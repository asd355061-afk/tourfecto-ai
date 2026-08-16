<?php

/**
 * Tourfecto - User Controller
 * الملف الشخصي وإعدادات المستخدم
 * @version 1.0.0
 *
 * ملاحظة: كان هذا الملف مفقودًا بالكامل. تمت إعادة بنائه اعتمادًا على User Model
 * الموجود فعليًا في app/Models/User.php.
 */

class UserController extends Controller
{
    /**
     * الحصول على المستخدم الحالي من الجلسة، ويُرجع null إن لم يكن مسجل دخول
     */
    private function currentUser(): ?User
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    /**
     * هل الطلب الحالي API (JSON) ولا صفحة ويب عادية؟
     */
    private function isApiRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return strpos($path, '/api/') === 0;
    }

    /** GET /profile و GET /api/user/profile */
    public function showProfile(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success(['user' => $user->toArray()]);
        }

        // توحيد: /profile كانت صفحة منفصلة بنفس بيانات تبويب "الملف الشخصي"
        // في /profile/settings بالظبط (نفس الحقول: الاسم، الشركة، الهاتف)
        // - الفرق إن /profile/settings أشمل (فيها الصورة والأمان والإشعارات
        // وAPI كمان في نفس الصفحة). بدل ما نصون نسخة مكررة، نوجّه هنا.
        header('Location: /profile/settings');
        exit;
    }

    private function renderProfilePage(User $user): void
    {
        $data = $user->toArray();
        $firstName = htmlspecialchars((string) ($data['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars((string) ($data['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $companyName = htmlspecialchars((string) ($data['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars((string) ($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $countryCode = htmlspecialchars((string) ($data['country_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $role = htmlspecialchars((string) ($data['role'] ?? 'user'), ENT_QUOTES, 'UTF-8');
        $memberSince = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : '-';

        $body = <<<HTML
        <div class="p-grid cols-2">
            <div class="p-card">
                <div class="p-card-head"><h3>معلومات الحساب</h3></div>
                <div class="p-kv"><span class="k">البريد الإلكتروني</span><span class="v">{$email}</span></div>
                <div class="p-kv"><span class="k">الدور</span><span class="v">{$role}</span></div>
                <div class="p-kv"><span class="k">عضو منذ</span><span class="v">{$memberSince}</span></div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>تعديل البيانات</h3></div>
                <form id="profileForm">
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="first_name">الاسم الأول</label>
                            <input type="text" id="first_name" class="form-control" value="{$firstName}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="last_name">اسم العائلة</label>
                            <input type="text" id="last_name" class="form-control" value="{$lastName}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="company_name">اسم الشركة</label>
                        <input type="text" id="company_name" class="form-control" value="{$companyName}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="phone">رقم الهاتف</label>
                        <input type="text" id="phone" class="form-control" value="{$phone}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="country_code">كود الدولة</label>
                        <input type="text" id="country_code" class="form-control" value="{$countryCode}" placeholder="مثال: EG">
                    </div>
                    <div id="profileAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary">حفظ التعديلات</button>
                </form>
            </div>
        </div>
        <div class="p-grid cols-3" style="margin-top:16px;">
            <a href="/profile/settings" class="p-card quick-tile"><span class="ic">⚙️</span>الإعدادات (اللغة والتوقيت)</a>
            <a href="/profile/security" class="p-card quick-tile"><span class="ic">🔒</span>الأمان (كلمة المرور)</a>
            <a href="/profile/api" class="p-card quick-tile"><span class="ic">🔑</span>مفتاح الـ API</a>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const fetchJSON = P.fetchJSON, toast = P.toast;

    document.getElementById('profileForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('profileAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/user/profile', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: document.getElementById('first_name').value.trim(),
                last_name: document.getElementById('last_name').value.trim(),
                company_name: document.getElementById('company_name').value.trim(),
                phone: document.getElementById('phone').value.trim(),
                country_code: document.getElementById('country_code').value.trim(),
            }),
        });

        if (res.success) {
            toast('تم حفظ التعديلات', 'success');
        } else {
            alertBox.textContent = res.error || 'تعذر الحفظ';
            alertBox.style.display = 'block';
        }
    });
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_profile', 'الملف الشخصي', 'بيانات حسابك', $body, $script);
    }

    public function profile(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success(['user' => $user->toArray()]);
    }

    /** GET /profile/edit -> نفس صفحة /profile (الفورم متضمّن فيها بالفعل) */
    public function showEditProfile(array $params = []): array
    {
        if ($this->isApiRequest()) {
            return $this->profile($params);
        }
        header('Location: /profile');
        exit;
    }

    /** POST /profile/update و PUT /api/user/profile */
    public function updateProfile(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        // Server-side validation (لا نعتمد على Client Validation وحدها -
        // كل حقل عنده حد أقصى مطابق للـ maxlength في الفورم ومطابق لطول
        // العمود في قاعدة البيانات، عشان مانرميش SQL truncation error).
        if (!$this->validate([
            'first_name' => 'max:100',
            'last_name' => 'max:100',
            'display_name' => 'max:120',
            'company_name' => 'max:150',
            'job_title' => 'max:120',
            'bio' => 'max:500',
            'phone' => 'max:20',
            'country_code' => 'max:5',
            'currency' => 'max:3',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        // Profile Center Phase 1: country_code و currency (لو مبعوتين
        // ومش فاضيين) لازم يكونوا فعليًا داخل قوائم ISO 3166-1/4217
        // معروفة - الفورم بقى select مقفول بالقيم دي، لكن الـbackend هو
        // مصدر الحقيقة الحقيقي مش الفرونت إند بس.
        if ($this->has('country_code') && $this->get('country_code') !== '') {
            $code = strtoupper((string) $this->get('country_code'));
            if (!array_key_exists($code, self::isoCountries())) {
                return $this->error('كود الدولة غير صحيح', 422, ['country_code' => ['قيمة غير معروفة في ISO 3166-1']]);
            }
        }

        if ($this->has('currency') && $this->get('currency') !== '') {
            $currency = strtoupper((string) $this->get('currency'));
            if (!array_key_exists($currency, self::isoCurrencies())) {
                return $this->error('كود العملة غير صحيح', 422, ['currency' => ['قيمة غير معروفة في ISO 4217 - ملحوظة: دي عملة عرض الملف الشخصي فقط، مش عملة الفوترة']]);
            }
        }

        // تصحيح: 'country' مش موجود في fillable الخاصة بـ User (الاسم
        // الحقيقي 'country_code')، فكان setAttribute('country', ...)
        // بيتجاهل التحديث بصمت من غير أي خطأ ظاهر للمستخدم.
        foreach (['company_name', 'phone', 'country_code', 'language', 'timezone', 'first_name', 'last_name', 'display_name', 'job_title', 'currency', 'bio'] as $field) {
            if ($this->has($field)) {
                $value = $this->get($field);
                // تنظيف بسيط: امسح أي وسوم HTML من الحقول النصية الحرة
                // (bio تحديدًا) عشان نمنع تخزين/عرض محتوى غير موثوق.
                if (is_string($value)) {
                    $value = trim(strip_tags($value));
                }
                if (in_array($field, ['country_code', 'currency'], true) && is_string($value) && $value !== '') {
                    $value = strtoupper($value);
                }
                $user->setAttribute($field, $value === '' ? null : $value);
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث البيانات', 500);
        }

        $_SESSION['user'] = $user->toArray();

        AuditLog::record((int) $user->getAttribute('id'), 'profile_updated');

        return $this->success(['user' => $user->toArray()], 'تم تحديث الملف الشخصي');
    }

    /** PUT /api/user/password */
    public function updatePassword(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$this->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('current_password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'password_change_failed', 'failed');
            return $this->error('كلمة المرور الحالية غير صحيحة', 401, ['current_password' => ['كلمة المرور الحالية غير صحيحة']]);
        }

        if (!$user->updatePassword((string) $this->get('new_password'))) {
            return $this->error('تعذر تحديث كلمة المرور', 500);
        }

        // أمان: بعد تغيير كلمة المرور بنسجّل خروج من كل الأجهزة التانية
        // فورًا (نحتفظ بالجهاز الحالي بس) - نفس سلوك GitHub/Stripe. أي
        // جلسة مسروقة/قديمة بكلمة مرور قديمة متبقاش صالحة. الاستثناء:
        // لو مفيش refresh token حالي معروف (جلسات قديمة بدون JWT) بنخلي
        // الوظيفة تسجّل خروج من كل الأجهزة عشان أقوى أمان.
        $currentRefreshTokenId = $_SESSION['current_refresh_token_id'] ?? null;
        RefreshToken::revokeAllForUserExcept((int) $user->getAttribute('id'), $currentRefreshTokenId);

        AuditLog::record((int) $user->getAttribute('id'), 'password_changed', 'success', null, null, ['other_sessions_revoked' => true]);

        return $this->success([], 'تم تحديث كلمة المرور بنجاح - تم تسجيل الخروج من جميع الأجهزة الأخرى');
    }

    /** GET /profile/settings و GET /api/user/settings */
    public function showSettings(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile/settings'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->getSettings($params);
        }

        $this->renderSettingsPage($user);
        exit;
    }

    private function renderSettingsPage(User $user): void
    {
        $data = $user->toArray();

        // Connected Accounts (Profile Center Phase 2): بيانات حقيقية من
        // oauth_accounts، مفيش أي Access Token أو Refresh Token أو
        // Client Secret بيتعرض - provider_user_id نفسه كمان متعرضش
        // (معرّف خارجي حساس).
        $connectedProviders = [];
        try {
            $rows = $this->db->query(
                'SELECT provider, email, created_at FROM oauth_accounts WHERE user_id = ?',
                [(int) $data['id']]
            );
            foreach ((array) $rows as $row) {
                $connectedProviders[$row['provider']] = $row;
            }
        } catch (\Throwable $e) {
            $connectedProviders = [];
        }

        $providerLabels = ['google' => 'Google', 'facebook' => 'Facebook', 'microsoft' => 'Microsoft', 'apple' => 'Apple'];
        $providersWithConnectFlow = ['google', 'facebook', 'microsoft']; // apple عبر appleCallback (POST) - غير مربوط بـ ?link=1 حاليًا
        $connectedAccountsHtml = '';
        foreach ($providerLabels as $providerKey => $label) {
            $isConnected = isset($connectedProviders[$providerKey]);
            $connectedEmail = $isConnected ? htmlspecialchars((string) ($connectedProviders[$providerKey]['email'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
            $connectedAccountsHtml .= '<div class="p-row" style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #eee;">';
            $connectedAccountsHtml .= '<div><strong>' . $label . '</strong>';
            $connectedAccountsHtml .= $isConnected
                ? '<div class="p-cell-muted" style="font-size:12.5px;">' . $this->tr('settings.connected_as') . ' ' . $connectedEmail . '</div>'
                : '<div class="p-cell-muted" style="font-size:12.5px;">' . $this->tr('settings.not_connected') . '</div>';
            $connectedAccountsHtml .= '</div>';
            if ($isConnected) {
                $connectedAccountsHtml .= '<button type="button" class="p-btn outline" onclick="disconnectOAuth(\'' . $providerKey . '\')">' . $this->tr('settings.disconnect') . '</button>';
            } elseif (in_array($providerKey, $providersWithConnectFlow, true)) {
                $connectedAccountsHtml .= '<a class="p-btn outline" href="/auth/' . $providerKey . '?link=1">' . $this->tr('settings.connect') . '</a>';
            } else {
                $connectedAccountsHtml .= '<span class="p-cell-muted" style="font-size:12.5px;">' . $this->tr('settings.not_available') . '</span>';
            }
            $connectedAccountsHtml .= '</div>';
        }

        // Role & Permissions (Read-only) - النظام مفهوش جدول Permissions
        // منفصل، لكن فيه نظام Feature Flags حقيقي (feature_flags +
        // user_feature_overrides) بيتحكم فعليًا في ظهور صفحات اللوحة
        // الجانبية لكل مستخدم - أقرب حاجة حقيقية لـ"Permission Details".
        // العرض هنا Read-only بالكامل - مفيش تغيير Role من صفحة البروفايل.
        $roleLabels = [
            'super_admin' => $this->tr('settings.role_super_admin'),
            'admin' => $this->tr('settings.role_admin'),
            'manager' => $this->tr('settings.role_manager'),
            'agent' => $this->tr('settings.role_agent'),
            'user' => $this->tr('settings.role_user'),
        ];
        $currentRoleKey = (string) ($data['role'] ?? 'user');
        $currentRoleLabel = $roleLabels[$currentRoleKey] ?? $currentRoleKey;

        $permissionsHtml = '';
        try {
            $featureMap = (new FeatureFlagService())->getEnabledMap((int) $data['id']);
            ksort($featureMap);
            foreach ($featureMap as $featureKey => $isEnabled) {
                $statusIcon = $isEnabled
                    ? '<span style="color:#2e7d32;">✔</span>'
                    : '<span style="color:#c0392b;">✖</span>';
                $permissionsHtml .= '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0;">'
                    . '<span>' . htmlspecialchars($featureKey, ENT_QUOTES, 'UTF-8') . '</span>'
                    . '<span>' . $statusIcon . '</span></div>';
            }
        } catch (\Throwable $e) {
            $permissionsHtml = '<p class="p-cell-muted">' . $this->tr('settings.not_available') . '</p>';
        }
        if ($permissionsHtml === '') {
            $permissionsHtml = '<p class="p-cell-muted">' . $this->tr('settings.no_permissions_data') . '</p>';
        }

        // Login Activity (Read-only) - آخر 20 محاولة دخول للمستخدم الحالي
        // فقط (WHERE user_id = ?)، مفيش session_id ولا is_impersonation
        // ولا إحداثيات دقيقة (latitude/longitude) بتتعرض للمستخدم - دي
        // بيانات إدارية داخلية مش لازم تظهر في واجهة المستخدم نفسه.
        $loginActivityHtml = '';
        try {
            $history = $this->db->query(
                'SELECT status, ip_address, device_type, browser, platform, country, city, created_at
                 FROM login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
                [(int) $data['id']]
            );
        } catch (\Throwable $e) {
            $history = [];
        }

        if (empty($history)) {
            $loginActivityHtml = '<p class="p-cell-muted">' . $this->tr('settings.no_login_activity') . '</p>';
        } else {
            $tSuccess = $this->tr('settings.login_success');
            $tFailed = $this->tr('settings.login_failed');
            $alignSide = current_dir() === 'rtl' ? 'right' : 'left';
            $loginActivityHtml .= '<table class="p-table" style="width:100%;border-collapse:collapse;">';
            $loginActivityHtml .= '<thead><tr>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_date') . '</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_device') . '</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_location') . '</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">IP</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_status') . '</th>'
                . '</tr></thead><tbody>';
            foreach ((array) $history as $entry) {
                $when = !empty($entry['created_at']) ? date('Y-m-d H:i', strtotime($entry['created_at'])) : '-';
                $device = trim(htmlspecialchars((string) ($entry['browser'] ?? '-'), ENT_QUOTES, 'UTF-8')
                    . ' / ' . htmlspecialchars((string) ($entry['platform'] ?? '-'), ENT_QUOTES, 'UTF-8'));
                $location = trim(htmlspecialchars((string) ($entry['city'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ' ' . htmlspecialchars((string) ($entry['country'] ?? ''), ENT_QUOTES, 'UTF-8'));
                $location = $location !== '' ? $location : $this->tr('settings.not_available');
                $ip = htmlspecialchars((string) ($entry['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8');
                $isSuccess = ($entry['status'] ?? '') === 'success';
                $statusLabel = $isSuccess
                    ? '<span style="color:#2e7d32;">✔ ' . $tSuccess . '</span>'
                    : '<span style="color:#c0392b;">✖ ' . $tFailed . '</span>';
                $loginActivityHtml .= '<tr style="border-top:1px solid #eee;">'
                    . '<td style="padding:8px;white-space:nowrap;">' . $when . '</td>'
                    . '<td style="padding:8px;">' . $device . '</td>'
                    . '<td style="padding:8px;">' . $location . '</td>'
                    . '<td style="padding:8px;direction:ltr;text-align:' . $alignSide . ';">' . $ip . '</td>'
                    . '<td style="padding:8px;">' . $statusLabel . '</td>'
                    . '</tr>';
            }
            $loginActivityHtml .= '</tbody></table>';
        }

        $firstName = htmlspecialchars((string) ($data['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars((string) ($data['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $displayName = htmlspecialchars((string) ($data['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $companyName = htmlspecialchars((string) ($data['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $jobTitle = htmlspecialchars((string) ($data['job_title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $bio = htmlspecialchars((string) ($data['bio'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars((string) ($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $countryCode = htmlspecialchars((string) ($data['country_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $role = htmlspecialchars((string) ($data['role'] ?? 'user'), ENT_QUOTES, 'UTF-8');
        $accountId = htmlspecialchars((string) ($data['id'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $accountStatusRaw = preg_replace('/[^a-z_]/', '', strtolower((string) ($data['status'] ?? (($data['is_active'] ?? 1) ? 'active' : 'suspended')))) ?: 'active';
        $accountStatus = htmlspecialchars($accountStatusRaw, ENT_QUOTES, 'UTF-8');
        $memberSince = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : '-';
        $lastLogin = !empty($data['last_login']) ? date('Y-m-d H:i', strtotime($data['last_login'])) : $this->tr('settings.never_logged_in');
        $lastPasswordChange = !empty($data['password_changed_at']) ? date('Y-m-d', strtotime($data['password_changed_at'])) : $this->tr('settings.unknown');
        $avatarUrl = htmlspecialchars((string) ($data['avatar_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $initials = htmlspecialchars(mb_strtoupper(mb_substr($data['first_name'] ?? ($data['company_name'] ?? '؟'), 0, 1)), ENT_QUOTES, 'UTF-8');
        $language = htmlspecialchars((string) ($data['language'] ?: UI_DEFAULT_LANGUAGE), ENT_QUOTES, 'UTF-8');
        $timezone = htmlspecialchars((string) ($data['timezone'] ?: 'UTC'), ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars((string) ($data['api_token'] ?? ''), ENT_QUOTES, 'UTF-8');
        $countryCodeRaw = strtoupper((string) ($data['country_code'] ?? ''));
        $currencyRaw = strtoupper((string) ($data['currency'] ?? ''));

        // Global-first (Profile Center Phase 1): قائمة اللغات المعروضة =
        // اللغات اللي فعليًا عندها ملف ترجمة (UI_SUPPORTED_LANGUAGES)، مش
        // قيم ثابتة (ar/en بس زي الكود القديم) رغم وجود fr.php و de.php
        // فعليًا في app/Lang/.
        $langLabels = ['en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'de' => 'Deutsch'];
        $languageOptionsHtml = '';
        foreach (UI_SUPPORTED_LANGUAGES as $langCode) {
            $label = htmlspecialchars($langLabels[$langCode] ?? strtoupper($langCode), ENT_QUOTES, 'UTF-8');
            $languageOptionsHtml .= '<option value="' . $langCode . '"' . $this->selected($language, $langCode) . '>' . $label . '</option>';
        }

        // Global-first: كل IANA timezones الحقيقية من PHP نفسها (DateTimeZone)،
        // مجمّعة حسب المنطقة (Africa/, Asia/, Europe/...) بدل 4 خيارات ثابتة.
        $timezoneOptionsHtml = '<option value="UTC"' . $this->selected($timezone, 'UTC') . '>UTC</option>';
        $tzByRegion = [];
        foreach (self::ianaTimezones() as $tz) {
            $parts = explode('/', $tz, 2);
            $region = count($parts) > 1 ? $parts[0] : 'Other';
            $tzByRegion[$region][] = $tz;
        }
        ksort($tzByRegion);
        foreach ($tzByRegion as $region => $zones) {
            $timezoneOptionsHtml .= '<optgroup label="' . htmlspecialchars($region, ENT_QUOTES, 'UTF-8') . '">';
            foreach ($zones as $tz) {
                $timezoneOptionsHtml .= '<option value="' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '"' . $this->selected($timezone, $tz) . '>' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            $timezoneOptionsHtml .= '</optgroup>';
        }

        // Global-first: قائمة دول ISO 3166-1 حقيقية بدل مربع نص حر
        $countryOptionsHtml = '<option value="">-</option>';
        foreach (self::isoCountries() as $code => $name) {
            $countryOptionsHtml .= '<option value="' . $code . '"' . $this->selected($countryCodeRaw, $code) . '>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' (' . $code . ')</option>';
        }

        // Currency Preference (Profile) - قائمة ISO 4217، مش عملة الفوترة
        $currencyOptionsHtml = '<option value="">-</option>';
        foreach (self::isoCurrencies() as $code => $name) {
            $currencyOptionsHtml .= '<option value="' . $code . '"' . $this->selected($currencyRaw, $code) . '>' . $code . ' - ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        // نصوص الصفحة كلها عن طريق نظام الترجمة
        $tTabProfile = $this->tr('settings.tab.profile');
        $tTabSecurity = $this->tr('settings.tab.security');
        $tTabNotifications = $this->tr('settings.tab.notifications');
        $tTabApi = $this->tr('settings.tab.api');
        $tTabIntegrations = $this->tr('settings.tab.integrations');
        $tTabsAriaLabel = $this->tr('settings.tabs_aria_label');
        $tIntegrationsTitle = $this->tr('settings.integrations_title');
        $tIntegrationsDesc = $this->tr('settings.integrations_desc');
        $tIntegrationsListHint = $this->tr('settings.integrations_list_hint');
        $tIntegrationsManageBtn = $this->tr('settings.integrations_manage_btn');
        $tTabBilling = $this->tr('settings.tab.billing');
        $tTabAudit = $this->tr('settings.tab.audit');
        $tTabWorkspace = $this->tr('settings.tab.workspace');
        $tTabTeam = $this->tr('settings.tab.team');
        $tTabGeneral = $this->tr('settings.tab.general');
        $tTabConnected = $this->tr('settings.tab.connected');
        $tTabActivity = $this->tr('settings.tab.activity');
        $tTabPermissions = $this->tr('settings.tab.permissions');
        $tConnectedTitle = $this->tr('settings.tab.connected');
        $tActivityTitle = $this->tr('settings.tab.activity');
        $tPermissionsTitle = $this->tr('settings.tab.permissions');
        $tCurrentRoleLabel = $this->tr('settings.current_role');
        $tFeatureAccessLabel = $this->tr('settings.feature_access');
        $tFeatureAccessDesc = $this->tr('settings.feature_access_desc');
        $tPrivacyTitle = $this->tr('settings.privacy_title');
        $tPrivacyDesc = $this->tr('settings.privacy_desc');
        $tViewPrivacyPolicy = $this->tr('settings.view_privacy_policy');
        $tDataExportTitle = $this->tr('settings.data_export_title');
        $tDataExportDesc = $this->tr('settings.data_export_desc');
        $tRequestExport = $this->tr('settings.request_export');
        $tAvatarTitle = $this->tr('settings.avatar.title');
        $tChangePhoto = $this->tr('settings.avatar.change');
        $tAvatarHint = $this->tr('settings.avatar.hint');
        $tAccountInfo = $this->tr('settings.account_info');
        $tEmail = $this->tr('settings.email');
        $tRole = $this->tr('settings.role');
        $tAccountId = $this->tr('settings.account_id');
        $tAccountStatus = $this->tr('settings.account_status');
        $tLastLogin = $this->tr('settings.last_login');
        $tMemberSince = $this->tr('settings.member_since');
        $tEditData = $this->tr('settings.edit_data');
        $tFirstName = $this->tr('settings.first_name');
        $tLastName = $this->tr('settings.last_name');
        $tDisplayName = $this->tr('settings.display_name');
        $tDisplayNameHint = $this->tr('settings.display_name_hint');
        $tCompanyName = $this->tr('settings.company_name');
        $tJobTitle = $this->tr('settings.job_title');
        $tBio = $this->tr('settings.bio');
        $tBioHint = $this->tr('settings.bio_hint');
        $tPhone = $this->tr('settings.phone');
        $tCountryCode = $this->tr('settings.country_code');
        $tCurrency = $this->tr('settings.currency');
        $tSaveChanges = $this->tr('settings.save_changes');
        $tCancel = $this->tr('settings.cancel');
        $tSaving = $this->tr('settings.saving');
        $tChangePassword = $this->tr('settings.change_password');
        $tCurrentPassword = $this->tr('settings.current_password');
        $tNewPassword = $this->tr('settings.new_password');
        $tUpdatePassword = $this->tr('settings.update_password');
        $tPasswordStatus = $this->tr('settings.password_status');
        $tPasswordSet = $this->tr('settings.password_set');
        $tLastPasswordChange = $this->tr('settings.last_password_change');
        $t2FATitle = $this->tr('settings.2fa_title');
        $t2FANotConfigured = $this->tr('settings.2fa_not_configured');
        $tfaEnabled = (bool) ($data['two_factor_enabled'] ?? false);
        $tfaDisabledDisplay = $tfaEnabled ? 'none' : 'block';
        $tfaEnabledDisplay = $tfaEnabled ? 'block' : 'none';
        $tTwoFactorDesc = $this->tr('settings.tfa_desc');
        $tEnableTwoFactor = $this->tr('settings.tfa_enable');
        $tDisableTwoFactor = $this->tr('settings.tfa_disable');
        $tTwoFactorSetupHint = $this->tr('settings.tfa_setup_hint');
        $tSetupKeyLabel = $this->tr('settings.tfa_setup_key');
        $tConfirmCodeLabel = $this->tr('settings.tfa_confirm_code');
        $tConfirmAndEnable = $this->tr('settings.tfa_confirm_enable');
        $tRecoveryCodesHint = $this->tr('settings.tfa_recovery_hint');
        $tSavedRecoveryCodes = $this->tr('settings.tfa_saved_codes');
        $tTwoFactorEnabledLabel = $this->tr('settings.tfa_enabled_label');
        $tConfirmPasswordFor2FA = $this->tr('settings.tfa_confirm_password');
        $tRegenerateRecoveryCodes = $this->tr('settings.regenerate_recovery_codes');
        $tRegenRecoveryHint = $this->tr('settings.recovery_codes_hint');
        $tRegenRecoveryConfirm = $this->trJs('settings.js.regenerate_recovery_confirm');
        $tRegenRecoveryDone = $this->trJs('settings.js.recovery_codes_regenerated');
        $tRegenRecoveryFailed = $this->trJs('settings.js.recovery_codes_failed');
        $tRecoveryNeedTotp = $this->trJs('settings.js.recovery_need_totp');
        $t2FANotConfiguredHint = $this->tr('settings.2fa_not_configured_hint');
        $tSessionsTitle = $this->tr('settings.sessions_title');
        $tSessionsHint = $this->tr('settings.sessions_hint');
        $tLogoutOthers = $this->tr('settings.logout_others');
        $tCurrentDevice = $this->trJs('settings.current_device');
        $tLogoutDevice = $this->trJs('settings.logout_device');
        $tSessionsEmpty = $this->trJs('settings.sessions_empty');
        $tSessionsLoading = $this->tr('settings.sessions_loading');
        $tNotifPrefs = $this->tr('settings.notif_prefs');
        $tNotifReviewsCat = $this->tr('settings.notif_cat_reviews');
        $tNotifContentCat = $this->tr('settings.notif_cat_content');
        $tNotifLeadsCat = $this->tr('settings.notif_cat_leads');
        $tNotifSystemCat = $this->tr('settings.notif_cat_system');
        $tNotifUnavailableCats = $this->tr('settings.notif_unavailable_cats');
        $tNotifChannelNote = $this->tr('settings.notif_channel_note');
        $tSavePrefs = $this->tr('settings.save_prefs');
        $tApiKeyTitle = $this->tr('settings.api_key_title');
        $tApiKeyDesc = $this->tr('settings.api_key_desc');
        $tCopyKey = $this->tr('settings.copy_key');
        $tRegenerateKey = $this->tr('settings.regenerate_key');
        $tRegenerateWarning = $this->tr('settings.regenerate_warning');
        $tPersonalKeysTitle = $this->tr('settings.personal_keys_title');
        $tPersonalKeysDesc = $this->tr('settings.personal_keys_desc');
        $tKeyNamePlaceholder = $this->tr('settings.key_name_placeholder');
        $tCreateKey = $this->tr('settings.create_key');
        $tKeyExpiryDaysPlaceholder = $this->tr('settings.key_expiry_days_placeholder');
        $tKeyExpiryLabel = $this->tr('settings.key_expiry_label');
        $tKeyExpiresNever = $this->tr('settings.key_expires_never');
        $tKeysLoading = $this->tr('settings.keys_loading');
        $tKeysEmpty = $this->trJs('settings.keys_empty');
        $tRevoke = $this->trJs('settings.revoke');
        $tRevoked = $this->trJs('settings.revoked');
        $tNeverUsed = $this->trJs('settings.never_used');
        $tAccountPrefs = $this->tr('settings.account_prefs');
        $tBillingPlanTitle = $this->tr('settings.billing_plan_title');
        $tBillingLoading = $this->tr('settings.billing_loading');
        $tBillingNoPlan = $this->trJs('settings.billing_no_plan');
        $tBillingCancelPlan = $this->trJs('settings.billing_cancel_plan');
        $tBillingInvoicesTitle = $this->tr('settings.billing_invoices_title');
        $tBillingInvoicesEmpty = $this->trJs('settings.billing_invoices_empty');
        $tBillingWalletTitle = $this->tr('settings.billing_wallet_title');
        $tBillingWalletEmpty = $this->tr('settings.billing_wallet_empty');
        $tAuditTitle = $this->tr('settings.audit_title');
        $tAuditDesc = $this->tr('settings.audit_desc');
        $tAuditSearchPlaceholder = $this->tr('settings.audit_search_placeholder');
        $tAuditFrom = $this->tr('settings.audit_from');
        $tAuditTo = $this->tr('settings.audit_to');
        $tAuditFilterBtn = $this->tr('settings.audit_filter_btn');
        $tAuditColAction = $this->tr('settings.audit_col_action');
        $tAuditColObject = $this->tr('settings.audit_col_object');
        $tAuditColTime = $this->tr('settings.audit_col_time');
        $tAuditColResult = $this->tr('settings.audit_col_result');
        $tAuditAllResults = $this->tr('settings.audit_all_results');
        $tAuditResultSuccess = $this->tr('settings.audit_result_success');
        $tAuditResultFailed = $this->tr('settings.audit_result_failed');
        $tAuditActionPlaceholder = $this->tr('settings.audit_action_placeholder');
        $tAuditExportBtn = $this->tr('settings.audit_export_btn');
        $tAuditExportFailed = $this->trJs('settings.js.audit_export_failed');
        $tAuditEmpty = $this->trJs('settings.audit_empty');
        $tAuditPrev = $this->tr('settings.audit_prev');
        $tAuditNext = $this->tr('settings.audit_next');
        $tWorkspaceTitle = $this->tr('settings.workspace_title');
        $tWorkspaceScopeNote = $this->tr('settings.workspace_scope_note');
        $tWorkspaceName = $this->tr('settings.workspace_name');
        $tWorkspaceIndustry = $this->tr('settings.workspace_industry');
        $tWorkspaceCountry = $this->tr('settings.workspace_country');
        $tWorkspaceTimezone = $this->tr('settings.workspace_timezone');
        $tWorkspaceLanguage = $this->tr('settings.workspace_language');
        $tWorkspaceLogo = $this->tr('settings.workspace_logo');
        $tWorkspaceReadOnlyNote = $this->tr('settings.workspace_readonly_note');
        $tTeamTitle = $this->tr('settings.team_title');
        $tTeamPermissionNote = $this->tr('settings.team_permission_note');
        $tInviteEmail = $this->tr('settings.invite_email');
        $tInviteRole = $this->tr('settings.invite_role');
        $tInviteSend = $this->tr('settings.invite_send');
        $tPendingInvitesTitle = $this->tr('settings.pending_invites_title');
        $tMembersTitle = $this->tr('settings.members_title');
        $tPermissionMatrixTitle = $this->tr('settings.permission_matrix_title');
        $tInterfaceLang = $this->tr('settings.interface_lang');
        $tTimezone = $this->tr('settings.timezone');
        $tSaveSettings = $this->tr('settings.save_settings');
        $tDangerZone = $this->tr('settings.danger_zone');
        $tDeleteWarning = $this->tr('settings.delete_warning');
        $tDeleteAccount = $this->tr('settings.delete_account');
        $tDeactivateTitle = $this->tr('settings.deactivate_title');
        $tDeactivateWarning = $this->tr('settings.deactivate_warning');
        $tDeactivateAccount = $this->tr('settings.deactivate_account');
        $tConfirmPasswordLabel = $this->tr('settings.confirm_password_label');
        $tConfirmEmailLabel = $this->tr('settings.confirm_email_label');
        $tConfirmEmailHint = $this->tr('settings.confirm_email_hint');
        $tLeaveWorkspaceTitle = $this->tr('settings.leave_workspace_title');
        $tLeaveWorkspaceWarning = $this->tr('settings.leave_workspace_warning');
        $tLeaveWorkspaceBtn = $this->tr('settings.leave_workspace_btn');

        // Phase 12 (Scoped CSRF): التوكن اللي بيتبعت مع كل طلب JSON من
        // أي تاب في الصفحة - بيتبني فوق كائن Csrf الموجود بالفعل.
        $csrfToken = class_exists('Csrf') ? Csrf::token() : '';

        $body = <<<HTML
        <script>window.TF_CSRF_TOKEN = "{$csrfToken}";</script>
        <style>
            /* Phase 14: تحويل تابات الإعدادات لـ Dropdown على الموبايل.
               مقصود إنها مربوطة بـ #settingsTabs/#settingsTabsMobile
               بالتحديد، مش .p-tabs/.p-tab العامة - الكلاسات دي مستخدمة
               في صفحات تانية ومش عايزين نغيّر سلوكها الافتراضي هناك. */
            #settingsTabsMobile { display: none; }
            @media (max-width: 640px) {
                #settingsTabs { display: none; }
                #settingsTabsMobile { display: block; width: 100%; margin-bottom: 14px; }
            }
        </style>
        <div class="p-tabs" id="settingsTabs">
            <button class="p-tab active" data-section="profile">👤 {$tTabProfile}</button>
            <button class="p-tab" data-section="security">🔒 {$tTabSecurity}</button>
            <button class="p-tab" data-section="notifications">🔔 {$tTabNotifications}</button>
            <button class="p-tab" data-section="api">🔑 {$tTabApi}</button>
            <button class="p-tab" data-section="integrations">🔌 {$tTabIntegrations}</button>
            <button class="p-tab" data-section="billing">💳 {$tTabBilling}</button>
            <button class="p-tab" data-section="audit">📜 {$tTabAudit}</button>
            <button class="p-tab" data-section="workspace">🏢 {$tTabWorkspace}</button>
            <button class="p-tab" data-section="team">👥 {$tTabTeam}</button>
            <button class="p-tab" data-section="general">🌐 {$tTabGeneral}</button>
            <button class="p-tab" data-section="connected">🔗 {$tTabConnected}</button>
            <button class="p-tab" data-section="activity">📋 {$tTabActivity}</button>
            <button class="p-tab" data-section="permissions">🛡️ {$tTabPermissions}</button>
        </div>

        <select id="settingsTabsMobile" aria-label="{$tTabsAriaLabel}">
            <option value="profile">👤 {$tTabProfile}</option>
            <option value="security">🔒 {$tTabSecurity}</option>
            <option value="notifications">🔔 {$tTabNotifications}</option>
            <option value="api">🔑 {$tTabApi}</option>
            <option value="integrations">🔌 {$tTabIntegrations}</option>
            <option value="billing">💳 {$tTabBilling}</option>
            <option value="audit">📜 {$tTabAudit}</option>
            <option value="workspace">🏢 {$tTabWorkspace}</option>
            <option value="team">👥 {$tTabTeam}</option>
            <option value="general">🌐 {$tTabGeneral}</option>
            <option value="connected">🔗 {$tTabConnected}</option>
            <option value="activity">📋 {$tTabActivity}</option>
            <option value="permissions">🛡️ {$tTabPermissions}</option>
        </select>

        <!-- الملف الشخصي -->
        <div class="settings-section" id="section_profile">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tAvatarTitle}</h3></div>
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div id="avatarPreviewWrap" style="width:84px;height:84px;border-radius:50%;overflow:hidden;background:var(--panel-accent,#f59e0b);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:700;color:#fff;flex-shrink:0;">
                        {$this->avatarInnerHtml($avatarUrl, $initials)}
                    </div>
                    <div>
                        <input type="file" id="avatarInput" accept="image/png,image/jpeg,image/webp" style="display:none;">
                        <button type="button" class="p-btn outline xs" onclick="document.getElementById('avatarInput').click()">📷 {$tChangePhoto}</button>
                        <p class="p-cell-muted" style="font-size:12.5px;margin-top:6px;">{$tAvatarHint}</p>
                    </div>
                </div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tAccountInfo}</h3></div>
                <div class="p-kv"><span class="k">{$tEmail}</span><span class="v" style="direction:ltr;display:inline-block;">{$email}</span></div>
                <div class="p-kv"><span class="k">{$tAccountId}</span><span class="v" style="direction:ltr;display:inline-block;">#{$accountId}</span></div>
                <div class="p-kv"><span class="k">{$tRole}</span><span class="v">{$role}</span></div>
                <div class="p-kv"><span class="k">{$tAccountStatus}</span><span class="v"><span class="p-badge status-{$accountStatusRaw}">{$accountStatus}</span></span></div>
                <div class="p-kv"><span class="k">{$tMemberSince}</span><span class="v">{$memberSince}</span></div>
                <div class="p-kv"><span class="k">{$tLastLogin}</span><span class="v">{$lastLogin}</span></div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tEditData}</h3></div>
                <form id="profileForm" novalidate>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="first_name">{$tFirstName}</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" value="{$firstName}" maxlength="100" aria-describedby="err_first_name">
                            <p class="field-error" id="err_first_name" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="last_name">{$tLastName}</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" value="{$lastName}" maxlength="100" aria-describedby="err_last_name">
                            <p class="field-error" id="err_last_name" role="alert"></p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="display_name">{$tDisplayName}</label>
                        <input type="text" id="display_name" name="display_name" class="form-control" value="{$displayName}" maxlength="120" aria-describedby="err_display_name">
                        <p class="p-cell-muted" style="font-size:12.5px;margin-top:4px;">{$tDisplayNameHint}</p>
                        <p class="field-error" id="err_display_name" role="alert"></p>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="company_name">{$tCompanyName}</label>
                            <input type="text" id="company_name" name="company_name" class="form-control" value="{$companyName}" maxlength="150" aria-describedby="err_company_name">
                            <p class="field-error" id="err_company_name" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="job_title">{$tJobTitle}</label>
                            <input type="text" id="job_title" name="job_title" class="form-control" value="{$jobTitle}" maxlength="120" aria-describedby="err_job_title">
                            <p class="field-error" id="err_job_title" role="alert"></p>
                        </div>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="phone">{$tPhone}</label>
                            <input type="text" id="phone" name="phone" class="form-control" value="{$phone}" maxlength="20" aria-describedby="err_phone">
                            <p class="field-error" id="err_phone" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="country_code">{$tCountryCode}</label>
                            <select id="country_code" name="country_code" class="form-control" aria-describedby="err_country_code">
                                {$countryOptionsHtml}
                            </select>
                            <p class="field-error" id="err_country_code" role="alert"></p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="currency">{$tCurrency}</label>
                        <select id="currency" name="currency" class="form-control" aria-describedby="err_currency">
                            {$currencyOptionsHtml}
                        </select>
                        <p class="field-error" id="err_currency" role="alert"></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bio">{$tBio}</label>
                        <textarea id="bio" name="bio" class="form-control" rows="3" maxlength="500" aria-describedby="err_bio">{$bio}</textarea>
                        <p class="p-cell-muted" style="font-size:12.5px;margin-top:4px;"><span id="bioCount">0</span>/500 — {$tBioHint}</p>
                        <p class="field-error" id="err_bio" role="alert"></p>
                    </div>
                    <div id="profileAlert" class="alert alert-danger" style="display:none;" role="alert"></div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="submit" class="p-btn primary" id="profileSaveBtn">{$tSaveChanges}</button>
                        <button type="button" class="p-btn outline" id="profileCancelBtn">{$tCancel}</button>
                        <span id="profileSavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);">{$tSaving}</span>
                    </div>
                </form>
            </div>
        </div>

        <!-- الأمان -->
        <div class="settings-section" id="section_security" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tPasswordStatus}</h3></div>
                <div class="p-kv"><span class="k">{$tPasswordStatus}</span><span class="v"><span class="p-badge status-active">{$tPasswordSet}</span></span></div>
                <div class="p-kv"><span class="k">{$tLastPasswordChange}</span><span class="v">{$lastPasswordChange}</span></div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tChangePassword}</h3></div>
                <form id="securityForm" novalidate>
                    <div class="form-group">
                        <label class="form-label" for="current_password">{$tCurrentPassword}</label>
                        <input type="password" id="current_password" class="form-control" required aria-describedby="err_current_password">
                        <p class="field-error" id="err_current_password" role="alert"></p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="new_password">{$tNewPassword}</label>
                        <input type="password" id="new_password" class="form-control" minlength="8" required aria-describedby="err_new_password">
                        <p class="field-error" id="err_new_password" role="alert"></p>
                    </div>
                    <div id="securityAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary" id="securitySaveBtn">{$tUpdatePassword}</button>
                    <span id="securitySavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);margin-inline-start:10px;">{$tSaving}</span>
                </form>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$t2FATitle}</h3></div>
                <div id="tfaAlert" class="alert alert-danger" style="display:none;"></div>

                <div id="tfaDisabledState" style="display:{$tfaDisabledDisplay};">
                    <p class="p-cell-muted">{$tTwoFactorDesc}</p>
                    <button type="button" class="p-btn primary" onclick="startTwoFactorSetup()">{$tEnableTwoFactor}</button>
                </div>

                <div id="tfaSetupState" style="display:none;">
                    <p class="p-cell-muted">{$tTwoFactorSetupHint}</p>
                    <div class="form-group">
                        <label class="form-label">{$tSetupKeyLabel}</label>
                        <code id="tfaSecretDisplay" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:12px;border-radius:8px;direction:ltr;text-align:left;word-break:break-all;"></code>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tfaConfirmCode">{$tConfirmCodeLabel}</label>
                        <input type="text" id="tfaConfirmCode" class="form-control" inputmode="numeric" maxlength="6" style="letter-spacing:3px;">
                    </div>
                    <button type="button" class="p-btn primary" onclick="confirmTwoFactorSetup()">{$tConfirmAndEnable}</button>
                </div>

                <div id="tfaRecoveryState" style="display:none;">
                    <p class="p-cell-muted">{$tRecoveryCodesHint}</p>
                    <div id="tfaRecoveryCodesList" style="background:#f7f7fb;padding:14px;border-radius:8px;font-family:monospace;direction:ltr;text-align:left;line-height:1.8;"></div>
                    <button type="button" class="p-btn primary" style="margin-top:12px;" onclick="acknowledgeRecoveryCodes()">{$tSavedRecoveryCodes}</button>
                </div>

                <div id="tfaEnabledState" style="display:{$tfaEnabledDisplay};">
                    <p style="color:#2e7d32;">✔ {$tTwoFactorEnabledLabel}</p>
                    <div class="form-group" style="max-width:320px;">
                        <label class="form-label" for="tfaDisablePassword">{$tConfirmPasswordFor2FA}</label>
                        <input type="password" id="tfaDisablePassword" class="form-control">
                    </div>
                    <button type="button" class="p-btn danger" onclick="disableTwoFactor()">{$tDisableTwoFactor}</button>

                    <hr style="border:none;border-top:1px solid var(--panel-border,#2a2a3a);margin:18px 0;">
                    <h4 style="margin:0 0 6px;">{$tRegenerateRecoveryCodes}</h4>
                    <p class="p-cell-muted" style="font-size:12.5px;">{$tRegenRecoveryHint}</p>
                    <div class="form-group" style="max-width:320px;margin-top:12px;">
                        <label class="form-label" for="regenRecoveryPassword">{$tConfirmPasswordFor2FA}</label>
                        <input type="password" id="regenRecoveryPassword" class="form-control" autocomplete="current-password">
                    </div>
                    <div class="form-group" style="max-width:320px;">
                        <label class="form-label" for="regenRecoveryCode">{$tConfirmCodeLabel}</label>
                        <input type="text" id="regenRecoveryCode" class="form-control" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
                    </div>
                    <button type="button" class="p-btn outline" onclick="regenerateRecoveryCodes()">{$tRegenerateRecoveryCodes}</button>
                    <div id="regenRecoveryCodesBox" style="display:none;background:#f7f7fb;padding:14px;border-radius:8px;font-family:monospace;direction:ltr;text-align:left;line-height:1.8;margin-top:12px;"></div>
                </div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head">
                    <h3>{$tSessionsTitle}</h3>
                    <button type="button" class="p-btn outline xs" id="logoutOthersBtn">{$tLogoutOthers}</button>
                </div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tSessionsHint}</p>
                <div id="sessionsList" style="margin-top:12px;">{$tSessionsLoading}</div>
            </div>
        </div>

        <!-- الإشعارات -->
        <div class="settings-section" id="section_notifications" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tNotifPrefs}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tNotifChannelNote}</p>

                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_reviews"> {$tNotifReviewsCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_content"> {$tNotifContentCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_leads"> {$tNotifLeadsCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_system"> {$tNotifSystemCat}
                </label>

                <p class="p-cell-muted" style="font-size:12px;margin-top:10px;">ℹ️ {$tNotifUnavailableCats}</p>

                <div id="notifAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="button" class="p-btn primary" id="notifSaveBtn" onclick="saveNotifications()">{$tSavePrefs}</button>
                <span id="notifSavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);margin-inline-start:10px;">{$tSaving}</span>
            </div>
        </div>

        <!-- API -->
        <div class="settings-section" id="section_api" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tApiKeyTitle}</h3></div>
                <p class="p-cell-muted">{$tApiKeyDesc}</p>
                <code id="apiToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:14px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;margin:14px 0;">{$token}</code>
                <div style="display:flex;gap:10px;">
                    <button class="p-btn outline" onclick="copyToken()">📋 {$tCopyKey}</button>
                    <button class="p-btn outline" onclick="regenerateToken()">🔄 {$tRegenerateKey}</button>
                </div>
                <p class="p-cell-muted" style="font-size:12.5px;margin-top:10px;">⚠️ {$tRegenerateWarning}</p>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tPersonalKeysTitle}</h3></div>
                <p class="p-cell-muted">{$tPersonalKeysDesc}</p>

                <div style="display:flex;gap:10px;margin:14px 0;flex-wrap:wrap;">
                    <input type="text" id="newKeyName" class="form-control" placeholder="{$tKeyNamePlaceholder}" maxlength="120" style="flex:1;min-width:180px;">
                    <input type="number" id="newKeyExpiry" class="form-control" placeholder="{$tKeyExpiryDaysPlaceholder}" min="1" max="365" style="width:150px;">
                    <button type="button" class="p-btn primary" id="createKeyBtn">{$tCreateKey}</button>
                </div>
                <p class="p-cell-muted" style="font-size:12px;margin:-4px 0 0;">{$tKeyExpiryLabel}: <span style="color:var(--panel-text-muted,#888)">{$tKeyExpiresNever}</span></p>
                <p class="field-error" id="err_key_name" role="alert"></p>
                <div id="newKeyRevealBox" style="display:none;background:#1e1e2e;padding:14px;border-radius:8px;margin-bottom:14px;">
                    <p class="p-cell-muted" style="font-size:12.5px;margin-bottom:8px;">⚠️ {$tRegenerateWarning}</p>
                    <code id="newKeyRaw" style="display:block;color:#a6e3a1;overflow-x:auto;direction:ltr;text-align:left;"></code>
                </div>

                <div id="apiKeysList">{$tKeysLoading}</div>
            </div>
        </div>

        <!-- التكاملات (Phase 13) - مؤشر لصفحة /integrations الحقيقية -->
        <div class="settings-section" id="section_integrations" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>🔌 {$tIntegrationsTitle}</h3></div>
                <p class="p-cell-muted">{$tIntegrationsDesc}</p>
                <div class="p-cell-muted" style="font-size:12.5px;margin:10px 0 16px;padding:12px;background:var(--panel-bg,#151521);border-radius:8px;">
                    💡 {$tIntegrationsListHint}
                </div>
                <a href="/integrations" class="p-btn primary">{$tIntegrationsManageBtn}</a>
            </div>
        </div>

        <!-- الفوترة -->
        <div class="settings-section" id="section_billing" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tBillingPlanTitle}</h3></div>
                <div id="billingPlanBox">{$tBillingLoading}</div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tBillingWalletTitle}</h3></div>
                <div id="billingWalletBox">{$tBillingLoading}</div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tBillingInvoicesTitle}</h3></div>
                <div id="billingInvoicesBox">{$tBillingLoading}</div>
            </div>
        </div>

        <!-- سجل النشاط -->
        <div class="settings-section" id="section_audit" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tAuditTitle}</h3></div>
                <p class="p-cell-muted">{$tAuditDesc}</p>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0;align-items:end;">
                    <div class="form-group" style="flex:1;min-width:180px;margin:0;">
                        <input type="text" id="auditSearch" class="form-control" placeholder="{$tAuditSearchPlaceholder}">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditFrom">{$tAuditFrom}</label>
                        <input type="date" id="auditFrom" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditTo">{$tAuditTo}</label>
                        <input type="date" id="auditTo" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditResult">{$tAuditColResult}</label>
                        <select id="auditResult" class="form-control">
                            <option value="">{$tAuditAllResults}</option>
                            <option value="success">{$tAuditResultSuccess}</option>
                            <option value="failed">{$tAuditResultFailed}</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="auditAction">{$tAuditColAction}</label>
                        <input type="text" id="auditAction" class="form-control" placeholder="{$tAuditActionPlaceholder}">
                    </div>
                    <button type="button" class="p-btn outline" id="auditFilterBtn">{$tAuditFilterBtn}</button>
                    <button type="button" class="p-btn outline" id="auditExportBtn">⬇ {$tAuditExportBtn}</button>
                </div>

                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
                        <thead>
                            <tr style="border-bottom:1px solid rgba(255,255,255,.1);">
                                <th style="padding:8px;">{$tAuditColAction}</th>
                                <th style="padding:8px;">{$tAuditColObject}</th>
                                <th style="padding:8px;">{$tAuditColResult}</th>
                                <th style="padding:8px;">{$tAuditColTime}</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogBody">
                            <tr><td colspan="4" class="p-cell-muted" style="padding:14px;">{$tBillingLoading}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;justify-content:center;gap:12px;margin-top:14px;align-items:center;">
                    <button type="button" class="p-btn outline xs" id="auditPrevBtn">← {$tAuditPrev}</button>
                    <span id="auditPageInfo" class="p-cell-muted" style="font-size:12.5px;"></span>
                    <button type="button" class="p-btn outline xs" id="auditNextBtn">{$tAuditNext} →</button>
                </div>
            </div>
        </div>

        <!-- الـ Workspace -->
        <div class="settings-section" id="section_workspace" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>🏢 {$tWorkspaceTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">ℹ️ {$tWorkspaceScopeNote}</p>

                <div id="workspaceReadOnlyNotice" style="display:none;" class="p-cell-muted" style="font-size:12.5px;margin:10px 0;">⚠️ {$tWorkspaceReadOnlyNote}</div>

                <form id="workspaceForm" novalidate style="margin-top:14px;">
                    <div class="form-group">
                        <label class="form-label" for="ws_logo_preview">{$tWorkspaceLogo}</label>
                        <div style="display:flex;align-items:center;gap:14px;">
                            <img id="ws_logo_preview" src="" alt="" style="width:56px;height:56px;border-radius:10px;object-fit:cover;display:none;background:#222;">
                            <input type="file" id="ws_logo_input" accept="image/png,image/jpeg,image/webp">
                        </div>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="ws_name">{$tWorkspaceName}</label>
                            <input type="text" id="ws_name" name="name" class="form-control" maxlength="150" aria-describedby="err_ws_name">
                            <p class="field-error" id="err_ws_name" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ws_industry">{$tWorkspaceIndustry}</label>
                            <input type="text" id="ws_industry" name="industry" class="form-control" maxlength="100" aria-describedby="err_ws_industry">
                            <p class="field-error" id="err_ws_industry" role="alert"></p>
                        </div>
                    </div>
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="ws_country">{$tWorkspaceCountry}</label>
                            <input type="text" id="ws_country" name="country_code" class="form-control" maxlength="5" placeholder="EG">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ws_timezone">{$tWorkspaceTimezone}</label>
                            <input type="text" id="ws_timezone" name="timezone" class="form-control" placeholder="Africa/Cairo">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ws_language">{$tWorkspaceLanguage}</label>
                        <select id="ws_language" name="default_language" class="form-control">
                            <option value="ar">العربية</option>
                            <option value="en">English</option>
                            <option value="fr">Français</option>
                            <option value="de">Deutsch</option>
                        </select>
                    </div>
                    <div id="workspaceAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary" id="workspaceSaveBtn">{$tSaveChanges}</button>
                    <span id="workspaceSavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);margin-inline-start:10px;">{$tSaving}</span>
                </form>
            </div>
        </div>

        <!-- الفريق -->
        <div class="settings-section" id="section_team" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>👥 {$tTeamTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">ℹ️ {$tTeamPermissionNote}</p>

                <div id="teamInviteBox" style="display:none;margin:14px 0;padding:14px;border:1px solid rgba(255,255,255,.1);border-radius:8px;">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                        <div class="form-group" style="flex:1;min-width:200px;margin:0;">
                            <label class="form-label" for="invite_email">{$tInviteEmail}</label>
                            <input type="email" id="invite_email" class="form-control">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="invite_role">{$tInviteRole}</label>
                            <select id="invite_role" class="form-control">
                                <option value="admin">admin</option>
                                <option value="manager">manager</option>
                                <option value="sales">sales</option>
                                <option value="support">support</option>
                                <option value="viewer" selected>viewer</option>
                            </select>
                        </div>
                        <button type="button" class="p-btn primary" id="sendInviteBtn">{$tInviteSend}</button>
                    </div>
                    <p class="field-error" id="err_invite_email" role="alert"></p>
                    <div id="inviteResultBox" style="display:none;margin-top:10px;padding:10px;background:#1e1e2e;border-radius:8px;font-size:12.5px;"></div>
                </div>

                <h4 style="font-size:13.5px;margin:18px 0 8px;">{$tPendingInvitesTitle}</h4>
                <div id="pendingInvitesList" class="p-cell-muted">{$tBillingLoading}</div>

                <h4 style="font-size:13.5px;margin:18px 0 8px;">{$tMembersTitle}</h4>
                <div id="membersList">{$tBillingLoading}</div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tPermissionMatrixTitle}</h3></div>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);">
                            <th style="padding:6px;text-align:start;">Role</th>
                            <th style="padding:6px;">manage_workspace</th>
                            <th style="padding:6px;">manage_team</th>
                            <th style="padding:6px;">view_billing</th>
                        </tr></thead>
                        <tbody>
                            <tr><td style="padding:6px;">admin</td><td style="text-align:center;">✅</td><td style="text-align:center;">✅</td><td style="text-align:center;">✅</td></tr>
                            <tr><td style="padding:6px;">manager</td><td style="text-align:center;">—</td><td style="text-align:center;">✅</td><td style="text-align:center;">✅</td></tr>
                            <tr><td style="padding:6px;">sales</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td></tr>
                            <tr><td style="padding:6px;">support</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td></tr>
                            <tr><td style="padding:6px;">viewer</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td><td style="text-align:center;">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- اللغة والمنطقة -->
        <div class="settings-section" id="section_general" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tAccountPrefs}</h3></div>
                <form id="generalForm">
                    <div class="form-group">
                        <label class="form-label" for="language">{$tInterfaceLang}</label>
                        <select id="language" class="form-control">
                            {$languageOptionsHtml}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="timezone">{$tTimezone}</label>
                        <select id="timezone" class="form-control">
                            {$timezoneOptionsHtml}
                        </select>
                    </div>
                    <div id="generalAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary">{$tSaveSettings}</button>
                </form>
            </div>

            <div class="p-card" style="margin-top:16px;border:1px solid #f3c6c6;">
                <div class="p-card-head"><h3 style="color:#c0392b;">⚠️ {$tDangerZone}</h3></div>

                <div id="leaveWorkspaceBox" style="display:none;padding:14px;border:1px solid #eee;border-radius:8px;margin-bottom:16px;">
                    <h4 style="margin:0 0 6px;font-size:14px;">{$tLeaveWorkspaceTitle}</h4>
                    <p class="p-cell-muted">{$tLeaveWorkspaceWarning}</p>
                    <form id="leaveWorkspaceForm" novalidate style="margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label" for="leave_password">{$tConfirmPasswordLabel}</label>
                            <input type="password" id="leave_password" class="form-control" required aria-describedby="err_leave_password">
                            <p class="field-error" id="err_leave_password" role="alert"></p>
                        </div>
                        <div id="leaveWorkspaceAlert" class="alert alert-danger" style="display:none;"></div>
                        <button type="submit" class="p-btn outline">{$tLeaveWorkspaceBtn}</button>
                    </form>
                </div>

                <div style="padding:14px;border:1px solid #eee;border-radius:8px;margin-bottom:16px;">
                    <h4 style="margin:0 0 6px;font-size:14px;">{$tDeactivateTitle}</h4>
                    <p class="p-cell-muted">{$tDeactivateWarning}</p>
                    <form id="deactivateForm" novalidate style="margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label" for="deactivate_password">{$tConfirmPasswordLabel}</label>
                            <input type="password" id="deactivate_password" class="form-control" required aria-describedby="err_deactivate_password">
                            <p class="field-error" id="err_deactivate_password" role="alert"></p>
                        </div>
                        <div id="deactivateAlert" class="alert alert-danger" style="display:none;"></div>
                        <button type="submit" class="p-btn outline">{$tDeactivateAccount}</button>
                    </form>
                </div>

                <div style="padding:14px;border:1px solid #f3c6c6;border-radius:8px;">
                    <h4 style="margin:0 0 6px;font-size:14px;color:#c0392b;">{$tDeleteAccount}</h4>
                    <p class="p-cell-muted">{$tDeleteWarning}</p>
                    <form id="deleteAccountForm" novalidate style="margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label" for="delete_password">{$tConfirmPasswordLabel}</label>
                            <input type="password" id="delete_password" class="form-control" required aria-describedby="err_delete_password">
                            <p class="field-error" id="err_delete_password" role="alert"></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="delete_confirm_email">{$tConfirmEmailLabel}</label>
                            <input type="email" id="delete_confirm_email" class="form-control" required aria-describedby="err_delete_confirm_email">
                            <p class="p-cell-muted" style="font-size:12px;">{$tConfirmEmailHint}</p>
                            <p class="field-error" id="err_delete_confirm_email" role="alert"></p>
                        </div>
                        <div id="deleteAccountAlert" class="alert alert-danger" style="display:none;"></div>
                        <button type="submit" class="p-btn danger">{$tDeleteAccount}</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- الحسابات المرتبطة -->
        <div class="settings-section" id="section_connected" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tConnectedTitle}</h3></div>
                {$connectedAccountsHtml}
            </div>
        </div>

        <!-- سجل تسجيل الدخول -->
        <div class="settings-section" id="section_activity" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tActivityTitle}</h3></div>
                {$loginActivityHtml}
            </div>
        </div>

        <!-- الصلاحيات (Read-only) -->
        <div class="settings-section" id="section_permissions" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tCurrentRoleLabel}: {$currentRoleLabel}</h3></div>
            </div>
            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tFeatureAccessLabel}</h3></div>
                <p class="p-cell-muted">{$tFeatureAccessDesc}</p>
                {$permissionsHtml}
            </div>
            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tPrivacyTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tPrivacyDesc}</p>
                <a class="p-btn outline" href="/privacy" target="_blank" rel="noopener">{$tViewPrivacyPolicy}</a>
            </div>
            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>{$tDataExportTitle}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tDataExportDesc}</p>
                <div id="exportAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="button" class="p-btn primary" onclick="requestDataExport()">{$tRequestExport}</button>
                <div id="exportsList" style="margin-top:14px;"></div>
            </div>
        </div>
HTML;

        $tUploading = $this->trJs('settings.js.uploading');
        $tPhotoChanged = $this->trJs('settings.js.photo_changed');
        $tPhotoUploadFailed = $this->trJs('settings.js.photo_upload_failed');
        $tConnectionFailed = $this->trJs('settings.js.connection_failed');
        $tChangesSaved = $this->trJs('settings.js.changes_saved');
        $tSaveFailed = $this->trJs('settings.js.save_failed');
        $tPasswordUpdated = $this->trJs('settings.js.password_updated');
        $tUpdateFailed = $this->trJs('settings.js.update_failed');
        $tSessionsLoadFailed = $this->trJs('settings.js.sessions_load_failed');
        $tPasswordRequired = $this->trJs('settings.js.password_required');
        $tTfaEnabledToast = $this->trJs('settings.js.tfa_enabled');
        $tTfaDisabledToast = $this->trJs('settings.js.tfa_disabled');
        $tTfaDisableConfirm = $this->trJs('settings.js.tfa_disable_confirm');
        $tDisconnectConfirm = $this->trJs('settings.js.disconnect_confirm');
        $tConnectedSuccess = $this->trJs('settings.js.connected_success');
        $tDisconnectFailed = $this->trJs('settings.js.disconnect_failed');
        $tAccountDisconnected = $this->trJs('settings.js.account_disconnected');
        $tExportRequested = $this->trJs('settings.js.export_requested');
        $tExportRequestedStatus = $this->trJs('settings.export_status_requested');
        $tExportProcessingStatus = $this->trJs('settings.export_status_processing');
        $tExportReadyStatus = $this->trJs('settings.export_status_ready');
        $tExportFailedStatus = $this->trJs('settings.export_status_failed');
        $tDownload = $this->trJs('settings.download');
        $tLogoutDeviceConfirm = $this->trJs('settings.js.logout_device_confirm');
        $tLogoutOthersConfirm = $this->trJs('settings.js.logout_others_confirm');
        $tDeviceLoggedOut = $this->trJs('settings.js.device_logged_out');
        $tOthersLoggedOut = $this->trJs('settings.js.others_logged_out');
        $tKeyCreated = $this->trJs('settings.js.key_created');
        $tKeysLoadFailed = $this->trJs('settings.js.keys_load_failed');
        $tRevokeConfirm = $this->trJs('settings.js.revoke_confirm');
        $tKeyRevoked = $this->trJs('settings.js.key_revoked');
        $tBillingLoadFailed = $this->trJs('settings.js.billing_load_failed');
        $tCancelPlanConfirm = $this->trJs('settings.js.cancel_plan_confirm');
        $tPlanCancelled = $this->trJs('settings.js.plan_cancelled');
        $tCancelFailed = $this->trJs('settings.js.cancel_failed');
        $tPlanNameLabel = $this->trJs('settings.js.plan_name_label');
        $tPlanStatusLabel = $this->trJs('settings.js.plan_status_label');
        $tPlanPriceLabel = $this->trJs('settings.js.plan_price_label');
        $tPlanExpiryLabel = $this->trJs('settings.js.plan_expiry_label');
        $tWalletBalanceLabel = $this->trJs('settings.js.wallet_balance_label');
        $tAuditLoadFailed = $this->trJs('settings.js.audit_load_failed');
        $tAuditPageOf = $this->trJs('settings.js.audit_page_of');
        $tLoadingJs = $this->trJs('settings.keys_loading');
        $tWorkspaceSaved = $this->trJs('settings.js.workspace_saved');
        $tWorkspaceSaveFailed = $this->trJs('settings.js.workspace_save_failed');
        $tLogoUpdated = $this->trJs('settings.js.logo_updated');
        $tLogoFailed = $this->trJs('settings.js.logo_failed');
        $tInviteSent = $this->trJs('settings.js.invite_sent');
        $tInviteFailed = $this->trJs('settings.js.invite_failed');
        $tInviteRevoked = $this->trJs('settings.js.invite_revoked');
        $tRevokeInviteConfirm = $this->trJs('settings.js.revoke_invite_confirm');
        $tRoleChanged = $this->trJs('settings.js.role_changed');
        $tRoleChangeFailed = $this->trJs('settings.js.role_change_failed');
        $tMemberDeactivated = $this->trJs('settings.js.member_deactivated');
        $tMemberReactivated = $this->trJs('settings.js.member_reactivated');
        $tMemberRemoved = $this->trJs('settings.js.member_removed');
        $tRemoveMemberConfirm = $this->trJs('settings.js.remove_member_confirm');
        $tDeactivateMemberConfirm = $this->trJs('settings.js.deactivate_member_confirm');
        $tCopyLink = $this->trJs('settings.js.copy_link');
        $tLinkCopied = $this->trJs('settings.js.link_copied');
        $tNoInvites = $this->trJs('settings.js.no_invites');
        $tWorkspaceLoadFailed = $this->trJs('settings.js.workspace_load_failed');
        $tMembersLoadFailed = $this->trJs('settings.js.members_load_failed');
        $tLeaveWorkspaceConfirm = $this->trJs('settings.js.leave_workspace_confirm');
        $tLeftWorkspace = $this->trJs('settings.js.left_workspace');
        $tLeaveFailed = $this->trJs('settings.js.leave_failed');
        $tDeactivateBtnLabel = $this->trJs('settings.js.deactivate_btn_label');
        $tRemoveBtnLabel = $this->trJs('settings.js.remove_btn_label');
        $tPrefsSaved = $this->trJs('settings.js.prefs_saved');
        $tKeyCopied = $this->trJs('settings.js.key_copied');
        $tRegenerateConfirm = $this->trJs('settings.js.regenerate_confirm');
        $tKeyRegenerated = $this->trJs('settings.js.key_regenerated');
        $tGenerateFailed = $this->trJs('settings.js.generate_failed');
        $tSettingsSaved = $this->trJs('settings.js.settings_saved');
        $tDeleteConfirm1 = $this->trJs('settings.js.delete_confirm1');
        $tDeleteConfirm2 = $this->trJs('settings.js.delete_confirm2');
        $tDeleteConfirmSubscription = $this->trJs('settings.js.delete_confirm_subscription');
        $tAccountDeleted = $this->trJs('settings.js.account_deleted');
        $tDeleteFailed = $this->trJs('settings.js.delete_failed');
        $tDeactivateConfirm = $this->trJs('settings.js.deactivate_confirm');
        $tAccountDeactivated = $this->trJs('settings.js.account_deactivated');
        $tDeactivateFailed = $this->trJs('settings.js.deactivate_failed');

        $script = <<<JS
(function () {
    const P = window.Panel;
    const esc = P.esc, toast = P.toast;
    const rawFetchJSON = P.fetchJSON;

    // Phase 12: نحقن csrf_token تلقائيًا في أي نداء بجسم JSON (POST/PUT/
    // DELETE) - بنستبدل fetchJSON المحلية بغلاف بسيط حواليها، عشان كل
    // نداء fetchJSON(...) موجود بالفعل في الملف ده كله (عشرات النداءات)
    // ياخد الحماية دي تلقائيًا من غير ما نلمس ولا نداء منهم بنفسه.
    // مبنيّة فوق window.TF_CSRF_TOKEN المحقون فوق في الصفحة.
    // عملاء Bearer token (Authorization header) مش محتاجين التوكن ده.
    async function fetchJSON(url, options = {}) {
        const method = (options.method || 'GET').toUpperCase();
        if (method !== 'GET' && typeof options.body === 'string') {
            try {
                const bodyObj = JSON.parse(options.body);
                bodyObj.csrf_token = window.TF_CSRF_TOKEN || '';
                options = Object.assign({}, options, { body: JSON.stringify(bodyObj) });
            } catch (e) {
                // جسم الطلب مش JSON (مثلًا FormData لرفع صورة) - نسيبه زي
                // ما هو، أماكن الرفع بتضيف csrf_token بنفسها لو محتاجة.
            }
        } else if (method !== 'GET' && !options.body) {
            // نداءات POST من غير جسم (زي 2fa/setup) - نضيف جسم بسيط فيه التوكن.
            options = Object.assign({}, options, {
                headers: Object.assign({ 'Content-Type': 'application/json' }, options.headers || {}),
                body: JSON.stringify({ csrf_token: window.TF_CSRF_TOKEN || '' }),
            });
        } else if (method !== 'GET' && typeof FormData !== 'undefined' && options.body instanceof FormData) {
            options.body.append('csrf_token', window.TF_CSRF_TOKEN || '');
        }
        return rawFetchJSON(url, options);
    }

    // ============ التابات (ديسكتوب + Dropdown الموبايل - Phase 14) ============
    const settingsSections = ['profile', 'security', 'notifications', 'api', 'integrations', 'billing', 'audit', 'workspace', 'team', 'general', 'connected', 'activity', 'permissions'];
    function switchSettingsTab(section) {
        if (settingsSections.indexOf(section) === -1) {
            section = 'profile';
        }
        document.querySelectorAll('#settingsTabs .p-tab').forEach(b => {
            b.classList.toggle('active', b.dataset.section === section);
        });
        document.getElementById('settingsTabsMobile').value = section;
        document.querySelectorAll('.settings-section').forEach(s => {
            s.style.display = (s.id === 'section_' + section) ? 'block' : 'none';
        });
    }
    document.querySelectorAll('#settingsTabs .p-tab').forEach(btn => {
        btn.addEventListener('click', () => switchSettingsTab(btn.dataset.section));
    });
    document.getElementById('settingsTabsMobile').addEventListener('change', function () {
        switchSettingsTab(this.value);
    });

    // Connected Accounts (Profile Center Phase 2): توست بعد الرجوع من
    // /auth/{provider}?link=1 (نجاح أو فشل)، وتنظيف الـURL بعدها.
    (function handleOAuthRedirectFlash() {
        const params = new URLSearchParams(window.location.search);
        const connected = params.get('oauth_connected');
        const error = params.get('oauth_error');
        if (connected) {
            toast(connected + ' ' + {$tConnectedSuccess}, 'success');
        }
        if (error) {
            toast(error, 'error');
        }
        if (connected || error) {
            params.delete('oauth_connected');
            params.delete('oauth_error');
            const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState({}, '', clean);
        }
    })();

    // ============ الملف الشخصي + الصورة ============
    document.getElementById('avatarInput').addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('avatar', file);

        const wrap = document.getElementById('avatarPreviewWrap');
        const originalHtml = wrap.innerHTML;
        wrap.innerHTML = '<span style="font-size:13px;">' + {$tUploading} + '</span>';

        try {
            const res = await fetch('/api/user/avatar', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                wrap.innerHTML = `<img src="\${esc(data.data.avatar_url)}" alt="الصورة الشخصية" style="width:100%;height:100%;object-fit:cover;">`;
                toast({$tPhotoChanged}, 'success');
            } else {
                wrap.innerHTML = originalHtml;
                toast(data.error || {$tPhotoUploadFailed}, 'error');
            }
        } catch (err) {
            wrap.innerHTML = originalHtml;
            toast({$tConnectionFailed}, 'error');
        }
    });

    const profileFieldIds = ['first_name', 'last_name', 'display_name', 'company_name', 'job_title', 'phone', 'country_code', 'currency', 'bio'];
    const profileOriginal = {};
    profileFieldIds.forEach(id => { profileOriginal[id] = document.getElementById(id).value; });

    function clearProfileFieldErrors() {
        profileFieldIds.forEach(id => {
            const err = document.getElementById('err_' + id);
            if (err) err.textContent = '';
        });
    }

    const bioEl = document.getElementById('bio');
    const bioCount = document.getElementById('bioCount');
    function updateBioCount() { bioCount.textContent = bioEl.value.length; }
    bioEl.addEventListener('input', updateBioCount);
    updateBioCount();

    document.getElementById('profileForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('profileAlert');
        const saveBtn = document.getElementById('profileSaveBtn');
        const savingIndicator = document.getElementById('profileSavingIndicator');
        alertBox.style.display = 'none';
        clearProfileFieldErrors();

        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';

        let res;
        try {
            res = await fetchJSON('/api/user/profile', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    first_name: document.getElementById('first_name').value.trim(),
                    last_name: document.getElementById('last_name').value.trim(),
                    display_name: document.getElementById('display_name').value.trim(),
                    company_name: document.getElementById('company_name').value.trim(),
                    job_title: document.getElementById('job_title').value.trim(),
                    bio: bioEl.value.trim(),
                    phone: document.getElementById('phone').value.trim(),
                    country_code: document.getElementById('country_code').value.trim(),
                    currency: document.getElementById('currency').value.trim(),
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) {
            profileFieldIds.forEach(id => { profileOriginal[id] = document.getElementById(id).value; });
            toast({$tChangesSaved}, 'success');
        } else {
            // لا نفرغ الفورم أبدًا لو فشل الحفظ - القيم اللي كتبها المستخدم تفضل زي ما هي
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_' + field);
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('profileCancelBtn').addEventListener('click', function () {
        profileFieldIds.forEach(id => { document.getElementById(id).value = profileOriginal[id]; });
        updateBioCount();
        clearProfileFieldErrors();
        document.getElementById('profileAlert').style.display = 'none';
    });

    // ============ التحقق بخطوتين (2FA) ============
    window.startTwoFactorSetup = async function () {
        const alertBox = document.getElementById('tfaAlert');
        alertBox.style.display = 'none';
        const res = await fetchJSON('/api/user/2fa/setup', { method: 'POST' });
        if (!res.success) {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
            return;
        }
        document.getElementById('tfaSecretDisplay').textContent = res.data.secret;
        document.getElementById('tfaDisabledState').style.display = 'none';
        document.getElementById('tfaSetupState').style.display = 'block';
    };

    window.confirmTwoFactorSetup = async function () {
        const alertBox = document.getElementById('tfaAlert');
        alertBox.style.display = 'none';
        const code = document.getElementById('tfaConfirmCode').value.trim();
        const res = await fetchJSON('/api/user/2fa/enable', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code }),
        });
        if (!res.success) {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
            return;
        }
        document.getElementById('tfaSetupState').style.display = 'none';
        const list = document.getElementById('tfaRecoveryCodesList');
        list.innerHTML = res.data.recovery_codes.map(c => '<div>' + c + '</div>').join('');
        document.getElementById('tfaRecoveryState').style.display = 'block';
    };

    window.acknowledgeRecoveryCodes = function () {
        document.getElementById('tfaRecoveryState').style.display = 'none';
        document.getElementById('tfaEnabledState').style.display = 'block';
        toast({$tTfaEnabledToast}, 'success');
    };

    window.disableTwoFactor = async function () {
        const alertBox = document.getElementById('tfaAlert');
        alertBox.style.display = 'none';
        const password = document.getElementById('tfaDisablePassword').value;
        if (!password) {
            alertBox.textContent = {$tPasswordRequired};
            alertBox.style.display = 'block';
            return;
        }
        if (!confirm({$tTfaDisableConfirm})) return;
        const res = await fetchJSON('/api/user/2fa/disable', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password }),
        });
        if (!res.success) {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
            return;
        }
        document.getElementById('tfaEnabledState').style.display = 'none';
        document.getElementById('tfaDisabledState').style.display = 'block';
        toast({$tTfaDisabledToast}, 'success');
    };

    window.regenerateRecoveryCodes = async function () {
        if (!confirm({$tRegenRecoveryConfirm})) return;

        const password = document.getElementById('regenRecoveryPassword').value;
        const code = document.getElementById('regenRecoveryCode').value;
        if (!password || !code) {
            toast({$tRecoveryNeedTotp}, 'error');
            return;
        }

        const res = await fetchJSON('/api/user/2fa/recovery-codes/regenerate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password, code: code }),
        });
        if (!res.success) {
            toast(res.error || {$tRegenRecoveryFailed}, 'error');
            return;
        }
        const box = document.getElementById('regenRecoveryCodesBox');
        box.innerHTML = res.data.recovery_codes.map(c => '<div>' + c + '</div>').join('');
        box.style.display = 'block';
        document.getElementById('regenRecoveryPassword').value = '';
        document.getElementById('regenRecoveryCode').value = '';
        toast({$tRegenRecoveryDone}, 'success');
    };

    window.disconnectOAuth = async function (provider) {
        if (!confirm({$tDisconnectConfirm})) return;
        const res = await fetchJSON('/api/user/oauth/' + encodeURIComponent(provider), { method: 'DELETE' });
        if (res.success) {
            toast({$tAccountDisconnected}, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            toast(res.error || {$tDisconnectFailed}, 'error');
        }
    };

    // ============ الأمان ============
    document.getElementById('securityForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('securityAlert');
        const saveBtn = document.getElementById('securitySaveBtn');
        const savingIndicator = document.getElementById('securitySavingIndicator');
        alertBox.style.display = 'none';
        document.getElementById('err_current_password').textContent = '';
        document.getElementById('err_new_password').textContent = '';

        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';

        let res;
        try {
            res = await fetchJSON('/api/user/password', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    current_password: document.getElementById('current_password').value,
                    new_password: document.getElementById('new_password').value,
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) {
            toast({$tPasswordUpdated}, 'success');
            document.getElementById('securityForm').reset();
            loadSessions();
        } else {
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_' + field);
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tUpdateFailed};
            alertBox.style.display = 'block';
        }
    });

    // ============ الجلسات النشطة ============
    function escapeHtml(str) {
        return (str || '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function loadSessions() {
        const list = document.getElementById('sessionsList');
        const res = await fetchJSON('/api/user/sessions');
        if (!res.success) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tSessionsLoadFailed}) + '</p>';
            return;
        }
        const sessions = res.data.sessions || [];
        if (sessions.length === 0) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tSessionsEmpty}) + '</p>';
            return;
        }
        list.innerHTML = sessions.map(s => `
            <div class="p-kv" style="align-items:center;">
                <span class="k">
                    \${escapeHtml(s.device_name)} — \${escapeHtml(s.browser)} / \${escapeHtml(s.os)}
                    \${s.is_current ? '<span class="p-badge status-active" style="margin-inline-start:8px;">' + esc({$tCurrentDevice}) + '</span>' : ''}
                    <br><span class="p-cell-muted" style="font-size:12px;">\${escapeHtml(s.ip_masked)} · \${escapeHtml(s.last_active || s.created_at || '')}</span>
                </span>
                <span class="v">
                    \${s.is_current ? '' : `<button type="button" class="p-btn outline xs" data-logout-session="\${s.id}">\${esc({$tLogoutDevice})}</button>`}
                </span>
            </div>
        `).join('');

        list.querySelectorAll('[data-logout-session]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tLogoutDeviceConfirm})) return;
                const id = this.getAttribute('data-logout-session');
                const r = await fetchJSON('/api/user/sessions/' + id + '/logout', { method: 'POST' });
                if (r.success) {
                    toast({$tDeviceLoggedOut}, 'success');
                    loadSessions();
                } else {
                    toast(r.error || {$tUpdateFailed}, 'error');
                }
            });
        });
    }

    document.getElementById('logoutOthersBtn').addEventListener('click', async function () {
        if (!confirm({$tLogoutOthersConfirm})) return;
        const res = await fetchJSON('/api/user/sessions/logout-others', { method: 'POST' });
        if (res.success) {
            toast({$tOthersLoggedOut}, 'success');
            loadSessions();
        } else {
            toast(res.error || {$tUpdateFailed}, 'error');
        }
    });

    // ============ مفاتيح API الشخصية ============
    async function loadApiKeys() {
        const list = document.getElementById('apiKeysList');
        const res = await fetchJSON('/api/user/api-keys');
        if (!res.success) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tKeysLoadFailed}) + '</p>';
            return;
        }
        const keys = res.data.keys || [];
        if (keys.length === 0) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tKeysEmpty}) + '</p>';
            return;
        }
        const expiresLabel = esc({$tKeyExpiresNever});
        list.innerHTML = keys.map(k => `
            <div class="p-kv" style="align-items:center;">
                <span class="k">
                    \${escapeHtml(k.name)}
                    \${k.revoked ? '<span class="p-badge status-suspended" style="margin-inline-start:8px;">' + esc({$tRevoked}) + '</span>' : ''}
                    <br><span class="p-cell-muted" style="font-size:12px;direction:ltr;display:inline-block;">\${escapeHtml(k.key_prefix)}••••••••</span>
                    <span class="p-cell-muted" style="font-size:12px;"> · \${escapeHtml(k.last_used_at || esc({$tNeverUsed}))}</span>
                    <span class="p-cell-muted" style="font-size:12px;"> · \${k.expires_at ? esc({$tKeyExpiryLabel}) + ' ' + escapeHtml(k.expires_at) : expiresLabel}</span>
                </span>
                <span class="v">
                    \${k.revoked ? '' : `<button type="button" class="p-btn outline xs" data-revoke-key="\${k.id}">\${esc({$tRevoke})}</button>`}
                </span>
            </div>
        `).join('');

        list.querySelectorAll('[data-revoke-key]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tRevokeConfirm})) return;
                const id = this.getAttribute('data-revoke-key');
                const r = await fetchJSON('/api/user/api-keys/' + id + '/revoke', { method: 'POST' });
                if (r.success) {
                    toast({$tKeyRevoked}, 'success');
                    loadApiKeys();
                } else {
                    toast(r.error || {$tUpdateFailed}, 'error');
                }
            });
        });
    }

    document.getElementById('createKeyBtn').addEventListener('click', async function () {
        const nameInput = document.getElementById('newKeyName');
        const errEl = document.getElementById('err_key_name');
        errEl.textContent = '';

        const btn = this;
        btn.disabled = true;
        let res;
        try {
            const expiryInput = document.getElementById('newKeyExpiry');
            const expiryDays = expiryInput && expiryInput.value ? parseInt(expiryInput.value, 10) : 0;
            res = await fetchJSON('/api/user/api-keys', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: nameInput.value.trim(), expires_in_days: expiryDays > 0 ? expiryDays : 0 }),
            });
        } finally {
            btn.disabled = false;
        }

        if (res.success) {
            nameInput.value = '';
            const box = document.getElementById('newKeyRevealBox');
            document.getElementById('newKeyRaw').textContent = res.data.raw_key;
            box.style.display = 'block';
            toast({$tKeyCreated}, 'success');
            loadApiKeys();
        } else {
            if (res.details && res.details.name) {
                errEl.textContent = Array.isArray(res.details.name) ? res.details.name[0] : res.details.name;
            } else {
                toast(res.error || {$tSaveFailed}, 'error');
            }
        }
    });

    // ============ الإشعارات ============
    async function loadNotifications() {
        const res = await fetchJSON('/api/settings/notifications');
        if (res.success) {
            const prefs = res.data.preferences || {};
            document.getElementById('notif_cat_reviews').checked = prefs.reviews !== false;
            document.getElementById('notif_cat_content').checked = prefs.content_publishing !== false;
            document.getElementById('notif_cat_leads').checked = prefs.leads !== false;
            document.getElementById('notif_cat_system').checked = prefs.system !== false;
        }
    }

    window.saveNotifications = async function () {
        const alertBox = document.getElementById('notifAlert');
        const saveBtn = document.getElementById('notifSaveBtn');
        const savingIndicator = document.getElementById('notifSavingIndicator');
        alertBox.style.display = 'none';
        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';

        let res;
        try {
            res = await fetchJSON('/api/settings/notifications', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    preferences: {
                        reviews: document.getElementById('notif_cat_reviews').checked,
                        content_publishing: document.getElementById('notif_cat_content').checked,
                        leads: document.getElementById('notif_cat_leads').checked,
                        system: document.getElementById('notif_cat_system').checked,
                    },
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) toast({$tPrefsSaved}, 'success');
        else { alertBox.textContent = res.error || {$tSaveFailed}; alertBox.style.display = 'block'; }
    };

    // ============ API ============
    window.copyToken = function () {
        const text = document.getElementById('apiToken').textContent;
        navigator.clipboard.writeText(text).then(() => toast({$tKeyCopied}, 'success'));
    };

    window.regenerateToken = async function () {
        if (!confirm({$tRegenerateConfirm})) return;
        const res = await fetchJSON('/api/settings/api/regenerate', { method: 'POST' });
        if (res.success) {
            document.getElementById('apiToken').textContent = res.data.api_token;
            toast({$tKeyRegenerated}, 'success');
        } else {
            toast(res.error || {$tGenerateFailed}, 'error');
        }
    };

    // ============ الفوترة ============
    async function loadBilling() {
        loadBillingPlan();
        loadBillingWallet();
        loadBillingInvoices();
    }

    async function loadBillingPlan() {
        const box = document.getElementById('billingPlanBox');
        const res = await fetchJSON('/api/subscription/current');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingLoadFailed}) + '</p>'; return; }

        if (!res.data.has_subscription) {
            box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingNoPlan}) + '</p>';
            return;
        }

        const s = res.data.subscription;
        box.innerHTML = `
            <div class="p-kv"><span class="k">\${esc({$tPlanNameLabel})}</span><span class="v">\${escapeHtml(s.plan_name)} (\${escapeHtml(s.plan_type)})</span></div>
            <div class="p-kv"><span class="k">\${esc({$tPlanStatusLabel})}</span><span class="v"><span class="p-badge status-\${escapeHtml(s.status)}">\${escapeHtml(s.status)}</span></span></div>
            <div class="p-kv"><span class="k">\${esc({$tPlanPriceLabel})}</span><span class="v">\${escapeHtml(s.price)} \${escapeHtml(s.currency || 'USD')}</span></div>
            <div class="p-kv"><span class="k">\${esc({$tPlanExpiryLabel})}</span><span class="v">\${escapeHtml(s.expiry_date || '-')}</span></div>
        `;
        if (s.status === 'active') {
            box.innerHTML += `<button type="button" class="p-btn outline" id="cancelPlanBtn" style="margin-top:10px;">\${esc({$tBillingCancelPlan})}</button>`;
            document.getElementById('cancelPlanBtn').addEventListener('click', async function () {
                if (!confirm({$tCancelPlanConfirm})) return;
                const r = await fetchJSON('/api/subscription/cancel', { method: 'POST' });
                if (r.success) { toast({$tPlanCancelled}, 'success'); loadBillingPlan(); }
                else toast(r.error || {$tCancelFailed}, 'error');
            });
        }
    }

    async function loadBillingWallet() {
        const box = document.getElementById('billingWalletBox');
        const res = await fetchJSON('/api/wallet/balance');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingLoadFailed}) + '</p>'; return; }
        box.innerHTML = `<div class="p-kv"><span class="k">\${esc({$tWalletBalanceLabel})}</span><span class="v" style="font-weight:800;">\${escapeHtml(res.data.balance)}</span></div>`;
    }

    async function loadBillingInvoices() {
        const box = document.getElementById('billingInvoicesBox');
        const res = await fetchJSON('/api/subscription/invoices');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingLoadFailed}) + '</p>'; return; }
        const invoices = res.data.invoices || [];
        if (invoices.length === 0) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingInvoicesEmpty}) + '</p>'; return; }
        box.innerHTML = invoices.map(inv => `
            <div class="p-kv">
                <span class="k">\${escapeHtml(inv.invoice_number)} <span class="p-cell-muted" style="font-size:12px;">· \${escapeHtml(inv.created_at)}</span></span>
                <span class="v">\${escapeHtml(inv.amount)} \${escapeHtml(inv.currency || 'USD')} <span class="p-badge status-\${escapeHtml(inv.status)}" style="margin-inline-start:8px;">\${escapeHtml(inv.status)}</span></span>
            </div>
        `).join('');
    }

    // ============ سجل النشاط ============
    let auditPage = 1;
    let auditHasNext = false;

    async function loadAuditLog() {
        const body = document.getElementById('auditLogBody');
        body.innerHTML = `<tr><td colspan="4" class="p-cell-muted" style="padding:14px;">\${esc({$tLoadingJs})}</td></tr>`;

        const qs = new URLSearchParams({
            page: String(auditPage),
            search: document.getElementById('auditSearch').value.trim(),
            from: document.getElementById('auditFrom').value,
            to: document.getElementById('auditTo').value,
            result: document.getElementById('auditResult').value,
            action: document.getElementById('auditAction').value.trim(),
        });

        const res = await fetchJSON('/api/user/audit-log?' + qs.toString());
        if (!res.success) {
            body.innerHTML = `<tr><td colspan="4" class="p-cell-muted" style="padding:14px;">\${esc({$tAuditLoadFailed})}</td></tr>`;
            return;
        }

        const rows = res.data.rows || [];
        if (rows.length === 0) {
            body.innerHTML = `<tr><td colspan="4" class="p-cell-muted" style="padding:14px;">\${esc({$tAuditEmpty})}</td></tr>`;
        } else {
            body.innerHTML = rows.map(r => `
                <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
                    <td style="padding:8px;">\${escapeHtml(r.action)}</td>
                    <td style="padding:8px;">\${r.object_type ? escapeHtml(r.object_type) + (r.object_id ? ' #' + escapeHtml(r.object_id) : '') : '-'}</td>
                    <td style="padding:8px;"><span class="p-badge status-\${r.result === 'success' ? 'active' : 'suspended'}">\${escapeHtml(r.result)}</span></td>
                    <td style="padding:8px;" class="p-cell-muted">\${escapeHtml(r.created_at)}</td>
                </tr>
            `).join('');
        }

        const total = res.data.total || 0;
        const perPage = res.data.per_page || 20;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        auditHasNext = auditPage < totalPages;
        document.getElementById('auditPageInfo').textContent = {$tAuditPageOf}.replace('{page}', auditPage).replace('{total}', totalPages);
        document.getElementById('auditPrevBtn').disabled = auditPage <= 1;
        document.getElementById('auditNextBtn').disabled = !auditHasNext;
    }

    document.getElementById('auditFilterBtn').addEventListener('click', () => { auditPage = 1; loadAuditLog(); });
    document.getElementById('auditPrevBtn').addEventListener('click', () => { if (auditPage > 1) { auditPage--; loadAuditLog(); } });
    document.getElementById('auditNextBtn').addEventListener('click', () => { if (auditHasNext) { auditPage++; loadAuditLog(); } });

    document.getElementById('auditExportBtn').addEventListener('click', async function () {
        const btn = this;
        btn.disabled = true;
        try {
            const qs = new URLSearchParams({
                search: document.getElementById('auditSearch').value.trim(),
                from: document.getElementById('auditFrom').value,
                to: document.getElementById('auditTo').value,
                result: document.getElementById('auditResult').value,
                action: document.getElementById('auditAction').value.trim(),
            });
            const res = await fetchJSON('/api/user/audit-log/export?' + qs.toString());
            if (!res.success || !res.data || !res.data.csv) {
                toast({$tAuditExportFailed}, 'error');
                return;
            }
            const blob = new Blob(['\uFEFF' + res.data.csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = res.data.filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } finally {
            btn.disabled = false;
        }
    });

    // ============ الـ Workspace ============
    let wsIsOwner = true;
    let wsCanManageWorkspace = true;
    let wsCanManageTeam = true;

    async function loadWorkspace() {
        const res = await fetchJSON('/api/workspace');
        if (!res.success) { toast({$tWorkspaceLoadFailed}, 'error'); return; }

        const w = res.data.workspace;
        wsIsOwner = res.data.is_owner;
        wsCanManageWorkspace = res.data.can_manage_workspace;
        wsCanManageTeam = res.data.can_manage_team;

        document.getElementById('ws_name').value = w.name || '';
        document.getElementById('ws_industry').value = w.industry || '';
        document.getElementById('ws_country').value = w.country_code || '';
        document.getElementById('ws_timezone').value = w.timezone || '';
        document.getElementById('ws_language').value = w.default_language || 'ar';
        if (w.logo_url) {
            const img = document.getElementById('ws_logo_preview');
            img.src = w.logo_url;
            img.style.display = 'inline-block';
        }

        const readOnly = !wsCanManageWorkspace;
        ['ws_name', 'ws_industry', 'ws_country', 'ws_timezone', 'ws_language', 'ws_logo_input'].forEach(id => {
            document.getElementById(id).disabled = readOnly;
        });
        document.getElementById('workspaceSaveBtn').style.display = readOnly ? 'none' : 'inline-block';
        document.getElementById('workspaceReadOnlyNotice').style.display = readOnly ? 'block' : 'none';

        document.getElementById('teamInviteBox').style.display = wsCanManageTeam ? 'block' : 'none';
        document.getElementById('leaveWorkspaceBox').style.display = wsIsOwner ? 'none' : 'block';
    }

    document.getElementById('workspaceForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('workspaceAlert');
        const saveBtn = document.getElementById('workspaceSaveBtn');
        const savingIndicator = document.getElementById('workspaceSavingIndicator');
        alertBox.style.display = 'none';
        document.getElementById('err_ws_name').textContent = '';
        document.getElementById('err_ws_industry').textContent = '';

        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';
        let res;
        try {
            res = await fetchJSON('/api/workspace', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: document.getElementById('ws_name').value.trim(),
                    industry: document.getElementById('ws_industry').value.trim(),
                    country_code: document.getElementById('ws_country').value.trim(),
                    timezone: document.getElementById('ws_timezone').value.trim(),
                    default_language: document.getElementById('ws_language').value,
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) {
            toast({$tWorkspaceSaved}, 'success');
        } else {
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_ws_' + field);
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tWorkspaceSaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('ws_logo_input').addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('logo', file);
        const res = await fetchJSON('/api/workspace/logo', { method: 'POST', body: formData });
        if (res.success) {
            document.getElementById('ws_logo_preview').src = res.data.logo_url;
            document.getElementById('ws_logo_preview').style.display = 'inline-block';
            toast({$tLogoUpdated}, 'success');
        } else {
            toast(res.error || {$tLogoFailed}, 'error');
        }
    });

    // ============ الفريق ============
    async function loadMembers() {
        const box = document.getElementById('membersList');
        const res = await fetchJSON('/api/workspace/members');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tMembersLoadFailed}) + '</p>'; return; }

        box.innerHTML = res.data.members.map(m => {
            const roleControl = (m.role === 'owner' || !wsCanManageTeam || m.is_self)
                ? `<span class="p-badge">\${escapeHtml(m.role)}</span>`
                : `<select class="form-control" style="display:inline-block;width:auto;" data-role-select="\${m.id}">
                    \${['admin','manager','sales','support','viewer'].map(r => `<option value="\${r}" \${r === m.role ? 'selected' : ''}>\${r}</option>`).join('')}
                   </select>`;
            const actions = (m.role === 'owner' || !wsCanManageTeam || m.is_self) ? '' : `
                \${m.status === 'active'
                    ? `<button type="button" class="p-btn outline xs" data-deactivate="\${m.id}">\${esc({$tDeactivateBtnLabel})}</button>`
                    : `<button type="button" class="p-btn outline xs" data-reactivate="\${m.id}">✓</button>`}
                <button type="button" class="p-btn outline xs" data-remove="\${m.id}">\${esc({$tRemoveBtnLabel})}</button>
            `;
            return `
                <div class="p-kv" style="align-items:center;">
                    <span class="k">\${escapeHtml(m.name)} <span class="p-cell-muted" style="font-size:12px;">\${escapeHtml(m.email)}</span>
                        \${m.status !== 'active' ? '<span class="p-badge status-suspended" style="margin-inline-start:6px;">' + escapeHtml(m.status) + '</span>' : ''}
                    </span>
                    <span class="v" style="display:flex;gap:8px;align-items:center;">\${roleControl} \${actions}</span>
                </div>
            `;
        }).join('');

        box.querySelectorAll('[data-role-select]').forEach(sel => {
            sel.addEventListener('change', async function () {
                const id = this.getAttribute('data-role-select');
                const res = await fetchJSON('/api/workspace/members/' + id + '/role', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ role: this.value }),
                });
                if (res.success) { toast({$tRoleChanged}, 'success'); loadMembers(); }
                else { toast(res.error || {$tRoleChangeFailed}, 'error'); loadMembers(); }
            });
        });
        box.querySelectorAll('[data-deactivate]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tDeactivateMemberConfirm})) return;
                const id = this.getAttribute('data-deactivate');
                const res = await fetchJSON('/api/workspace/members/' + id + '/deactivate', { method: 'POST' });
                if (res.success) { toast({$tMemberDeactivated}, 'success'); loadMembers(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
        box.querySelectorAll('[data-reactivate]').forEach(btn => {
            btn.addEventListener('click', async function () {
                const id = this.getAttribute('data-reactivate');
                const res = await fetchJSON('/api/workspace/members/' + id + '/reactivate', { method: 'POST' });
                if (res.success) { toast({$tMemberReactivated}, 'success'); loadMembers(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
        box.querySelectorAll('[data-remove]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tRemoveMemberConfirm})) return;
                const id = this.getAttribute('data-remove');
                const res = await fetchJSON('/api/workspace/members/' + id, { method: 'DELETE' });
                if (res.success) { toast({$tMemberRemoved}, 'success'); loadMembers(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
    }

    async function loadPendingInvites() {
        const box = document.getElementById('pendingInvitesList');
        const res = await fetchJSON('/api/workspace/invites');
        if (!res.success) { box.style.display = 'none'; return; }
        const invites = res.data.invites || [];
        if (invites.length === 0) { box.innerHTML = '<p>' + esc({$tNoInvites}) + '</p>'; return; }
        box.innerHTML = invites.map(inv => `
            <div class="p-kv">
                <span class="k">\${escapeHtml(inv.email)} <span class="p-badge" style="margin-inline-start:6px;">\${escapeHtml(inv.role)}</span></span>
                <span class="v">
                    <span class="p-badge status-\${inv.status === 'pending' ? 'pending' : 'suspended'}">\${escapeHtml(inv.status)}</span>
                    <button type="button" class="p-btn outline xs" data-revoke-invite="\${inv.id}">✕</button>
                </span>
            </div>
        `).join('');
        box.querySelectorAll('[data-revoke-invite]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tRevokeInviteConfirm})) return;
                const id = this.getAttribute('data-revoke-invite');
                const res = await fetchJSON('/api/workspace/invites/' + id + '/revoke', { method: 'POST' });
                if (res.success) { toast({$tInviteRevoked}, 'success'); loadPendingInvites(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
    }

    document.getElementById('sendInviteBtn').addEventListener('click', async function () {
        const email = document.getElementById('invite_email').value.trim();
        const role = document.getElementById('invite_role').value;
        const errEl = document.getElementById('err_invite_email');
        errEl.textContent = '';

        const btn = this;
        btn.disabled = true;
        let res;
        try {
            res = await fetchJSON('/api/workspace/invite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, role }),
            });
        } finally {
            btn.disabled = false;
        }

        if (res.success) {
            document.getElementById('invite_email').value = '';
            const resultBox = document.getElementById('inviteResultBox');
            resultBox.style.display = 'block';
            resultBox.innerHTML = escapeHtml(res.message || {$tInviteSent}) +
                (res.data.email_sent ? '' : `<br><code style="direction:ltr;display:inline-block;margin-top:6px;">\${escapeHtml(res.data.invite_url)}</code> <button type="button" class="p-btn outline xs" id="copyInviteLinkBtn">\${esc({$tCopyLink})}</button>`);
            if (!res.data.email_sent) {
                document.getElementById('copyInviteLinkBtn').addEventListener('click', () => {
                    navigator.clipboard.writeText(res.data.invite_url).then(() => toast({$tLinkCopied}, 'success'));
                });
            }
            toast(res.message || {$tInviteSent}, 'success');
            loadPendingInvites();
        } else {
            if (res.details && res.details.email) {
                errEl.textContent = Array.isArray(res.details.email) ? res.details.email[0] : res.details.email;
            } else {
                toast(res.error || {$tInviteFailed}, 'error');
            }
        }
    });

    // ============ اللغة والمنطقة ============
    document.getElementById('generalForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('generalAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/user/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                language: document.getElementById('language').value,
                timezone: document.getElementById('timezone').value,
            }),
        });

        if (res.success) {
            toast({$tSettingsSaved}, 'success');
        } else {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('leaveWorkspaceForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (!confirm({$tLeaveWorkspaceConfirm})) return;

        const alertBox = document.getElementById('leaveWorkspaceAlert');
        const errEl = document.getElementById('err_leave_password');
        alertBox.style.display = 'none';
        errEl.textContent = '';

        const res = await fetchJSON('/api/workspace/leave', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: document.getElementById('leave_password').value }),
        });

        if (res.success) {
            toast({$tLeftWorkspace}, 'success');
            setTimeout(() => window.location.href = '/login', 1200);
        } else {
            if (res.details && res.details.current_password) {
                errEl.textContent = Array.isArray(res.details.current_password) ? res.details.current_password[0] : res.details.current_password;
            }
            alertBox.textContent = res.error || {$tLeaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('deactivateForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (!confirm({$tDeactivateConfirm})) return;

        const alertBox = document.getElementById('deactivateAlert');
        const errEl = document.getElementById('err_deactivate_password');
        alertBox.style.display = 'none';
        errEl.textContent = '';

        const res = await fetchJSON('/api/user/deactivate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: document.getElementById('deactivate_password').value }),
        });

        if (res.success) {
            toast({$tAccountDeactivated}, 'success');
            setTimeout(() => window.location.href = '/login', 1200);
        } else {
            if (res.details && res.details.current_password) {
                errEl.textContent = Array.isArray(res.details.current_password) ? res.details.current_password[0] : res.details.current_password;
            }
            alertBox.textContent = res.error || {$tDeactivateFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('deleteAccountForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (!confirm({$tDeleteConfirm1})) return;
        if (!confirm({$tDeleteConfirm2})) return;

        const alertBox = document.getElementById('deleteAccountAlert');
        document.getElementById('err_delete_password').textContent = '';
        document.getElementById('err_delete_confirm_email').textContent = '';
        alertBox.style.display = 'none';

        const payload = {
            current_password: document.getElementById('delete_password').value,
            confirm_email: document.getElementById('delete_confirm_email').value,
        };

        let res = await fetchJSON('/api/user/account', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        // Profile Center Phase 3: عندك اشتراك مدفوع فعّال - نوضّح للمستخدم
        // إن الحذف مش هيلغي الاشتراك تلقائيًا عند مزوّد الدفع، ونطلب تأكيد
        // واعي إضافي قبل ما نكمل فعليًا.
        if (!res.success && res.details && res.details.subscription) {
            if (!confirm(res.error + '\\n\\n' + {$tDeleteConfirmSubscription})) return;
            payload.acknowledge_active_subscription = '1';
            res = await fetchJSON('/api/user/account', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
        }

        if (res.success) {
            toast({$tAccountDeleted}, 'success');
            setTimeout(() => window.location.href = '/login', 1200);
        } else {
            const fieldMap = { current_password: 'delete_password', confirm_email: 'delete_confirm_email' };
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_' + (fieldMap[field] || field));
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tDeleteFailed};
            alertBox.style.display = 'block';
        }
    });

    // ============ تصدير بيانات الحساب (Profile Center Phase 9) ============
    window.requestDataExport = async function () {
        const alertBox = document.getElementById('exportAlert');
        alertBox.style.display = 'none';
        const res = await fetchJSON('/api/user/data-export', { method: 'POST' });
        if (res.success) {
            toast({$tExportRequested}, 'success');
            loadDataExports();
        } else {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
        }
    };

    window.loadDataExports = async function () {
        const list = document.getElementById('exportsList');
        const res = await fetchJSON('/api/user/data-export');
        if (!res.success || !res.data.exports || res.data.exports.length === 0) {
            list.innerHTML = '';
            return;
        }
        const statusLabels = {
            requested: {$tExportRequestedStatus},
            processing: {$tExportProcessingStatus},
            ready: {$tExportReadyStatus},
            failed: {$tExportFailedStatus},
        };
        list.innerHTML = res.data.exports.map(function (e) {
            const label = statusLabels[e.status] || e.status;
            const downloadBtn = e.status === 'ready'
                ? '<a class="p-btn outline" href="/profile/data-export/download/' + e.id + '">' + {$tDownload} + '</a>'
                : '';
            return '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;">'
                + '<div><span>' + label + '</span>'
                + '<div class="p-cell-muted" style="font-size:12px;">' + (e.requested_at || '') + '</div></div>'
                + downloadBtn
                + '</div>';
        }).join('');
    };

    loadNotifications();
    loadSessions();
    loadApiKeys();
    loadBilling();
    loadAuditLog();
    loadWorkspace();
    loadMembers();
    loadPendingInvites();
    loadDataExports();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_settings', $this->tr('sidebar.settings'), $this->tr('settings.page_subtitle'), $body, $script);
    }

    /** يبني HTML الصورة الشخصية الحالية (صورة حقيقية لو موجودة، وإلا حرف أول الاسم) */
    private function avatarInnerHtml(string $avatarUrl, string $initials): string
    {
        if ($avatarUrl !== '') {
            return '<img src="' . $avatarUrl . '" alt="الصورة الشخصية" style="width:100%;height:100%;object-fit:cover;">';
        }
        return $initials;
    }

    /** POST /api/user/avatar - رفع/تغيير الصورة الشخصية */
    public function uploadAvatar(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (empty($_FILES['avatar'])) {
            return $this->error('لم يتم اختيار أي صورة', 422);
        }

        $handler = new AvatarUploadHandler();
        $result = $handler->upload($_FILES['avatar'], (int) $user->getAttribute('id'), $user->getAttribute('avatar_url'));

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر رفع الصورة', 422);
        }

        $user->setAttribute('avatar_url', $result['url']);
        if ($user->save() === false) {
            return $this->error('اترفعت الصورة بس تعذر حفظ الرابط', 500);
        }

        return $this->success(['avatar_url' => $result['url']], 'تم تحديث الصورة الشخصية');
    }

    /** يرجّع ' selected' لو القيمتين متطابقتين، مفيدة لبناء HTML بسيط */
    private function selected(string $current, string $option): string
    {
        return $current === $option ? ' selected' : '';
    }

    private static function isoCountries(): array
    {
        static $countries = null;
        if ($countries !== null) {
            return $countries;
        }
        $countries = [
            'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra',
            'AO' => 'Angola', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia',
            'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BH' => 'Bahrain', 'BD' => 'Bangladesh',
            'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin',
            'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana',
            'BR' => 'Brazil', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso',
            'BI' => 'Burundi', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada',
            'CV' => 'Cape Verde', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile',
            'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo',
            'CR' => 'Costa Rica', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus',
            'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DO' => 'Dominican Republic',
            'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'EE' => 'Estonia',
            'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France',
            'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany',
            'GH' => 'Ghana', 'GR' => 'Greece', 'GT' => 'Guatemala', 'GN' => 'Guinea',
            'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary',
            'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran',
            'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy',
            'CI' => 'Ivory Coast', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan',
            'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan',
            'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho',
            'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania',
            'LU' => 'Luxembourg', 'MO' => 'Macau', 'MG' => 'Madagascar', 'MW' => 'Malawi',
            'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta',
            'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MX' => 'Mexico', 'MD' => 'Moldova',
            'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco',
            'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NP' => 'Nepal',
            'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger',
            'NG' => 'Nigeria', 'MK' => 'North Macedonia', 'NO' => 'Norway', 'OM' => 'Oman',
            'PK' => 'Pakistan', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay',
            'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal',
            'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda',
            'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles',
            'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
            'SO' => 'Somalia', 'ZA' => 'South Africa', 'KR' => 'South Korea', 'SS' => 'South Sudan',
            'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname',
            'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan',
            'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TG' => 'Togo',
            'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan',
            'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
            'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela',
            'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        ];
        return $countries;
    }

    /**
     * قائمة مرجعية ISO 4217 (العملات الأكثر استخدامًا عالميًا وإقليميًا).
     * هذه Currency Preference خاصة بعرض الملف الشخصي فقط، ولا تُغيّر
     * أو تتقاطع مع عملة الفوترة/الاشتراك في subscriptions/invoices.
     * @return array<string,string>
     */
    private static function isoCurrencies(): array
    {
        return [
            'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'EGP' => 'Egyptian Pound',
            'SAR' => 'Saudi Riyal', 'AED' => 'UAE Dirham', 'QAR' => 'Qatari Riyal', 'KWD' => 'Kuwaiti Dinar',
            'BHD' => 'Bahraini Dinar', 'OMR' => 'Omani Rial', 'JOD' => 'Jordanian Dinar', 'LBP' => 'Lebanese Pound',
            'IQD' => 'Iraqi Dinar', 'MAD' => 'Moroccan Dirham', 'DZD' => 'Algerian Dinar', 'TND' => 'Tunisian Dinar',
            'LYD' => 'Libyan Dinar', 'TRY' => 'Turkish Lira', 'ILS' => 'Israeli New Shekel', 'JPY' => 'Japanese Yen',
            'CNY' => 'Chinese Yuan', 'HKD' => 'Hong Kong Dollar', 'SGD' => 'Singapore Dollar', 'KRW' => 'South Korean Won',
            'INR' => 'Indian Rupee', 'PKR' => 'Pakistani Rupee', 'BDT' => 'Bangladeshi Taka', 'IDR' => 'Indonesian Rupiah',
            'MYR' => 'Malaysian Ringgit', 'THB' => 'Thai Baht', 'VND' => 'Vietnamese Dong', 'PHP' => 'Philippine Peso',
            'AUD' => 'Australian Dollar', 'NZD' => 'New Zealand Dollar', 'CAD' => 'Canadian Dollar', 'CHF' => 'Swiss Franc',
            'SEK' => 'Swedish Krona', 'NOK' => 'Norwegian Krone', 'DKK' => 'Danish Krone', 'PLN' => 'Polish Zloty',
            'CZK' => 'Czech Koruna', 'HUF' => 'Hungarian Forint', 'RON' => 'Romanian Leu', 'RUB' => 'Russian Ruble',
            'UAH' => 'Ukrainian Hryvnia', 'ZAR' => 'South African Rand', 'NGN' => 'Nigerian Naira', 'KES' => 'Kenyan Shilling',
            'GHS' => 'Ghanaian Cedi', 'ETB' => 'Ethiopian Birr', 'BRL' => 'Brazilian Real', 'MXN' => 'Mexican Peso',
            'ARS' => 'Argentine Peso', 'CLP' => 'Chilean Peso', 'COP' => 'Colombian Peso', 'PEN' => 'Peruvian Sol',
        ];
    }

    /**
     * قائمة كاملة IANA Time Zones من PHP نفسه (DateTimeZone) - مش قائمة
     * مكتوبة يدويًا، فتبقى دايمًا مطابقة لبيانات tzdata الفعلية ومحدّثة
     * تلقائيًا مع أي تحديث PHP/OS، وتراعي Daylight Saving Time تلقائيًا
     * لأنها بتستخدم identifier حقيقي (زي Africa/Cairo) مش UTC+2 ثابت.
     * @return string[]
     */
    private static function ianaTimezones(): array
    {
        return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
    }


    public function getSettings(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success([
            'language' => $user->getAttribute('language'),
            'timezone' => $user->getAttribute('timezone'),
        ]);
    }

    /** POST /profile/settings و PUT /api/user/settings */
    public function updateSettings(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if ($this->has('language') && $this->get('language') !== '' && !in_array($this->get('language'), UI_SUPPORTED_LANGUAGES, true)) {
            return $this->error('لغة غير مدعومة', 422, ['language' => ['يجب اختيار لغة من القائمة المتاحة فعليًا']]);
        }

        // IANA timezone حقيقي بدل قائمة يدوية ممكن تبقى قديمة - نتحقق
        // منها عن طريق PHP نفسه (DateTimeZone).
        if ($this->has('timezone') && $this->get('timezone') !== '' && !in_array($this->get('timezone'), self::ianaTimezones(), true)) {
            return $this->error('منطقة زمنية غير صحيحة', 422, ['timezone' => ['يجب اختيار IANA Timezone صحيحة']]);
        }

        foreach (['language', 'timezone'] as $field) {
            if ($this->has($field)) {
                $user->setAttribute($field, $this->get($field));
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث الإعدادات', 500);
        }

        return $this->success([], 'تم تحديث الإعدادات');
    }

    /**
     * DELETE /api/user/oauth/{provider} - فصل حساب OAuth مربوط بالمستخدم
     * الحالي فقط (مفيش أي user_id بييجي من الـFrontend، بنستخدم المستخدم
     * الحالي من الـSession زي باقي الكونترولر). لا يوجد Access/Refresh
     * Token مخزّن في oauth_accounts أصلاً، فمفيش حاجة حساسة نحذفها غير
     * الربط نفسه.
     */
    public function disconnectOAuth(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $provider = (string) ($params['provider'] ?? '');
        if (!in_array($provider, ['google', 'apple', 'facebook', 'microsoft'], true)) {
            return $this->error('منصة غير معروفة', 422);
        }

        $this->db->exec(
            'DELETE FROM oauth_accounts WHERE user_id = ? AND provider = ?',
            [(int) $user->getAttribute('id'), $provider]
        );

        return $this->success([], 'تم فصل الحساب');
    }

    /**
     * POST /api/user/2fa/setup - الخطوة الأولى: توليد secret جديد وحفظه
     * (لكن two_factor_enabled يفضل 0 لحد ما يتأكد بكود صحيح في enable()).
     * ده بيمنع حالة إن مستخدم يولّد secret وينساه من غير ما يفعّل 2FA
     * فعليًا، ومحدش يقدر يقفل حسابه بالغلط.
     */
    public function setupTwoFactor(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $secret = AuthController::generateTotpSecret();
        $user->setAttribute('two_factor_secret', $secret);
        $user->setAttribute('two_factor_enabled', 0);
        if ($user->save() === false) {
            return $this->error('تعذر بدء إعداد التحقق بخطوتين', 500);
        }

        $issuer = 'Tourfecto';
        $email = (string) $user->getAttribute('email');
        $otpauthUri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';

        return $this->success(['secret' => $secret, 'otpauth_uri' => $otpauthUri]);
    }

    /**
     * POST /api/user/2fa/enable - الخطوة الثانية: تأكيد إن المستخدم فعلًا
     * ضبط الـsecret صح في تطبيق Authenticator بتاعه (بإدخال كود حقيقي)،
     * قبل ما نفعّل 2FA فعليًا على تسجيل الدخول. بعد كده بنولّد Recovery
     * Codes وبنعرضهم مرة واحدة بس (نص عادي) - بعدها بيتخزنوا مُشفّرين فقط.
     */
    public function enableTwoFactor(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $secret = (string) $user->getAttribute('two_factor_secret');
        if ($secret === '') {
            return $this->error('لازم تبدأ الإعداد أولًا', 422);
        }

        if (!$this->validate(['code' => 'required'])) {
            return $this->error('أدخل كود التحقق', 422, $this->getErrors());
        }

        if (!AuthController::verifyTotpCode($secret, (string) $this->get('code'))) {
            return $this->error('كود التحقق غير صحيح', 401);
        }

        $recoveryCodes = AuthController::generateRecoveryCodes();
        $hashedCodes = array_map(static fn ($code) => password_hash($code, PASSWORD_DEFAULT), $recoveryCodes);

        $user->setAttribute('two_factor_enabled', 1);
        $user->setAttribute('two_factor_recovery_codes', json_encode($hashedCodes));
        $user->setAttribute('two_factor_confirmed_at', date('Y-m-d H:i:s'));
        if ($user->save() === false) {
            return $this->error('تعذر تفعيل التحقق بخطوتين', 500);
        }

        // Recovery Codes بتترجع نص عادي هنا مرة واحدة بس - مش هيبقى فيه
        // طريقة تانية تسترجعها تاني، بالظبط زي أي SaaS تاني.
        return $this->success(['recovery_codes' => $recoveryCodes], 'تم تفعيل التحقق بخطوتين');
    }

    /** POST /api/user/2fa/disable - لازم كلمة المرور، مش بس زرار */
    public function disableTwoFactor(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate(['password' => 'required'])) {
            return $this->error('يجب إدخال كلمة المرور', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('password'))) {
            return $this->error('كلمة المرور غير صحيحة', 401);
        }

        $user->setAttribute('two_factor_enabled', 0);
        $user->setAttribute('two_factor_secret', null);
        $user->setAttribute('two_factor_recovery_codes', null);
        $user->setAttribute('two_factor_confirmed_at', null);
        if ($user->save() === false) {
            return $this->error('تعذر إلغاء التحقق بخطوتين', 500);
        }

        return $this->success([], 'تم إلغاء التحقق بخطوتين');
    }

    /**
     * POST /api/user/2fa/recovery-codes/regenerate
     *
     * إعادة توليد أكواد الطوارئ (Recovery Codes) مع إبطال الدفعة القديمة
     * بالكامل - نفس سلوك GitHub/Stripe. ليه حد يطلبها؟ لو المستخدم شك إن
     * الأكواد القديمة اتكشفت (جهاز مسروق/مخترق مثلًا) أو فقدها، يعمل
     * توليد جديد يحل محل القديم فورًا فلا تصلح أكواد قديمة تاني.
     *
     * المتطلبات (أقوى من مجرد زر): كلمة المرور الحالية + كود TOTP صالح
     * (أو Recovery Code قديم صالح - عشان لو فقد التطبيق نفسه برضه يقدر
     * يسترجع). من غير كده أي شخص عنده جلسة مسروقة يقدر يبطل أكواد
     * طوارئ صاحبها وياخد أكواد جديدة بدلها.
     */
    public function regenerateRecoveryCodes(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!(bool) $user->getAttribute('two_factor_enabled')) {
            return $this->error('التحقق بخطوتين غير مُفعّل أصلًا', 422);
        }

        if (!$this->validate(['password' => 'required'])) {
            return $this->error('يجب إدخال كلمة المرور', 422, $this->getErrors());
        }
        if (!$user->verifyPassword((string) $this->get('password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'recovery_codes_regenerate_failed', 'failed');
            return $this->error('كلمة المرور غير صحيحة', 401);
        }

        // تحقق ثانٍ بكود TOTP صالح أو Recovery Code قديم صالح (بدل ما
        // نعتمد على كلمة المرور لوحدها).
        $verified = false;
        $secret = (string) $user->getAttribute('two_factor_secret');
        if ($secret !== '' && AuthController::verifyTotpCode($secret, (string) $this->get('code', ''))) {
            $verified = true;
        } else {
            $oldJson = $user->getAttribute('two_factor_recovery_codes');
            $oldCodes = $oldJson ? (json_decode((string) $oldJson, true) ?: []) : [];
            if (TotpService::verifyRecoveryCode($oldCodes, (string) $this->get('code', '')) !== null) {
                $verified = true;
            }
        }
        if (!$verified) {
            AuditLog::record((int) $user->getAttribute('id'), 'recovery_codes_regenerate_failed', 'failed');
            return $this->error('كود التحقق غير صحيح - أدخل كود التطبيق أو أحد أكواد الطوارئ القديمة', 401);
        }

        $newCodes = AuthController::generateRecoveryCodes();
        $hashed = array_map(static fn ($code) => password_hash($code, PASSWORD_DEFAULT), $newCodes);

        $user->setAttribute('two_factor_recovery_codes', json_encode($hashed));
        if ($user->save() === false) {
            return $this->error('تعذر توليد أكواد طوارئ جديدة', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'recovery_codes_regenerated', 'success', 'two_factor', null, ['count' => count($newCodes)]);

        return $this->success(['recovery_codes' => $newCodes], 'تم توليد أكواد طوارئ جديدة - الأكواد القديمة أُبطلت نهائيًا');
    }

    /**
     * POST /api/user/data-export
     * Profile Center Phase 9: طلب تصدير بيانات الحساب (Profile + Login
     * History + Connected Accounts + Sessions metadata - مش كل بيانات
     * العمل زي المواقع/الاشتراكات/CRM). بيتنفّذ Async عن طريق الـQueue
     * الموجود بالفعل، مش هنا مباشرة.
     */
    public function requestDataExport(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) $user->getAttribute('id');

        // منع تكديس طلبات كتير مع بعض - لو فيه طلب لسه requested/processing، منرجعش نعمل واحد جديد
        $pending = $this->db->query(
            "SELECT id FROM data_export_requests WHERE user_id = ? AND status IN ('requested', 'processing') LIMIT 1",
            [$userId]
        );
        if (!empty($pending)) {
            return $this->error('عندك طلب تصدير بيانات قيد التنفيذ بالفعل - استنى لحد ما يخلص', 409);
        }

        $requestId = $this->db->query(
            "INSERT INTO data_export_requests (user_id, status, requested_at) VALUES (?, 'requested', NOW())",
            [$userId]
        );

        if (!$requestId) {
            return $this->error('تعذر إنشاء طلب التصدير', 500);
        }

        try {
            $queue = new QueueManager();
            $queue->push('ExportUserDataJob', ['export_request_id' => $requestId, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            // لو الـQueue نفسه فشل لأي سبب، سجّل الطلب فاشل فورًا بدل ما
            // يفضل عالق على "requested" للأبد من غير أي تفسير للمستخدم
            $this->db->exec("UPDATE data_export_requests SET status = 'failed', error_message = ? WHERE id = ?", [substr($e->getMessage(), 0, 500), $requestId]);
            return $this->error('تعذر جدولة طلب التصدير', 500);
        }

        return $this->success(['request_id' => $requestId], 'تم استلام طلب التصدير - هيوصلك إشعار لما يخلص');
    }

    /** GET /api/user/data-export - طلبات المستخدم الحالي فقط */
    public function listDataExports(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $rows = $this->db->query(
            'SELECT id, status, requested_at, completed_at, expires_at, error_message
             FROM data_export_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 10',
            [(int) $user->getAttribute('id')]
        );

        return $this->success(['exports' => $rows ?: []]);
    }

    /**
     * GET /profile/data-export/download/{id}
     * تحميل موثّق - مفيش رابط عام مباشر للملف خالص (الملف أصلًا بره
     * public_html في TOURFECTO_STORAGE). لازم يتحقق من ملكية الطلب
     * وإن حالته "ready" وإنه لسه ما انتهتش صلاحيته قبل ما يسمح بالتحميل.
     */
    public function downloadDataExport(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            header('Location: /login');
            exit;
        }

        $requestId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query(
            "SELECT * FROM data_export_requests WHERE id = ? AND user_id = ? LIMIT 1",
            [$requestId, (int) $user->getAttribute('id')]
        );

        if (empty($rows)) {
            http_response_code(404);
            echo 'طلب التصدير غير موجود';
            exit;
        }

        $export = $rows[0];
        if ($export['status'] !== 'ready' || empty($export['file_path']) || !is_file($export['file_path'])) {
            http_response_code(404);
            echo 'الملف غير جاهز أو منتهي الصلاحية';
            exit;
        }

        if (!empty($export['expires_at']) && strtotime($export['expires_at']) < time()) {
            http_response_code(410);
            echo 'انتهت صلاحية رابط التحميل';
            exit;
        }

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="tourfecto-data-export.json"');
        header('Content-Length: ' . filesize($export['file_path']));
        readfile($export['file_path']);
        exit;
    }

    /** GET /profile/security */
    public function showSecurity(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile/security'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success(['section' => 'security']);
        }

        // توحيد: نفس فورم تغيير كلمة المرور موجود في تبويب "الأمان"
        // بصفحة /profile/settings بالظبط.
        header('Location: /profile/settings');
        exit;
    }

    /** POST /profile/security */
    public function updateSecurity(array $params = []): array
    {
        return $this->updatePassword($params);
    }

    /** GET /profile/api */
    public function showAPI(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile/api'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success(['api_token' => $user->getAttribute('api_token')]);
        }

        // توحيد: نفس المفتاح وزرار النسخ (وكمان توليد مفتاح جديد) موجودين
        // في تبويب "API" بصفحة /profile/settings.
        header('Location: /profile/settings');
        exit;
    }

    /** GET /api/user/sessions - قائمة الجلسات النشطة (أجهزة سجّلت دخول فعليًا) */
    public function listSessions(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $currentId = $_SESSION['current_refresh_token_id'] ?? null;
        $tokenModel = new RefreshToken();
        $now = time();
        $sessions = [];

        foreach ($tokenModel->where(['user_id' => (int) $user->getAttribute('id')]) as $token) {
            if ($token->getAttribute('revoked_at')) {
                continue; // ملغي - متعرضش في القائمة
            }
            if (strtotime((string) $token->getAttribute('expires_at')) <= $now) {
                continue; // منتهي فعليًا - مش جلسة نشطة
            }

            $ua = (string) ($token->getAttribute('user_agent') ?? '');
            $ip = (string) ($token->getAttribute('ip_address') ?? '');

            $sessions[] = [
                'id' => (int) $token->getAttribute('id'),
                'device_name' => $token->getAttribute('device_name') ?: 'جهاز غير معروف',
                'browser' => $this->guessBrowser($ua),
                'os' => $this->guessOS($ua),
                'ip_masked' => $this->maskIp($ip),
                'last_active' => $token->getAttribute('last_used_at'),
                'created_at' => $token->getAttribute('created_at'),
                'is_current' => $currentId !== null && (int) $token->getAttribute('id') === (int) $currentId,
            ];
        }

        // الأحدث نشاطًا الأول
        usort($sessions, fn ($a, $b) => strcmp((string) $b['last_active'], (string) $a['last_active']));

        return $this->success(['sessions' => $sessions]);
    }

    /** POST /api/user/sessions/{id}/logout - تسجيل خروج من جهاز واحد بعينه */
    public function logoutSession(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $id = (int) ($params['id'] ?? 0);
        $tokenModel = new RefreshToken();
        $token = $tokenModel->find($id);

        // لازم يكون التوكن موجود، ومملوك فعليًا للمستخدم الحالي - وإلا
        // أي مستخدم يقدر يسجّل خروج جلسات مستخدمين تانيين لمجرد تخمين ID.
        if (!$token || (int) $token->getAttribute('user_id') !== (int) $user->getAttribute('id')) {
            return $this->error('الجلسة غير موجودة', 404);
        }

        $token->revoke();

        AuditLog::record((int) $user->getAttribute('id'), 'session_logged_out', 'success', 'session', (string) $id);

        return $this->success([], 'تم تسجيل الخروج من هذا الجهاز');
    }

    /** POST /api/user/sessions/logout-others - تسجيل خروج من كل الأجهزة عدا الحالي */
    public function logoutOtherSessions(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $currentId = $_SESSION['current_refresh_token_id'] ?? null;
        $tokenModel = new RefreshToken();

        foreach ($tokenModel->where(['user_id' => (int) $user->getAttribute('id')]) as $token) {
            if ($token->getAttribute('revoked_at')) {
                continue;
            }
            if ($currentId !== null && (int) $token->getAttribute('id') === (int) $currentId) {
                continue; // متمسّش الجلسة الحالية
            }
            $token->revoke();
        }

        AuditLog::record((int) $user->getAttribute('id'), 'sessions_logged_out_others');

        return $this->success([], 'تم تسجيل الخروج من كل الأجهزة الأخرى');
    }

    /** تخمين مبسّط لاسم المتصفح من الـ User-Agent - عرض فقط، مش قرار أمني */
    private function guessBrowser(string $ua): string
    {
        if ($ua === '') {
            return '-';
        }
        if (stripos($ua, 'Edg/') !== false) {
            return 'Edge';
        }
        if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
            return 'Opera';
        }
        if (stripos($ua, 'Chrome/') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'CriOS') !== false) {
            return 'Chrome (iOS)';
        }
        if (stripos($ua, 'Firefox/') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Safari/') !== false) {
            return 'Safari';
        }
        return 'غير معروف';
    }

    /** تخمين مبسّط لنظام التشغيل من الـ User-Agent - عرض فقط، مش قرار أمني */
    private function guessOS(string $ua): string
    {
        if ($ua === '') {
            return '-';
        }
        if (stripos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            return 'iOS';
        }
        if (stripos($ua, 'Mac OS X') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'Android') !== false) {
            return 'Android';
        }
        if (stripos($ua, 'Linux') !== false) {
            return 'Linux';
        }
        return 'غير معروف';
    }

    /** يخفي جزء من الـ IP قبل عرضه (مثال: 41.238.xx.xx) - مش محتاج IP كامل في الواجهة */
    private function maskIp(string $ip): string
    {
        if ($ip === '') {
            return '-';
        }
        if (strpos($ip, '.') !== false) { // IPv4
            $parts = explode('.', $ip);
            return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.xx.xx" : 'xx.xx.xx.xx';
        }
        if (strpos($ip, ':') !== false) { // IPv6
            $parts = explode(':', $ip);
            return (count($parts) >= 2 ? $parts[0] . ':' . $parts[1] : 'xx') . ':xxxx:xxxx';
        }
        return 'xx.xx.xx.xx';
    }

    /** GET /api/user/api-keys - قائمة مفاتيح API الشخصية للمستخدم الحالي */
    public function listApiKeys(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $keyModel = new UserApiKey();
        $keys = array_map(
            fn ($key) => $key->toSafeArray(),
            $keyModel->where(['user_id' => (int) $user->getAttribute('id')], [], 0)
        );

        usort($keys, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $this->success(['keys' => $keys]);
    }

    /** POST /api/user/api-keys - إنشاء مفتاح جديد (المفتاح الخام بيترجع مرة واحدة بس هنا) */
    public function createApiKey(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate(['name' => 'required|max:120'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        // صلاحية اختيارية (بالأيام): لو المستخدم حددها، المفتاح ينتهي
        // بعد المدة دي تلقائيًا - نفس Vercel/Stripe. مش إجباري أبدًا:
        // لو مترسلتش، المفتاح يفضل صالح لغاية ما يُلغى يدويًا.
        $expiresInDays = (int) ($this->get('expires_in_days', 0));
        if ($expiresInDays < 0 || $expiresInDays > 365) {
            return $this->error('فترة الصلاحية لازم تكون بين 1 و365 يوم، أو متترسلش خالص للمفتاح الدائم', 422);
        }

        // حد أقصى معقول لعدد المفاتيح الفعّالة لكل مستخدم - يمنع إنشاء
        // آلاف المفاتيح بالخطأ أو بسكريبت من غير ما يفيد أي استخدام حقيقي.
        $keyModel = new UserApiKey();
        $activeCount = count(array_filter(
            $keyModel->where(['user_id' => (int) $user->getAttribute('id')], [], 0),
            fn ($k) => !$k->getAttribute('revoked_at')
        ));
        if ($activeCount >= 10) {
            return $this->error('وصلت للحد الأقصى (10 مفاتيح فعّالة) - ألغِ مفتاح قديم أولًا', 422);
        }

        $name = trim(strip_tags((string) $this->get('name')));
        $result = UserApiKey::generateFor((int) $user->getAttribute('id'), $name, $expiresInDays > 0 ? date('Y-m-d H:i:s', time() + ($expiresInDays * 86400)) : null);

        AuditLog::record((int) $user->getAttribute('id'), 'api_key_created', 'success', 'api_key', (string) $result['model']->getAttribute('id'));

        return $this->success([
            'key' => $result['model']->toSafeArray(),
            // المفتاح الخام الوحيد اللي هيترجع في حياة الطلب ده - الواجهة
            // لازم تعرضه مرة واحدة وتقول للمستخدم يحفظه فورًا.
            'raw_key' => $result['raw_key'],
        ], 'تم إنشاء المفتاح بنجاح - احفظه الآن، لن يظهر كاملًا مرة أخرى');
    }

    /** POST /api/user/api-keys/{id}/revoke */
    public function revokeApiKey(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $id = (int) ($params['id'] ?? 0);
        $key = (new UserApiKey())->find($id);

        // لازم يكون المفتاح موجود ومملوك فعليًا للمستخدم الحالي - نفس
        // مبدأ logoutSession() بالظبط، عشان نمنع IDOR.
        if (!$key || (int) $key->getAttribute('user_id') !== (int) $user->getAttribute('id')) {
            return $this->error('المفتاح غير موجود', 404);
        }

        if ($key->getAttribute('revoked_at')) {
            return $this->success([], 'المفتاح ملغي بالفعل');
        }

        $key->revoke();

        AuditLog::record((int) $user->getAttribute('id'), 'api_key_revoked', 'success', 'api_key', (string) $id);

        return $this->success([], 'تم إلغاء المفتاح');
    }

    /** POST /api/user/deactivate - إيقاف مؤقت وقابل للتراجع (البديل الآمن للحذف النهائي) */
    public function deactivateAccount(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate(['current_password' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('current_password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'account_deactivate_failed', 'failed');
            return $this->error('كلمة المرور غير صحيحة', 401, ['current_password' => ['كلمة المرور غير صحيحة']]);
        }

        $user->setAttribute('status', 'suspended');
        if ($user->save() === false) {
            return $this->error('تعذر إيقاف الحساب', 500);
        }

        // نسجّل خروج من كل الأجهزة والمفاتيح فورًا - إيقاف الحساب لازم
        // يعني توقف فعلي، مش بس علم status متغيّر والجلسات لسه شغالة.
        RefreshToken::revokeAllForUser((int) $user->getAttribute('id'));
        foreach ((new UserApiKey())->where(['user_id' => (int) $user->getAttribute('id')]) as $key) {
            if (!$key->getAttribute('revoked_at')) {
                $key->revoke();
            }
        }

        AuditLog::record((int) $user->getAttribute('id'), 'account_deactivated');

        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['current_refresh_token_id']);

        return $this->success([], 'تم إيقاف الحساب مؤقتًا - تواصل مع الدعم لإعادة تفعيله في أي وقت');
    }

    /** GET /api/user/audit-log - سجل نشاط الحساب (Read-Only) */
    public function listAuditLog(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $page = max(1, (int) $this->get('page', 1));
        $result = AuditLog::listFor(
            (int) $user->getAttribute('id'),
            [
                'search' => (string) $this->get('search', ''),
                'action' => (string) $this->get('action', ''),
                'result' => (string) $this->get('result', ''),
                'from' => (string) $this->get('from', ''),
                'to' => (string) $this->get('to', ''),
            ],
            $page,
            20
        );

        return $this->success([
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => 20,
        ]);
    }

    /**
     * GET /api/user/audit-log/export
     *
     * تصدير سجل النشاط كملف CSV (نفس فلاتر القائمة: search/action/result/
     * from/to). مش بيكشف أي بيانات حساسة - نفس الأعمدة المعروضة في
     * الواجهة بالظبط. التصدير بيحصل مباشرة (بحد أقصى 5000 صف) من غير
     * ما يعدي على الـ Queue عشان الملف صغير مقارنةً بتصدير البيانات
     * الكامل.
     */
    public function exportAuditLog(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $rows = AuditLog::exportFor(
            (int) $user->getAttribute('id'),
            [
                'search' => (string) $this->get('search', ''),
                'action' => (string) $this->get('action', ''),
                'result' => (string) $this->get('result', ''),
                'from' => (string) $this->get('from', ''),
                'to' => (string) $this->get('to', ''),
            ]
        );

        // CSV بسيط يدوي - مفيش أي مكتبة خارجية (قيد السيرفر).
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['time', 'action', 'object_type', 'object_id', 'result', 'ip']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at'] ?? '',
                $row['action'] ?? '',
                $row['object_type'] ?? '',
                $row['object_id'] ?? '',
                $row['result'] ?? '',
                $row['ip_address'] ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $filename = 'tourfecto-audit-log-' . date('Y-m-d-His') . '.csv';

        if ($this->isApiRequest()) {
            // واجهة API: نرجّع الـ CSV كنص مع اسم الملف للمتصفح يبدأ تحميل
            return $this->success(['filename' => $filename, 'csv' => $csv]);
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF" . $csv; // BOM لـ Excel
        exit;
    }

    /**
     * DELETE /api/user/account
     *
     * ⚠️ تحذير مهم من الأوديت: جدول users مرتبط بـ ON DELETE CASCADE مع
     * أكتر من 18 جدول تاني (subscriptions, websites, ai_reports, reviews,
     * chat_messages, api_usage_logs...إلخ - شوف CHANGELOG.md لتفاصيل
     * أكتر). يعني نداء واحد لـ delete() هنا بيمسح كل بيانات المستخدم في
     * المنصة كلها فورًا وبلا رجعة - مش مجرد صف واحد في جدول users.
     * الكود الأصلي (قبل الأوديت ده) كان بينفّذ الحذف ده من غير أي تحقق
     * من كلمة المرور خالص - أي حد لسه Session بتاعته شغالة (حتى لو
     * مسروقة) كان يقدر يمسح الحساب بالكامل بضغطة واحدة. الحد الأدنى
     * المُضاف هنا: التحقق من كلمة المرور + تأكيد مكتوب بالبريد
     * الإلكتروني الحالي، قبل ما ننفّذ أي حاجة. القرار الأشمل (هل نغيّر
     * الحذف ده لـ Soft Delete بدل الاعتماد على CASCADE) قرار منتج/عمل
     * محتاج مراجعتك، مش حاجة غيّرتها من نفسي.
     */
    public function deleteAccount(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate([
            'current_password' => 'required',
            'confirm_email' => 'required|email',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('current_password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'account_delete_failed', 'failed', null, null, ['reason' => 'wrong_password']);
            return $this->error('كلمة المرور غير صحيحة', 401, ['current_password' => ['كلمة المرور غير صحيحة']]);
        }

        $confirmEmail = trim((string) $this->get('confirm_email'));
        if (strcasecmp($confirmEmail, (string) $user->getAttribute('email')) !== 0) {
            AuditLog::record((int) $user->getAttribute('id'), 'account_delete_failed', 'failed', null, null, ['reason' => 'email_mismatch']);
            return $this->error('البريد الإلكتروني للتأكيد غير مطابق', 422, ['confirm_email' => ['اكتب بريدك الإلكتروني الحالي بالضبط للتأكيد']]);
        }

        // Profile Center Phase 3: فحص اشتراك مدفوع فعّال قبل الحذف. الحذف
        // مش هيلغي أي اشتراك فعليًا عند مزوّد الدفع (Stripe/PayPal) - ده
        // منطق منفصل تمامًا وخارج نطاق الحذف نفسه. فبدل ما نمسح ونسيب
        // اشتراك شغال بره النظام من غير ما حد يعرف، لازم المستخدم يلغي
        // الاشتراك بنفسه الأول، أو يأكد بوعي إنه فاهم إن الاشتراك مش
        // هيتلغي تلقائيًا.
        $activeSubscription = $this->db->query(
            "SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' LIMIT 1",
            [(int) $user->getAttribute('id')]
        );
        if (!empty($activeSubscription) && $this->get('acknowledge_active_subscription') !== '1') {
            return $this->error(
                'عندك اشتراك مدفوع فعّال. حذف الحساب مش هيلغي الاشتراك تلقائيًا عند مزوّد الدفع - لازم تلغيه الأول من صفحة الفوترة، أو تأكد إنك فاهم كده وعايز تكمل.',
                409,
                ['subscription' => ['active_subscription_found']]
            );
        }

        $userId = (int) $user->getAttribute('id');

        if (!$user->delete()) {
            return $this->error('تعذر حذف الحساب', 500);
        }

        // نسجّل الحدث ده بعد نجاح الحذف فعليًا - وبتصميم: جدول
        // audit_logs مالوش Foreign Key على users عمدًا (خلافًا لكل
        // الجداول التانية اللي فيها CASCADE) - عشان سجل "الحساب اتحذف"
        // ده بالذات يفضل موجود حتى بعد ما صف المستخدم نفسه يتمسح.
        AuditLog::record($userId, 'account_deleted');

        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['current_refresh_token_id']);

        return $this->success([], 'تم حذف الحساب');
    }
}
