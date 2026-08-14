<?php
/**
 * Tourfecto - Export User Data Job
 * @version 1.0.0
 *
 * نطاق التصدير: بيانات الحساب/الملف الشخصي الأساسية فقط (Core Account
 * Data) - مش "كل بيانات المستخدم" في المشروع كله. اقتصرت عمدًا على
 * الجداول اللي فحصتها وتأكدت من بنيتها الحقيقية بنفسي في هذه الجلسة:
 * users, login_history, oauth_accounts, user_refresh_tokens. لم أضمّن
 * جداول العمل الأخرى (websites, subscriptions, crm_leads, invoices,
 * chat...) لأنني لم أتحقق من بنيتها الفعلية بشكل مباشر، وهذا المشروع
 * أثبت بالفعل (راجع PROFILE_CHANGELOG.md) أن database/schema.sql
 * المرفق قد ينحرف عن الواقع الفعلي - تخمين أعمدة جداول لم تُتحقق منها
 * هو بالضبط نوع الخطأ اللي كنا بنتجنبه طول التسليم ده. توسعة النطاق
 * ليشمل بيانات الأعمال الكاملة يحتاج مراجعة منفصلة لكل جدول أولًا.
 *
 * لا تُصدَّر أي أسرار: لا password_hash، لا api_token، لا
 * two_factor_secret/recovery_codes، ولا أي Access/Refresh Token خام.
 */
class ExportUserDataJob implements QueueJobInterface {
    public function handle(array $payload): void {
        $requestId = (int) ($payload['export_request_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);

        $db = Database::getInstance();
        $request = $db->query('SELECT * FROM data_export_requests WHERE id = ? LIMIT 1', [$requestId]);
        if (empty($request)) {
            throw new Exception("Data export request #{$requestId} غير موجود");
        }

        $db->exec("UPDATE data_export_requests SET status = 'processing' WHERE id = ?", [$requestId]);

        try {
            $userModel = new User();
            $user = $userModel->find($userId);
            if (!$user) {
                throw new Exception("User #{$userId} غير موجود");
            }

            // toArray() بيحذف الحقول المحمية تلقائيًا (راجع $hidden في
            // User.php: password_hash, api_token, two_factor_secret,
            // two_factor_recovery_codes) - نفس الحماية المستخدمة في كل
            // مكان تاني في المشروع، مش منطق جديد مختلف هنا.
            $profile = $user->toArray();

            $loginHistory = $db->query(
                'SELECT status, ip_address, device_type, browser, platform, country, city, created_at
                 FROM login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 200',
                [$userId]
            );

            $connectedAccounts = $db->query(
                'SELECT provider, email, created_at FROM oauth_accounts WHERE user_id = ?',
                [$userId]
            );

            // فقط بيانات وصفية عن الجلسات (device_name/ip/تواريخ) - مش أي
            // توكن خام أو hash
            $sessions = $db->query(
                'SELECT device_name, ip_address, created_at, last_used_at, revoked_at, expires_at
                 FROM user_refresh_tokens WHERE user_id = ? ORDER BY created_at DESC',
                [$userId]
            );

            $exportData = [
                'export_generated_at' => date('c'),
                'export_scope_note' => 'Core account/profile data only - not a full export of all Tourfecto business data (websites, CRM, billing, chat, etc.)',
                'profile' => $profile,
                'login_history' => $loginHistory,
                'connected_accounts' => $connectedAccounts,
                'sessions_metadata' => $sessions,
            ];

            $exportDir = TOURFECTO_STORAGE . '/exports';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }

            // اسم ملف عشوائي غير قابل للتخمين - الملف بره public_html
            // أصلًا (TOURFECTO_STORAGE) فمش قابل للوصول المباشر عبر أي
            // رابط، لكن اسم عشوائي طبقة حماية إضافية لو انتقل الملف يومًا.
            $fileName = 'export_' . $userId . '_' . bin2hex(random_bytes(16)) . '.json';
            $filePath = $exportDir . '/' . $fileName;

            file_put_contents($filePath, json_encode($exportData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $db->exec(
                "UPDATE data_export_requests SET status = 'ready', file_path = ?, completed_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?",
                [$filePath, $requestId]
            );
        } catch (\Throwable $e) {
            $db->exec(
                "UPDATE data_export_requests SET status = 'failed', error_message = ? WHERE id = ?",
                [substr($e->getMessage(), 0, 500), $requestId]
            );
            if (class_exists('Logger')) {
                Logger::error('ExportUserDataJob Failed', ['request_id' => $requestId, 'user_id' => $userId, 'message' => $e->getMessage()]);
            }
            throw $e; // يخلي QueueManager يسجّلها fail ويعيد المحاولة حسب MAX_ATTEMPTS
        }
    }
}
