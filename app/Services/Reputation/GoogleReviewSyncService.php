<?php
/**
 * Tourfecto - Google Review Sync Service
 * سحب المراجعات الجديدة من كل حسابات Google Business المربوطة
 * @version 1.0.0
 *
 * ملاحظة: Google مبيقدّمش webhook فوري للمراجعات الجديدة، فالطريقة
 * الرسمية الوحيدة هي polling دوري - السكريبت ده مصمم يتنده من Cron Job
 * (شوف cron/sync_google_reviews.php) كل فترة (مثلاً كل 6 ساعات).
 */
class GoogleReviewSyncService {
    /** @var Database */
    private $db;
    /** @var Encryption */
    private $encryption;
    private GoogleOAuthClient $oauthClient;
    private ReputationManager $reputationManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        $this->oauthClient = new GoogleOAuthClient();
        $this->reputationManager = new ReputationManager();
    }

    /**
     * مزامنة كل الاتصالات المربوطة (كل عملاء المنصة دفعة واحدة).
     * @return array ['synced'=>int, 'new_reviews'=>int, 'errors'=>int]
     */
    public function syncAll(): array {
        $summary = ['synced' => 0, 'new_reviews' => 0, 'errors' => 0];

        try {
            $connections = (new PlatformConnection())->where([
                'platform' => 'google_business',
                'status' => 'connected',
            ]);
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('GoogleReviewSyncService: تعذر جلب الاتصالات', ['error' => $e->getMessage()]);
            }
            return $summary;
        }

        foreach ($connections as $connection) {
            try {
                $result = $this->syncConnection($connection);
                $summary['synced']++;
                $summary['new_reviews'] += $result['new_reviews'];
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::error('Google review sync failed for connection', [
                        'connection_id' => $connection->getAttribute('id'),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $summary;
    }

    /**
     * مزامنة اتصال واحد فورًا (Manual Sync من واجهة GBP Module) - غلاف عام
     * حوالين syncConnection() الموجودة بالفعل، من غير تكرار منطقها.
     * @since 2026-08-09 (GBP Module Upgrade)
     */
    public function syncOne(PlatformConnection $connection): array {
        return $this->syncConnection($connection);
    }

    private function syncConnection(PlatformConnection $connection): array {
        $accessToken = $this->getValidAccessToken($connection);

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $result = $api->getReviews(['limit' => 50]);

        if (!$result['success']) {
            $connection->setAttribute('status', 'error');
            $connection->setAttribute('last_error', $result['error'] ?? 'خطأ غير معروف');
            $connection->save();
            throw new Exception($result['error'] ?? 'فشل جلب المراجعات');
        }

        $newCount = 0;
        $websiteId = (int) $connection->getAttribute('website_id');
        $userId = (int) $connection->getAttribute('user_id');

        foreach ($result['reviews'] as $review) {
            $reviewId = $review['id'] ?? null;
            if (!$reviewId || $this->reviewExists($websiteId, 'google_business', $reviewId)) {
                continue; // مراجعة موجودة بالفعل أو بدون معرف صالح
            }

            $this->reputationManager->processWebhook([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'platform' => 'google_business',
                'platform_review_id' => $reviewId,
                'reviewer_name' => $review['reviewer']['name'] ?? 'عميل Google',
                'review_text' => $review['text'] ?? '',
                'rating' => $review['rating'] ?? 0,
                'review_date' => $review['date'] ?? date('Y-m-d H:i:s'),
                'review_language' => 'ar',
            ]);

            // ربط جديد: نفحص لو المراجعة الجديدة دي جت من ضيف بعتنالوه
            // طلب مراجعة قبل كده (اسم متطابق + لسه مفيش رد "قيّم فعلاً") -
            // لو كده، نعلّم الطلب "قيّم" تلقائيًا بدل ما يفضل عالق على
            // "اتبعتت" للأبد من غير ما حد يتابعها يدوي.
            if (class_exists('ReviewRequestService')) {
                try {
                    (new ReviewRequestService())->markReviewedIfMatching($websiteId, $review['reviewer']['name'] ?? '');
                } catch (Exception $e) {
                    // فشل صامت - مطابقة المراجعات تحسين ثانوي، مش لازم يوقف المزامنة الأساسية
                }
            }

            $newCount++;
        }

        $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
        $connection->setAttribute('status', 'connected');
        $connection->setAttribute('last_error', null);
        $connection->save();

        if ($newCount > 0 && class_exists('Notification')) {
            Notification::notify(
                $userId,
                'review_received',
                $newCount === 1 ? 'مراجعة جديدة على Google' : "{$newCount} مراجعات جديدة على Google",
                'وصلتلك مراجعات جديدة من عملاء - راجعها ورد عليها.',
                '/reputation/reviews'
            );
        }

        return ['new_reviews' => $newCount];
    }

    private function reviewExists(int $websiteId, string $platform, string $platformReviewId): bool {
        try {
            $sql = "SELECT id FROM reviews WHERE website_id = ? AND source_platform = ? AND external_review_id = ? LIMIT 1";
            $result = $this->db->query($sql, [$websiteId, $platform, $platformReviewId]);
            return !empty($result);
        } catch (Exception $e) {
            // في حالة الشك، اعتبرها موجودة عشان منكررش المراجعة بالغلط
            return true;
        }
    }

    /**
     * يرجّع access_token صالح، وبيجدده تلقائيًا لو منتهي باستخدام refresh_token.
     * عام (مش private) عشان ReputationController يقدر يستخدمه وقت إرسال
     * رد فعلي على مراجعة، مش بس وقت المزامنة الدورية.
     */
    public function getValidAccessToken(PlatformConnection $connection): string {
        if (!$connection->isTokenExpired()) {
            return $this->encryption->decrypt((string) $connection->getAttribute('access_token'));
        }

        $refreshTokenEncrypted = $connection->getAttribute('refresh_token');
        if (!$refreshTokenEncrypted) {
            $connection->setAttribute('status', 'error');
            $connection->setAttribute('last_error', 'انتهت صلاحية التوكن ومفيش refresh token - محتاج يعيد الربط يدويًا');
            $connection->save();
            throw new Exception('لا يوجد refresh_token صالح');
        }

        $refreshToken = $this->encryption->decrypt((string) $refreshTokenEncrypted);
        $result = $this->oauthClient->refreshAccessToken($refreshToken);

        if (!$result['success']) {
            $connection->setAttribute('status', 'error');
            $connection->setAttribute('last_error', 'فشل تجديد التوكن: ' . ($result['error'] ?? ''));
            $connection->save();
            throw new Exception('فشل تجديد access_token');
        }

        $connection->setAttribute('access_token', $this->encryption->encrypt($result['access_token']));
        $connection->setAttribute('token_expires_at', date('Y-m-d H:i:s', time() + (int) $result['expires_in']));
        $connection->save();

        return $result['access_token'];
    }
}