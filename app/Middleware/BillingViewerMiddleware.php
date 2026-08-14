<?php
/**
 * Tourfecto - Billing Viewer Middleware
 * صلاحية "اطّلاع فقط" على الفوترة - لموظفي الدعم/المبيعات اللي محتاجين
 * يشوفوا حالة اشتراك عميل أو إحصائيات، من غير ما يقدروا يوافقوا على
 * إيداع أو يغيّروا أي حاجة فعليًا.
 *
 * بيستخدم دور 'agent' الموجود بالفعل في users.role ENUM (كان مش
 * مستخدم في أي مكان في المشروع) - بدل ما نخترع دور جديد ونعمل
 * migration لتوسيع الـ ENUM. أي دور من الأدوار اللي تقدر تعمل تعديل
 * فعلي (manager/admin/super_admin) طبعًا يقدر يشوف برضه.
 *
 * @version 1.0.0
 * @date 2026-08-10
 */
class BillingViewerMiddleware {
    private const ALLOWED_ROLES = ['super_admin', 'admin', 'manager', 'agent'];

    public function handle(): ?array {
        $user = $_SESSION['user'] ?? ($_SERVER['auth_user'] ?? null);

        if (!$user) {
            http_response_code(401);
            return ['success' => false, 'error' => 'مطلوب تسجيل الدخول', 'code' => 401];
        }

        $role = $user['role'] ?? 'user';
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            http_response_code(403);
            return ['success' => false, 'error' => 'هذا المسار مخصص لفريق الدعم/الفوترة فقط', 'code' => 403];
        }

        return null;
    }
}
