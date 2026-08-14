<?php
/**
 * Tourfecto - Workspace Controller
 * إعدادات الـ Workspace وإدارة الفريق (Settings Center - Phase 8).
 *
 * ⚠️ ملاحظة نطاق مهمة: هذا الكونترولر بيدير مين عضو في الـ Workspace
 * وبأي دور - لكنه لا يفرض عزل بيانات (Tenant Isolation) على موديولات
 * تانية زي CRM/المواقع/التقارير. عضو فريق دلوقتي بيقدر يسجّل دخول
 * ويشوف Workspace Settings بتاعه، لكن لسه محتاج تعديلات في كل موديول
 * يشوف بيانات صاحب الحساب فعليًا. راجع CHANGELOG.md قسم Phase 8
 * لتفاصيل كاملة وقائمة الملفات المحتاجة تعديل لاحقًا.
 *
 * @version 1.0.0
 */
class WorkspaceController extends Controller {

    private function currentUser(): ?User {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    /** صاحب الـ Workspace الفعلي (نفس المستخدم لو مالك، أو المالك الحقيقي لو عضو) */
    private function workspaceOwner(User $user): ?User {
        $ownerId = $user->getAttribute('owner_user_id');
        if ($ownerId === null) {
            return $user;
        }
        return (new User())->find((int) $ownerId);
    }

    /** GET /api/workspace */
    public function getWorkspace(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $owner = $this->workspaceOwner($user);
        if (!$owner) {
            return $this->error('تعذر تحديد الـ Workspace', 500);
        }

        $memberModel = new User();
        $memberCount = count($memberModel->where(['owner_user_id' => (int) $owner->getAttribute('id')], [], 0)) + 1; // +1 للمالك نفسه

        return $this->success([
            'workspace' => [
                'name' => $owner->getAttribute('company_name'),
                'logo_url' => $owner->getAttribute('workspace_logo_url'),
                'industry' => $owner->getAttribute('industry'),
                'country_code' => $owner->getAttribute('country_code'),
                'timezone' => $owner->getAttribute('timezone'),
                'default_language' => $owner->getAttribute('language'),
                'member_count' => $memberCount,
            ],
            'is_owner' => $user->getAttribute('owner_user_id') === null,
            'my_role' => $user->getAttribute('owner_user_id') === null ? 'owner' : $user->getAttribute('workspace_role'),
            'can_manage_workspace' => WorkspacePermissions::can($user, 'manage_workspace'),
            'can_manage_team' => WorkspacePermissions::can($user, 'manage_team'),
        ]);
    }

    /** PUT /api/workspace */
    public function updateWorkspace(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_workspace')) {
            return $this->error('مفيش صلاحية لتعديل إعدادات الـ Workspace', 403);
        }

        $owner = $this->workspaceOwner($user);
        if (!$owner) {
            return $this->error('تعذر تحديد الـ Workspace', 500);
        }

        if (!$this->validate([
            'name' => 'max:150',
            'industry' => 'max:100',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if ($this->has('name')) {
            $owner->setAttribute('company_name', trim(strip_tags((string) $this->get('name'))) ?: null);
        }
        if ($this->has('industry')) {
            $owner->setAttribute('industry', trim(strip_tags((string) $this->get('industry'))) ?: null);
        }
        if ($this->has('country_code')) {
            $owner->setAttribute('country_code', (string) $this->get('country_code'));
        }
        if ($this->has('timezone')) {
            $owner->setAttribute('timezone', (string) $this->get('timezone'));
        }
        if ($this->has('default_language')) {
            $owner->setAttribute('language', (string) $this->get('default_language'));
        }

        if ($owner->save() === false) {
            return $this->error('تعذر حفظ إعدادات الـ Workspace', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'workspace_updated');

        return $this->success([], 'تم حفظ إعدادات الـ Workspace');
    }

    /** POST /api/workspace/logo */
    public function uploadLogo(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_workspace')) {
            return $this->error('مفيش صلاحية لتعديل إعدادات الـ Workspace', 403);
        }

        $owner = $this->workspaceOwner($user);
        if (!$owner || empty($_FILES['logo'])) {
            return $this->error('لم يتم رفع أي ملف', 422);
        }

        try {
            $handler = new AvatarUploadHandler();
            $result = $handler->upload($_FILES['logo'], (int) $owner->getAttribute('id'), $owner->getAttribute('workspace_logo_url'));
        } catch (\Throwable $e) {
            return $this->error('تعذر رفع اللوجو', 422);
        }

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر رفع اللوجو', 422);
        }

        $owner->setAttribute('workspace_logo_url', $result['url']);
        if ($owner->save() === false) {
            return $this->error('تعذر حفظ اللوجو', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'workspace_logo_updated');

        return $this->success(['logo_url' => $result['url']], 'تم تحديث اللوجو');
    }

    /** GET /api/workspace/members */
    public function listMembers(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $owner = $this->workspaceOwner($user);
        if (!$owner) {
            return $this->error('تعذر تحديد الـ Workspace', 500);
        }

        $memberModel = new User();
        $members = $memberModel->where(['owner_user_id' => (int) $owner->getAttribute('id')]);

        $rows = [[
            'id' => (int) $owner->getAttribute('id'),
            'name' => trim(($owner->getAttribute('first_name') ?? '') . ' ' . ($owner->getAttribute('last_name') ?? '')) ?: $owner->getAttribute('company_name'),
            'email' => $owner->getAttribute('email'),
            'role' => 'owner',
            'status' => $owner->getAttribute('status'),
            'is_self' => (int) $owner->getAttribute('id') === (int) $user->getAttribute('id'),
        ]];

        foreach ($members as $member) {
            $rows[] = [
                'id' => (int) $member->getAttribute('id'),
                'name' => trim(($member->getAttribute('first_name') ?? '') . ' ' . ($member->getAttribute('last_name') ?? '')) ?: $member->getAttribute('email'),
                'email' => $member->getAttribute('email'),
                'role' => $member->getAttribute('workspace_role'),
                'status' => $member->getAttribute('status'),
                'is_self' => (int) $member->getAttribute('id') === (int) $user->getAttribute('id'),
            ];
        }

        return $this->success(['members' => $rows]);
    }

    /** POST /api/workspace/invite */
    public function inviteMember(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_team')) {
            return $this->error('مفيش صلاحية لدعوة أعضاء', 403);
        }

        if (!$this->validate([
            'email' => 'required|email',
            'role' => 'required|in:' . implode(',', WorkspacePermissions::roles()),
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $owner = $this->workspaceOwner($user);
        if (!$owner) {
            return $this->error('تعذر تحديد الـ Workspace', 500);
        }

        $email = strtolower(trim((string) $this->get('email')));
        $role = (string) $this->get('role');

        if ($user->getAttribute('owner_user_id') !== null && $role === 'admin' && $user->getAttribute('workspace_role') !== 'admin') {
            return $this->error('مش مسموح تدعو عضو بصلاحيات أعلى من صلاحياتك', 403);
        }

        $existingUser = (new User())->where(['email' => $email], [], 1);
        if (!empty($existingUser)) {
            return $this->error('البريد الإلكتروني ده مستخدم بالفعل في حساب موجود', 409);
        }

        $result = WorkspaceInvite::createFor((int) $owner->getAttribute('id'), (int) $user->getAttribute('id'), $email, $role);
        $inviteUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/workspace/accept-invite?token=' . $result['token'];

        $mailSent = false;
        try {
            $mailer = new Mailer();
            if ($mailer->isConfigured()) {
                $ownerName = $owner->getAttribute('company_name') ?: 'Tourfecto';
                $mailer->send(
                    $email,
                    $email,
                    "دعوة للانضمام لفريق {$ownerName} على Tourfecto",
                    "تمت دعوتك للانضمام لفريق <strong>" . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . "</strong> على Tourfecto.<br><br><a href=\"{$inviteUrl}\">اضغط هنا لقبول الدعوة</a><br><br>الدعوة صالحة لمدة 7 أيام."
                );
                $mailSent = true;
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Workspace invite email failed: ' . $e->getMessage());
            }
        }

        AuditLog::record((int) $user->getAttribute('id'), 'team_member_invited', 'success', 'invite', (string) $result['model']->getAttribute('id'));

        return $this->success([
            'invite' => $result['model']->toSafeArray(),
            'invite_url' => $inviteUrl,
            'email_sent' => $mailSent,
        ], $mailSent ? 'تم إرسال الدعوة بالبريد الإلكتروني' : 'تم إنشاء الدعوة - انسخ الرابط وابعته يدويًا (البريد الإلكتروني غير مُفعّل حاليًا على السيرفر)');
    }

    /** GET /api/workspace/invites */
    public function listInvites(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_team')) {
            return $this->error('مفيش صلاحية', 403);
        }

        $owner = $this->workspaceOwner($user);
        $invites = (new WorkspaceInvite())->where(['owner_user_id' => (int) $owner->getAttribute('id'), 'status' => 'pending'], [], 0);

        return $this->success(['invites' => array_map(fn($i) => $i->toSafeArray(), $invites)]);
    }

    /** POST /api/workspace/invites/{id}/revoke */
    public function revokeInvite(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_team')) {
            return $this->error('مفيش صلاحية', 403);
        }

        $owner = $this->workspaceOwner($user);
        $invite = (new WorkspaceInvite())->find((int) ($params['id'] ?? 0));

        if (!$invite || (int) $invite->getAttribute('owner_user_id') !== (int) $owner->getAttribute('id')) {
            return $this->error('الدعوة غير موجودة', 404);
        }

        $invite->revoke();
        AuditLog::record((int) $user->getAttribute('id'), 'team_invite_revoked', 'success', 'invite', (string) $invite->getAttribute('id'));

        return $this->success([], 'تم إلغاء الدعوة');
    }

    /** GET /api/workspace/invite/{token} - عام (بدون تسجيل دخول) */
    public function showInvite(array $params = []): array {
        $token = (string) ($params['token'] ?? '');
        $invite = (new WorkspaceInvite())->where(['token' => $token], [], 1);
        $invite = $invite[0] ?? null;

        if (!$invite || $invite->getAttribute('status') !== 'pending' || $invite->isExpired()) {
            return $this->error('الدعوة غير صالحة أو منتهية', 404);
        }

        $owner = (new User())->find((int) $invite->getAttribute('owner_user_id'));

        return $this->success([
            'email' => $invite->getAttribute('email'),
            'role' => $invite->getAttribute('role'),
            'workspace_name' => $owner ? $owner->getAttribute('company_name') : null,
        ]);
    }

    /** POST /api/workspace/invite/{token}/accept - عام (بدون تسجيل دخول) */
    public function acceptInvite(array $params = []): array {
        $token = (string) ($params['token'] ?? '');
        $invite = (new WorkspaceInvite())->where(['token' => $token], [], 1);
        $invite = $invite[0] ?? null;

        if (!$invite || $invite->getAttribute('status') !== 'pending' || $invite->isExpired()) {
            return $this->error('الدعوة غير صالحة أو منتهية', 404);
        }

        if (!$this->validate([
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'password' => 'required|min:8',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $email = (string) $invite->getAttribute('email');
        $existing = (new User())->where(['email' => $email], [], 1);
        if (!empty($existing)) {
            return $this->error('البريد الإلكتروني ده بقى مستخدم بالفعل - جرّب تسجّل الدخول', 409);
        }

        $newUser = User::create([
            'email' => $email,
            'password' => (string) $this->get('password'),
            'first_name' => trim((string) $this->get('first_name')),
            'last_name' => trim((string) $this->get('last_name')),
            'owner_user_id' => (int) $invite->getAttribute('owner_user_id'),
            'workspace_role' => $invite->getAttribute('role'),
            'role' => 'user',
            'status' => 'active',
        ]);

        if (!$newUser) {
            return $this->error('تعذر إنشاء الحساب', 500);
        }

        $invite->markAccepted();
        AuditLog::record((int) $invite->getAttribute('owner_user_id'), 'team_member_joined', 'success', 'user', (string) $newUser->getAttribute('id'));

        $_SESSION['user_id'] = (int) $newUser->getAttribute('id');
        $_SESSION['user'] = $newUser->toArray();

        return $this->success(['user' => $newUser->toArray()], 'تم قبول الدعوة والانضمام للفريق');
    }

    /** PUT /api/workspace/members/{id}/role */
    public function changeRole(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_team')) {
            return $this->error('مفيش صلاحية', 403);
        }

        $owner = $this->workspaceOwner($user);
        $targetId = (int) ($params['id'] ?? 0);

        if ($targetId === (int) $user->getAttribute('id')) {
            return $this->error('مش مسموح تغيّر صلاحياتك أنت بنفسك', 403);
        }
        if ($targetId === (int) $owner->getAttribute('id')) {
            return $this->error('مش ممكن تغيّر دور صاحب الـ Workspace', 403);
        }

        if (!$this->validate(['role' => 'required|in:' . implode(',', WorkspacePermissions::roles())])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $target = (new User())->find($targetId);
        if (!$target || (int) $target->getAttribute('owner_user_id') !== (int) $owner->getAttribute('id')) {
            return $this->error('العضو غير موجود', 404);
        }

        $newRole = (string) $this->get('role');
        if ($newRole === 'admin' && $user->getAttribute('owner_user_id') !== null && $user->getAttribute('workspace_role') !== 'admin') {
            return $this->error('مش مسموح تدي صلاحيات أعلى من صلاحياتك', 403);
        }

        $oldRole = $target->getAttribute('workspace_role');
        $target->setAttribute('workspace_role', $newRole);
        if ($target->save() === false) {
            return $this->error('تعذر تغيير الدور', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'team_member_role_changed', 'success', 'user', (string) $targetId, ['from' => $oldRole, 'to' => $newRole]);

        return $this->success([], 'تم تغيير الدور');
    }

    /** POST /api/workspace/members/{id}/deactivate */
    public function deactivateMember(array $params = []): array {
        return $this->setMemberStatus($params, 'suspended', 'team_member_deactivated');
    }

    /** POST /api/workspace/members/{id}/reactivate */
    public function reactivateMember(array $params = []): array {
        return $this->setMemberStatus($params, 'active', 'team_member_reactivated');
    }

    private function setMemberStatus(array $params, string $status, string $auditAction): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_team')) {
            return $this->error('مفيش صلاحية', 403);
        }

        $owner = $this->workspaceOwner($user);
        $targetId = (int) ($params['id'] ?? 0);

        if ($targetId === (int) $owner->getAttribute('id')) {
            return $this->error('مش ممكن توقف صاحب الـ Workspace من هنا - استخدم Danger Zone', 403);
        }

        $target = (new User())->find($targetId);
        if (!$target || (int) $target->getAttribute('owner_user_id') !== (int) $owner->getAttribute('id')) {
            return $this->error('العضو غير موجود', 404);
        }

        $target->setAttribute('status', $status);
        if ($target->save() === false) {
            return $this->error('تعذر تحديث حالة العضو', 500);
        }

        if ($status === 'suspended') {
            RefreshToken::revokeAllForUser($targetId);
            foreach ((new UserApiKey())->where(['user_id' => $targetId]) as $key) {
                if (!$key->getAttribute('revoked_at')) {
                    $key->revoke();
                }
            }
        }

        AuditLog::record((int) $user->getAttribute('id'), $auditAction, 'success', 'user', (string) $targetId);

        return $this->success([], 'تم تحديث حالة العضو');
    }

    /** DELETE /api/workspace/members/{id} */
    public function removeMember(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if (!WorkspacePermissions::can($user, 'manage_team')) {
            return $this->error('مفيش صلاحية', 403);
        }

        $owner = $this->workspaceOwner($user);
        $targetId = (int) ($params['id'] ?? 0);

        if ($targetId === (int) $owner->getAttribute('id')) {
            return $this->error('مش ممكن تشيل صاحب الـ Workspace', 403);
        }

        $target = (new User())->find($targetId);
        if (!$target || (int) $target->getAttribute('owner_user_id') !== (int) $owner->getAttribute('id')) {
            return $this->error('العضو غير موجود', 404);
        }

        $target->setAttribute('status', 'suspended');
        $target->setAttribute('owner_user_id', null);
        $target->setAttribute('workspace_role', null);
        if ($target->save() === false) {
            return $this->error('تعذر إزالة العضو', 500);
        }

        RefreshToken::revokeAllForUser($targetId);
        foreach ((new UserApiKey())->where(['user_id' => $targetId]) as $key) {
            if (!$key->getAttribute('revoked_at')) {
                $key->revoke();
            }
        }

        AuditLog::record((int) $user->getAttribute('id'), 'team_member_removed', 'success', 'user', (string) $targetId);

        return $this->success([], 'تم إزالة العضو من الفريق');
    }

    /** POST /api/workspace/leave */
    public function leaveWorkspace(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($user->getAttribute('owner_user_id') === null) {
            return $this->error('صاحب الـ Workspace مايقدرش يسيبه - استخدم حذف/إيقاف الحساب من Danger Zone', 403);
        }

        if (!$this->validate(['current_password' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }
        if (!$user->verifyPassword((string) $this->get('current_password'))) {
            return $this->error('كلمة المرور غير صحيحة', 401, ['current_password' => ['كلمة المرور غير صحيحة']]);
        }

        $ownerIdForLog = (int) $user->getAttribute('owner_user_id');

        $user->setAttribute('owner_user_id', null);
        $user->setAttribute('workspace_role', null);
        $user->setAttribute('status', 'suspended');
        if ($user->save() === false) {
            return $this->error('تعذر ترك الـ Workspace', 500);
        }

        RefreshToken::revokeAllForUser((int) $user->getAttribute('id'));
        AuditLog::record($ownerIdForLog, 'team_member_left', 'success', 'user', (string) $user->getAttribute('id'));

        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['current_refresh_token_id']);

        return $this->success([], 'تم ترك الـ Workspace');
    }

    /** GET /workspace/accept-invite - صفحة بسيطة لقبول الدعوة (Public) */
    public function showAcceptInvitePage(array $params = []): array {
        $token = htmlspecialchars((string) ($_GET['token'] ?? ''), ENT_QUOTES, 'UTF-8');

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>قبول دعوة الانضمام - Tourfecto</title>
<style>
body { font-family: system-ui, sans-serif; background: #12121c; color: #eee; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
.card { background: #1b1b28; padding: 32px; border-radius: 14px; width: 100%; max-width: 400px; }
h1 { font-size: 18px; margin: 0 0 6px; }
p { color: #999; font-size: 13.5px; }
.form-group { margin: 14px 0; }
label { display: block; font-size: 13px; margin-bottom: 6px; }
input { width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid #333; background: #12121c; color: #eee; }
button { width: 100%; padding: 12px; border-radius: 8px; border: none; background: #efb05e; color: #12121c; font-weight: 700; cursor: pointer; margin-top: 8px; }
button:disabled { opacity: .6; cursor: default; }
.error { color: #e57373; font-size: 13px; min-height: 18px; }
.success { color: #81c784; }
</style>
</head>
<body>
<div class="card">
    <h1 id="title">جارِ التحقق من الدعوة...</h1>
    <p id="subtitle"></p>
    <form id="acceptForm" style="display:none;">
        <div class="form-group">
            <label for="first_name">الاسم الأول</label>
            <input type="text" id="first_name" required>
        </div>
        <div class="form-group">
            <label for="last_name">الاسم الأخير</label>
            <input type="text" id="last_name" required>
        </div>
        <div class="form-group">
            <label for="password">كلمة المرور</label>
            <input type="password" id="password" minlength="8" required>
        </div>
        <p class="error" id="formError"></p>
        <button type="submit" id="submitBtn">قبول الدعوة والانضمام</button>
    </form>
</div>
<script>
const token = "{$token}";
async function init() {
    if (!token) { document.getElementById('title').textContent = 'رابط غير صالح'; return; }
    const res = await fetch('/api/workspace/invite/' + encodeURIComponent(token)).then(r => r.json());
    if (!res.success) {
        document.getElementById('title').textContent = 'الدعوة غير صالحة أو منتهية';
        document.getElementById('subtitle').textContent = 'اطلب من صاحب الفريق يبعتلك دعوة جديدة.';
        return;
    }
    document.getElementById('title').textContent = 'دعوة للانضمام لفريق ' + (res.data.workspace_name || '');
    document.getElementById('subtitle').textContent = res.data.email + ' — ' + res.data.role;
    document.getElementById('acceptForm').style.display = 'block';
}
document.getElementById('acceptForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const err = document.getElementById('formError');
    err.textContent = '';
    btn.disabled = true;
    const res = await fetch('/api/workspace/invite/' + encodeURIComponent(token) + '/accept', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            first_name: document.getElementById('first_name').value,
            last_name: document.getElementById('last_name').value,
            password: document.getElementById('password').value,
        }),
    }).then(r => r.json());
    btn.disabled = false;
    if (res.success) {
        document.getElementById('title').textContent = 'تم الانضمام بنجاح ✅';
        document.getElementById('title').className = 'success';
        document.getElementById('acceptForm').style.display = 'none';
        setTimeout(() => window.location.href = '/dashboard', 1200);
    } else {
        err.textContent = res.error || 'حصل خطأ، حاول تاني';
    }
});
init();
</script>
</body>
</html>
HTML;
        exit;
    }
}
