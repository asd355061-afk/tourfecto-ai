<?php

namespace App\Services\Mobile;

/**
 * Mobile App API Service
 * 
 * Provides RESTful API endpoints for native mobile applications (iOS/Android).
 * Handles authentication, data synchronization, and push notifications.
 */
class MobileAppService
{
    private string $apiVersion = 'v1';
    private array $supportedPlatforms = ['ios', 'android'];
    private ?PushNotificationService $pushService = null;

    public function __construct()
    {
        $this->pushService = new PushNotificationService();
    }

    /**
     * Authenticate mobile user
     */
    public function authenticate(string $email, string $password, string $platform): array
    {
        if (!in_array($platform, $this->supportedPlatforms)) {
            return [
                'success' => false,
                'message' => 'Unsupported platform',
            ];
        }

        // Validate credentials (integrate with auth system)
        $user = $this->validateUserCredentials($email, $password);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid credentials',
            ];
        }

        // Generate JWT token
        $token = $this->generateJwtToken($user, $platform);
        
        // Generate refresh token
        $refreshToken = $this->generateRefreshToken($user['id']);

        return [
            'success' => true,
            'data' => [
                'user' => $this->sanitizeUserData($user),
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ],
        ];
    }

    /**
     * Refresh access token
     */
    public function refreshToken(string $refreshToken): array
    {
        $userId = $this->validateRefreshToken($refreshToken);
        
        if (!$userId) {
            return [
                'success' => false,
                'message' => 'Invalid refresh token',
            ];
        }

        $user = $this->getUserById($userId);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        $token = $this->generateJwtToken($user, 'mobile');

        return [
            'success' => true,
            'data' => [
                'access_token' => $token,
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ],
        ];
    }

    /**
     * Register device for push notifications
     */
    public function registerDevice(string $userId, string $deviceToken, string $platform): bool
    {
        if (!in_array($platform, $this->supportedPlatforms)) {
            return false;
        }

        $deviceData = [
            'user_id' => $userId,
            'device_token' => $deviceToken,
            'platform' => $platform,
            'registered_at' => date('Y-m-d H:i:s'),
        ];

        // Store device token
        $this->saveDeviceToken($deviceData);

        return true;
    }

    /**
     * Unregister device from push notifications
     */
    public function unregisterDevice(string $deviceToken): bool
    {
        return $this->removeDeviceToken($deviceToken);
    }

    /**
     * Send push notification to user
     */
    public function sendPushNotification(string $userId, string $title, string $body, array $data = []): bool
    {
        return $this->pushService->sendToUser($userId, $title, $body, $data);
    }

    /**
     * Send push notification to topic
     */
    public function sendTopicNotification(string $topic, string $title, string $body, array $data = []): bool
    {
        return $this->pushService->sendToTopic($topic, $title, $body, $data);
    }

    /**
     * Get user dashboard data for mobile
     */
    public function getDashboardData(string $userId): array
    {
        return [
            'summary' => $this->getUserSummary($userId),
            'recent_activities' => $this->getRecentActivities($userId),
            'pending_tasks' => $this->getPendingTasks($userId),
            'notifications_count' => $this->getUnreadNotificationsCount($userId),
            'quick_stats' => $this->getQuickStats($userId),
        ];
    }

    /**
     * Sync data for offline support
     */
    public function syncData(string $userId, array $lastSyncTimestamps): array
    {
        $syncData = [];

        // Sync customers
        if (isset($lastSyncTimestamps['customers'])) {
            $syncData['customers'] = $this->getUpdatedCustomers($userId, $lastSyncTimestamps['customers']);
        }

        // Sync leads
        if (isset($lastSyncTimestamps['leads'])) {
            $syncData['leads'] = $this->getUpdatedLeads($userId, $lastSyncTimestamps['leads']);
        }

        // Sync deals
        if (isset($lastSyncTimestamps['deals'])) {
            $syncData['deals'] = $this->getUpdatedDeals($userId, $lastSyncTimestamps['deals']);
        }

        // Sync activities
        if (isset($lastSyncTimestamps['activities'])) {
            $syncData['activities'] = $this->getUpdatedActivities($userId, $lastSyncTimestamps['activities']);
        }

        $syncData['sync_timestamp'] = time();

        return $syncData;
    }

    /**
     * Create activity from mobile
     */
    public function createActivity(string $userId, array $activityData): array
    {
        $validation = $this->validateActivityData($activityData);
        
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['errors'],
            ];
        }

        $activity = [
            'id' => $this->generateId(),
            'user_id' => $userId,
            'type' => $activityData['type'],
            'title' => $activityData['title'],
            'description' => $activityData['description'] ?? '',
            'related_to' => $activityData['related_to'] ?? null,
            'due_date' => $activityData['due_date'] ?? null,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'created_from' => 'mobile',
        ];

        $this->saveActivity($activity);

        return [
            'success' => true,
            'data' => $activity,
        ];
    }

    /**
     * Update activity status
     */
    public function updateActivityStatus(string $activityId, string $status): array
    {
        $activity = $this->getActivityById($activityId);
        
        if (!$activity) {
            return [
                'success' => false,
                'message' => 'Activity not found',
            ];
        }

        $activity['status'] = $status;
        $activity['updated_at'] = date('Y-m-d H:i:s');

        $this->saveActivity($activity);

        return [
            'success' => true,
            'data' => $activity,
        ];
    }

    /**
     * Search records
     */
    public function search(string $userId, string $query, string $type = 'all', int $limit = 20): array
    {
        $results = [
            'customers' => [],
            'leads' => [],
            'deals' => [],
            'activities' => [],
        ];

        if ($type === 'all' || $type === 'customers') {
            $results['customers'] = $this->searchCustomers($userId, $query, $limit);
        }

        if ($type === 'all' || $type === 'leads') {
            $results['leads'] = $this->searchLeads($userId, $query, $limit);
        }

        if ($type === 'all' || $type === 'deals') {
            $results['deals'] = $this->searchDeals($userId, $query, $limit);
        }

        if ($type === 'all' || $type === 'activities') {
            $results['activities'] = $this->searchActivities($userId, $query, $limit);
        }

        return $results;
    }

    /**
     * Get customer details
     */
    public function getCustomer(string $customerId): array
    {
        $customer = $this->fetchCustomer($customerId);
        
        if (!$customer) {
            return [];
        }

        return [
            'customer' => $customer,
            'interactions' => $this->getCustomerInteractions($customerId),
            'deals' => $this->getCustomerDeals($customerId),
            'activities' => $this->getCustomerActivities($customerId),
        ];
    }

    /**
     * Create lead from mobile
     */
    public function createLead(string $userId, array $leadData): array
    {
        $validation = $this->validateLeadData($leadData);
        
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['errors'],
            ];
        }

        $lead = [
            'id' => $this->generateId(),
            'user_id' => $userId,
            'name' => $leadData['name'],
            'email' => $leadData['email'] ?? null,
            'phone' => $leadData['phone'] ?? null,
            'company' => $leadData['company'] ?? null,
            'source' => $leadData['source'] ?? 'mobile',
            'status' => 'new',
            'score' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'created_from' => 'mobile',
        ];

        $this->saveLead($lead);

        return [
            'success' => true,
            'data' => $lead,
        ];
    }

    /**
     * Get analytics summary
     */
    public function getAnalytics(string $userId, string $period = '7d'): array
    {
        $startDate = $this->getStartDate($period);

        return [
            'period' => $period,
            'metrics' => [
                'total_customers' => $this->countCustomers($userId, $startDate),
                'total_leads' => $this->countLeads($userId, $startDate),
                'total_deals' => $this->countDeals($userId, $startDate),
                'won_deals' => $this->countWonDeals($userId, $startDate),
                'revenue' => $this->calculateRevenue($userId, $startDate),
                'conversion_rate' => $this->calculateConversionRate($userId, $startDate),
            ],
            'chart_data' => $this->getChartData($userId, $startDate),
        ];
    }

    /**
     * Update user profile
     */
    public function updateProfile(string $userId, array $profileData): array
    {
        $validation = $this->validateProfileData($profileData);
        
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['errors'],
            ];
        }

        $user = $this->getUserById($userId);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        $user = array_merge($user, $profileData);
        $user['updated_at'] = date('Y-m-d H:i:s');

        $this->updateUser($user);

        return [
            'success' => true,
            'data' => $this->sanitizeUserData($user),
        ];
    }

    /**
     * Change password
     */
    public function changePassword(string $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->getUserById($userId);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        if (!$this->verifyPassword($currentPassword, $user['password'])) {
            return [
                'success' => false,
                'message' => 'Current password is incorrect',
            ];
        }

        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Password must be at least 8 characters',
            ];
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->updateUserPassword($userId, $hashedPassword);

        return [
            'success' => true,
            'message' => 'Password changed successfully',
        ];
    }

    /**
     * Enable biometric authentication
     */
    public function enableBiometricAuth(string $userId, string $biometricKey): array
    {
        $this->saveBiometricKey($userId, $biometricKey);

        return [
            'success' => true,
            'message' => 'Biometric authentication enabled',
        ];
    }

    /**
     * Authenticate with biometrics
     */
    public function authenticateBiometric(string $userId, string $biometricKey): array
    {
        $storedKey = $this->getBiometricKey($userId);
        
        if (!$storedKey || $storedKey !== $biometricKey) {
            return [
                'success' => false,
                'message' => 'Biometric authentication failed',
            ];
        }

        $user = $this->getUserById($userId);
        $token = $this->generateJwtToken($user, 'mobile');

        return [
            'success' => true,
            'data' => [
                'access_token' => $token,
                'expires_in' => 3600,
            ],
        ];
    }

    /**
     * Get app settings
     */
    public function getAppSettings(string $userId): array
    {
        return [
            'language' => $this->getUserLanguage($userId),
            'timezone' => $this->getUserTimezone($userId),
            'notifications_enabled' => $this->areNotificationsEnabled($userId),
            'theme' => $this->getUserTheme($userId),
            'auto_sync' => $this->getAutoSyncSetting($userId),
            'biometric_enabled' => $this->isBiometricEnabled($userId),
        ];
    }

    /**
     * Update app settings
     */
    public function updateAppSettings(string $userId, array $settings): bool
    {
        return $this->saveUserSettings($userId, $settings);
    }

    /**
     * Get offline data package
     */
    public function getOfflinePackage(string $userId): array
    {
        return [
            'customers' => $this->getAllCustomers($userId),
            'leads' => $this->getAllLeads($userId),
            'deals' => $this->getAllDeals($userId),
            'products' => $this->getAllProducts($userId),
            'templates' => $this->getAllTemplates($userId),
            'downloaded_at' => time(),
        ];
    }

    /**
     * Log mobile app event
     */
    public function logEvent(string $userId, string $eventName, array $ eventData = []): void
    {
        $logEntry = [
            'user_id' => $userId,
            'event_name' => $eventName,
            'event_data' => $eventData,
            'timestamp' => time(),
            'platform' => $this->detectPlatform(),
        ];

        $this->saveEventLog($logEntry);
    }

    /**
     * Validate user credentials
     */
    private function validateUserCredentials(string $email, string $password): ?array
    {
        // This should integrate with the actual auth system
        // Placeholder implementation
        return [
            'id' => 'user_' . md5($email),
            'email' => $email,
            'name' => 'User',
            'role' => 'admin',
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ];
    }

    /**
     * Generate JWT token
     */
    private function generateJwtToken(array $user, string $platform): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'platform' => $platform,
            'iat' => time(),
            'exp' => time() + 3600,
        ]);

        $base64Header = base64_encode($header);
        $base64Payload = base64_encode($payload);

        return "{$base64Header}.{$base64Payload}.signature";
    }

    /**
     * Generate refresh token
     */
    private function generateRefreshToken(string $userId): string
    {
        return base64_encode("refresh_{$userId}_" . time());
    }

    /**
     * Sanitize user data for API response
     */
    private function sanitizeUserData(array $user): array
    {
        unset($user['password']);
        return $user;
    }

    /**
     * Placeholder methods - should integrate with actual data layer
     */
    private function getUserById(string $id): ?array { return ['id' => $id, 'email' => 'user@example.com']; }
    private function validateRefreshToken(string $token): ?string { return 'user_id'; }
    private function saveDeviceToken(array $data): bool { return true; }
    private function removeDeviceToken(string $token): bool { return true; }
    private function getUserSummary(string $userId): array { return []; }
    private function getRecentActivities(string $userId): array { return []; }
    private function getPendingTasks(string $userId): array { return []; }
    private function getUnreadNotificationsCount(string $userId): int { return 0; }
    private function getQuickStats(string $userId): array { return []; }
    private function getUpdatedCustomers(string $userId, int $timestamp): array { return []; }
    private function getUpdatedLeads(string $userId, int $timestamp): array { return []; }
    private function getUpdatedDeals(string $userId, int $timestamp): array { return []; }
    private function getUpdatedActivities(string $userId, int $timestamp): array { return []; }
    private function validateActivityData(array $data): array { return ['valid' => true]; }
    private function generateId(): string { return uniqid(); }
    private function saveActivity(array $activity): bool { return true; }
    private function getActivityById(string $id): ?array { return ['id' => $id]; }
    private function searchCustomers(string $userId, string $query, int $limit): array { return []; }
    private function searchLeads(string $userId, string $query, int $limit): array { return []; }
    private function searchDeals(string $userId, string $query, int $limit): array { return []; }
    private function searchActivities(string $userId, string $query, int $limit): array { return []; }
    private function fetchCustomer(string $id): ?array { return ['id' => $id]; }
    private function getCustomerInteractions(string $customerId): array { return []; }
    private function getCustomerDeals(string $customerId): array { return []; }
    private function getCustomerActivities(string $customerId): array { return []; }
    private function validateLeadData(array $data): array { return ['valid' => true]; }
    private function saveLead(array $lead): bool { return true; }
    private function getStartDate(string $period): string { return date('Y-m-d', strtotime("-{$period}")); }
    private function countCustomers(string $userId, string $startDate): int { return 0; }
    private function countLeads(string $userId, string $startDate): int { return 0; }
    private function countDeals(string $userId, string $startDate): int { return 0; }
    private function countWonDeals(string $userId, string $startDate): int { return 0; }
    private function calculateRevenue(string $userId, string $startDate): float { return 0.0; }
    private function calculateConversionRate(string $userId, string $startDate): float { return 0.0; }
    private function getChartData(string $userId, string $startDate): array { return []; }
    private function validateProfileData(array $data): array { return ['valid' => true]; }
    private function updateUser(array $user): bool { return true; }
    private function verifyPassword(string $password, string $hash): bool { return true; }
    private function updateUserPassword(string $userId, string $hash): bool { return true; }
    private function saveBiometricKey(string $userId, string $key): bool { return true; }
    private function getBiometricKey(string $userId): ?string { return null; }
    private function getUserLanguage(string $userId): string { return 'en'; }
    private function getUserTimezone(string $userId): string { return 'UTC'; }
    private function areNotificationsEnabled(string $userId): bool { return true; }
    private function getUserTheme(string $userId): string { return 'light'; }
    private function getAutoSyncSetting(string $userId): bool { return true; }
    private function isBiometricEnabled(string $userId): bool { return false; }
    private function saveUserSettings(string $userId, array $settings): bool { return true; }
    private function getAllCustomers(string $userId): array { return []; }
    private function getAllLeads(string $userId): array { return []; }
    private function getAllDeals(string $userId): array { return []; }
    private function getAllProducts(string $userId): array { return []; }
    private function getAllTemplates(string $userId): array { return []; }
    private function detectPlatform(): string { return 'mobile'; }
    private function saveEventLog(array $log): bool { return true; }
}
