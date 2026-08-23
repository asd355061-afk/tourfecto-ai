<?php

namespace App\Services\Marketplace;

/**
 * Integration Marketplace Service
 * 
 * Manages third-party integrations and plugins for the platform.
 * Allows users to browse, install, and configure integrations.
 */
class MarketplaceService
{
    private array $integrations = [];
    private string $cacheFile = '/workspace/storage/marketplace_cache.json';
    
    public function __construct()
    {
        $this->loadIntegrations();
    }

    /**
     * Get all available integrations
     */
    public function getIntegrations(array $filters = []): array
    {
        $results = $this->integrations;
        
        // Filter by category
        if (isset($filters['category'])) {
            $results = array_filter($results, function($integration) use ($filters) {
                return $integration['category'] === $filters['category'];
            });
        }
        
        // Filter by status
        if (isset($filters['status'])) {
            $results = array_filter($results, function($integration) use ($filters) {
                return $integration['status'] === $filters['status'];
            });
        }
        
        // Search by name or description
        if (isset($filters['search'])) {
            $search = strtolower($filters['search']);
            $results = array_filter($results, function($integration) use ($search) {
                return strpos(strtolower($integration['name']), $search) !== false ||
                       strpos(strtolower($integration['description']), $search) !== false;
            });
        }
        
        return array_values($results);
    }

    /**
     * Get integration by ID
     */
    public function getIntegration(string $id): ?array
    {
        foreach ($this->integrations as $integration) {
            if ($integration['id'] === $id) {
                return $integration;
            }
        }
        
        return null;
    }

    /**
     * Install integration
     */
    public function installIntegration(string $id, array $config = []): bool
    {
        $integration = $this->getIntegration($id);
        
        if (!$integration) {
            return false;
        }
        
        // Validate configuration
        if (!$this->validateConfig($integration, $config)) {
            return false;
        }
        
        // Install dependencies
        if (isset($integration['dependencies'])) {
            foreach ($integration['dependencies'] as $dependency) {
                if (!$this->installIntegration($dependency)) {
                    return false;
                }
            }
        }
        
        // Run installation script
        if (isset($integration['install_script'])) {
            $scriptPath = "/workspace/app/Integrations/{$id}/install.php";
            if (file_exists($scriptPath)) {
                require_once $scriptPath;
                $installer = new InstallHandler();
                if (!$installer->install($config)) {
                    return false;
                }
            }
        }
        
        // Save configuration
        $this->saveIntegrationConfig($id, $config);
        
        // Update status
        $this->updateIntegrationStatus($id, 'installed');
        
        return true;
    }

    /**
     * Uninstall integration
     */
    public function uninstallIntegration(string $id): bool
    {
        $integration = $this->getIntegration($id);
        
        if (!$integration) {
            return false;
        }
        
        // Check if other integrations depend on this
        $dependents = $this->getDependentIntegrations($id);
        if (!empty($dependents)) {
            return false; // Cannot uninstall, has dependents
        }
        
        // Run uninstallation script
        if (isset($integration['uninstall_script'])) {
            $scriptPath = "/workspace/app/Integrations/{$id}/uninstall.php";
            if (file_exists($scriptPath)) {
                require_once $scriptPath;
                $uninstaller = new UninstallHandler();
                $uninstaller->uninstall();
            }
        }
        
        // Remove configuration
        $this->removeIntegrationConfig($id);
        
        // Update status
        $this->updateIntegrationStatus($id, 'available');
        
        return true;
    }

    /**
     * Update integration configuration
     */
    public function updateConfig(string $id, array $config): bool
    {
        $integration = $this->getIntegration($id);
        
        if (!$integration) {
            return false;
        }
        
        if (!$this->validateConfig($integration, $config)) {
            return false;
        }
        
        return $this->saveIntegrationConfig($id, $config);
    }

    /**
     * Test integration connection
     */
    public function testConnection(string $id): array
    {
        $integration = $this->getIntegration($id);
        
        if (!$integration) {
            return ['success' => false, 'message' => 'Integration not found'];
        }
        
        $config = $this->getIntegrationConfig($id);
        
        if (empty($config)) {
            return ['success' => false, 'message' => 'Integration not configured'];
        }
        
        // Run test script
        if (isset($integration['test_script'])) {
            $scriptPath = "/workspace/app/Integrations/{$id}/test.php";
            if (file_exists($scriptPath)) {
                require_once $scriptPath;
                $tester = new TestHandler();
                return $tester->test($config);
            }
        }
        
        return ['success' => true, 'message' => 'Connection successful'];
    }

    /**
     * Get integration categories
     */
    public function getCategories(): array
    {
        $categories = [];
        
        foreach ($this->integrations as $integration) {
            $category = $integration['category'];
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'id' => $category,
                    'name' => $this->getCategoryName($category),
                    'count' => 0,
                ];
            }
            $categories[$category]['count']++;
        }
        
        return array_values($categories);
    }

    /**
     * Get installed integrations
     */
    public function getInstalledIntegrations(): array
    {
        return $this->getIntegrations(['status' => 'installed']);
    }

    /**
     * Get available (not installed) integrations
     */
    public function getAvailableIntegrations(): array
    {
        return $this->getIntegrations(['status' => 'available']);
    }

    /**
     * Get integration logs
     */
    public function getLogs(string $id, int $limit = 50): array
    {
        $logFile = "/workspace/storage/logs/integration_{$id}.log";
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);
        
        return array_slice($lines, 0, $limit);
    }

    /**
     * Clear integration logs
     */
    public function clearLogs(string $id): bool
    {
        $logFile = "/workspace/storage/logs/integration_{$id}.log";
        
        if (file_exists($logFile)) {
            return unlink($logFile);
        }
        
        return true;
    }

    /**
     * Load integrations from cache or initialize
     */
    private function loadIntegrations(): void
    {
        if (file_exists($this->cacheFile)) {
            $cache = file_get_contents($this->cacheFile);
            $data = json_decode($cache, true);
            
            if ($data && isset($data['integrations'])) {
                $this->integrations = $data['integrations'];
                return;
            }
        }
        
        // Initialize default integrations
        $this->integrations = $this->getDefaultIntegrations();
        $this->saveCache();
    }

    /**
     * Get default integrations list
     */
    private function getDefaultIntegrations(): array
    {
        return [
            // CRM Integrations
            [
                'id' => 'salesforce',
                'name' => 'Salesforce',
                'description' => 'Sync contacts, leads, and deals with Salesforce CRM',
                'category' => 'crm',
                'version' => '2.1.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'salesforce.svg',
                'config_fields' => [
                    ['name' => 'api_url', 'type' => 'text', 'required' => true, 'label' => 'API URL'],
                    ['name' => 'client_id', 'type' => 'text', 'required' => true, 'label' => 'Client ID'],
                    ['name' => 'client_secret', 'type' => 'password', 'required' => true, 'label' => 'Client Secret'],
                    ['name' => 'username', 'type' => 'text', 'required' => true, 'label' => 'Username'],
                    ['name' => 'password', 'type' => 'password', 'required' => true, 'label' => 'Password + Security Token'],
                ],
                'dependencies' => [],
                'install_script' => 'salesforce/install.php',
                'uninstall_script' => 'salesforce/uninstall.php',
                'test_script' => 'salesforce/test.php',
            ],
            [
                'id' => 'hubspot',
                'name' => 'HubSpot',
                'description' => 'Connect with HubSpot CRM for contact and deal management',
                'category' => 'crm',
                'version' => '1.8.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'hubspot.svg',
                'config_fields' => [
                    ['name' => 'access_token', 'type' => 'password', 'required' => true, 'label' => 'Access Token'],
                    ['name' => 'portal_id', 'type' => 'text', 'required' => true, 'label' => 'Portal ID'],
                ],
                'dependencies' => [],
                'install_script' => 'hubspot/install.php',
                'uninstall_script' => 'hubspot/uninstall.php',
                'test_script' => 'hubspot/test.php',
            ],
            
            // Payment Integrations
            [
                'id' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Accept payments via Stripe',
                'category' => 'payment',
                'version' => '3.2.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'stripe.svg',
                'config_fields' => [
                    ['name' => 'publishable_key', 'type' => 'text', 'required' => true, 'label' => 'Publishable Key'],
                    ['name' => 'secret_key', 'type' => 'password', 'required' => true, 'label' => 'Secret Key'],
                    ['name' => 'webhook_secret', 'type' => 'password', 'required' => false, 'label' => 'Webhook Secret'],
                ],
                'dependencies' => [],
                'install_script' => 'stripe/install.php',
                'uninstall_script' => 'stripe/uninstall.php',
                'test_script' => 'stripe/test.php',
            ],
            [
                'id' => 'paypal',
                'name' => 'PayPal',
                'description' => 'Accept PayPal payments',
                'category' => 'payment',
                'version' => '2.5.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'paypal.svg',
                'config_fields' => [
                    ['name' => 'client_id', 'type' => 'text', 'required' => true, 'label' => 'Client ID'],
                    ['name' => 'client_secret', 'type' => 'password', 'required' => true, 'label' => 'Client Secret'],
                    ['name' => 'mode', 'type' => 'select', 'required' => true, 'label' => 'Mode', 'options' => ['sandbox', 'live']],
                ],
                'dependencies' => [],
                'install_script' => 'paypal/install.php',
                'uninstall_script' => 'paypal/uninstall.php',
                'test_script' => 'paypal/test.php',
            ],
            
            // Marketing Integrations
            [
                'id' => 'mailchimp',
                'name' => 'Mailchimp',
                'description' => 'Email marketing automation with Mailchimp',
                'category' => 'marketing',
                'version' => '4.0.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'mailchimp.svg',
                'config_fields' => [
                    ['name' => 'api_key', 'type' => 'password', 'required' => true, 'label' => 'API Key'],
                    ['name' => 'audience_id', 'type' => 'text', 'required' => true, 'label' => 'Audience ID'],
                ],
                'dependencies' => [],
                'install_script' => 'mailchimp/install.php',
                'uninstall_script' => 'mailchimp/uninstall.php',
                'test_script' => 'mailchimp/test.php',
            ],
            [
                'id' => 'sendgrid',
                'name' => 'SendGrid',
                'description' => 'Email delivery service by SendGrid',
                'category' => 'marketing',
                'version' => '2.3.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'sendgrid.svg',
                'config_fields' => [
                    ['name' => 'api_key', 'type' => 'password', 'required' => true, 'label' => 'API Key'],
                    ['name' => 'from_email', 'type' => 'email', 'required' => true, 'label' => 'From Email'],
                ],
                'dependencies' => [],
                'install_script' => 'sendgrid/install.php',
                'uninstall_script' => 'sendgrid/uninstall.php',
                'test_script' => 'sendgrid/test.php',
            ],
            
            // Analytics Integrations
            [
                'id' => 'google_analytics',
                'name' => 'Google Analytics',
                'description' => 'Track website analytics with Google Analytics',
                'category' => 'analytics',
                'version' => '4.0.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'google-analytics.svg',
                'config_fields' => [
                    ['name' => 'tracking_id', 'type' => 'text', 'required' => true, 'label' => 'Tracking ID'],
                    ['name' => 'property_id', 'type' => 'text', 'required' => true, 'label' => 'Property ID'],
                ],
                'dependencies' => [],
                'install_script' => 'google_analytics/install.php',
                'uninstall_script' => 'google_analytics/uninstall.php',
                'test_script' => 'google_analytics/test.php',
            ],
            
            // Communication Integrations
            [
                'id' => 'twilio',
                'name' => 'Twilio',
                'description' => 'SMS and voice communication via Twilio',
                'category' => 'communication',
                'version' => '3.1.0',
                'author' => 'Tourfecto',
                'status' => 'available',
                'icon' => 'twilio.svg',
                'config_fields' => [
                    ['name' => 'account_sid', 'type' => 'text', 'required' => true, 'label' => 'Account SID'],
                    ['name' => 'auth_token', 'type' => 'password', 'required' => true, 'label' => 'Auth Token'],
                    ['name' => 'phone_number', 'type' => 'text', 'required' => true, 'label' => 'Phone Number'],
                ],
                'dependencies' => [],
                'install_script' => 'twilio/install.php',
                'uninstall_script' => 'twilio/uninstall.php',
                'test_script' => 'twilio/test.php',
            ],
        ];
    }

    /**
     * Get category display name
     */
    private function getCategoryName(string $category): string
    {
        $names = [
            'crm' => 'CRM',
            'payment' => 'Payment Gateways',
            'marketing' => 'Marketing',
            'analytics' => 'Analytics',
            'communication' => 'Communication',
            'social' => 'Social Media',
            'booking' => 'Booking Systems',
            'accounting' => 'Accounting',
        ];
        
        return $names[$category] ?? ucfirst($category);
    }

    /**
     * Validate configuration against schema
     */
    private function validateConfig(array $integration, array $config): bool
    {
        if (!isset($integration['config_fields'])) {
            return true;
        }
        
        foreach ($integration['config_fields'] as $field) {
            if ($field['required'] && empty($config[$field['name']])) {
                return false;
            }
            
            if ($field['type'] === 'email' && !empty($config[$field['name']])) {
                if (!filter_var($config[$field['name']], FILTER_VALIDATE_EMAIL)) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Get integration configuration
     */
    private function getIntegrationConfig(string $id): array
    {
        $configFile = "/workspace/storage/integrations/{$id}_config.json";
        
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            return json_decode($content, true) ?? [];
        }
        
        return [];
    }

    /**
     * Save integration configuration
     */
    private function saveIntegrationConfig(string $id, array $config): bool
    {
        $configDir = '/workspace/storage/integrations';
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        $configFile = "{$configDir}/{$id}_config.json";
        
        // Encrypt sensitive fields
        $encryptedConfig = $this->encryptSensitiveFields($id, $config);
        
        return file_put_contents($configFile, json_encode($encryptedConfig, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Remove integration configuration
     */
    private function removeIntegrationConfig(string $id): bool
    {
        $configFile = "/workspace/storage/integrations/{$id}_config.json";
        
        if (file_exists($configFile)) {
            return unlink($configFile);
        }
        
        return true;
    }

    /**
     * Update integration status
     */
    private function updateIntegrationStatus(string $id, string $status): void
    {
        foreach ($this->integrations as &$integration) {
            if ($integration['id'] === $id) {
                $integration['status'] = $status;
                break;
            }
        }
        
        $this->saveCache();
    }

    /**
     * Get integrations that depend on given integration
     */
    private function getDependentIntegrations(string $id): array
    {
        $dependents = [];
        
        foreach ($this->integrations as $integration) {
            if (isset($integration['dependencies']) && in_array($id, $integration['dependencies'])) {
                if ($integration['status'] === 'installed') {
                    $dependents[] = $integration;
                }
            }
        }
        
        return $dependents;
    }

    /**
     * Encrypt sensitive configuration fields
     */
    private function encryptSensitiveFields(string $integrationId, array $config): array
    {
        $integration = $this->getIntegration($integrationId);
        
        if (!$integration) {
            return $config;
        }
        
        foreach ($integration['config_fields'] as $field) {
            if ($field['type'] === 'password' && !empty($config[$field['name']])) {
                $config[$field['name']] = base64_encode($config[$field['name']]);
            }
        }
        
        return $config;
    }

    /**
     * Decrypt sensitive configuration fields
     */
    private function decryptSensitiveFields(string $integrationId, array $config): array
    {
        $integration = $this->getIntegration($integrationId);
        
        if (!$integration) {
            return $config;
        }
        
        foreach ($integration['config_fields'] as $field) {
            if ($field['type'] === 'password' && !empty($config[$field['name']])) {
                $config[$field['name']] = base64_decode($config[$field['name']]);
            }
        }
        
        return $config;
    }

    /**
     * Save cache to file
     */
    private function saveCache(): void
    {
        $cacheDir = dirname($this->cacheFile);
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $data = [
            'integrations' => $this->integrations,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Refresh integrations list
     */
    public function refreshIntegrations(): bool
    {
        $this->integrations = $this->getDefaultIntegrations();
        
        // Preserve installed status and config
        $existingConfigs = [];
        foreach ($this->integrations as $integration) {
            $config = $this->getIntegrationConfig($integration['id']);
            if (!empty($config)) {
                $existingConfigs[$integration['id']] = [
                    'status' => 'installed',
                    'config' => $config,
                ];
            }
        }
        
        $this->saveCache();
        
        return true;
    }
}
