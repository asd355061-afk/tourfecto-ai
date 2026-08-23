<?php

namespace App\Services\CRM;

/**
 * Email Tracking Enhancement Service
 * 
 * Provides advanced email tracking capabilities including:
 * - Link click tracking
 * - Email engagement scoring
 * - Automated follow-up triggers
 * - Real-time analytics
 * 
 * @package App\Services\CRM
 */
class EmailTrackingEnhancementService
{
    /**
     * Track email open event
     * 
     * @param int $tenantId Tenant identifier
     * @param int $emailId Email campaign ID
     * @param int $contactId Contact who opened the email
     * @param array $metadata Additional metadata (user agent, IP, etc.)
     * @return array Tracking result
     */
    public function trackEmailOpen(int $tenantId, int $emailId, int $contactId, array $metadata = []): array
    {
        $timestamp = date('Y-m-d H:i:s');
        
        // Log the open event
        $trackingData = [
            'tenant_id' => $tenantId,
            'email_id' => $emailId,
            'contact_id' => $contactId,
            'event_type' => 'open',
            'timestamp' => $timestamp,
            'ip_address' => $metadata['ip'] ?? null,
            'user_agent' => $metadata['user_agent'] ?? null,
            'device_type' => $this->detectDeviceType($metadata['user_agent'] ?? ''),
            'email_client' => $this->detectEmailClient($metadata['user_agent'] ?? ''),
            'location' => $metadata['location'] ?? null
        ];
        
        // Save to database (pseudo-code, adapt to your DB layer)
        // $this->db->insert('email_tracking_events', $trackingData);
        
        // Update contact engagement score
        $engagementScore = $this->updateEngagementScore($tenantId, $contactId, 'open', 5);
        
        // Check for automated follow-up triggers
        $followUpTriggered = $this->checkFollowUpTriggers($tenantId, $contactId, $emailId, 'open');
        
        return [
            'success' => true,
            'tracking_id' => uniqid('etr_', true),
            'engagement_score' => $engagementScore,
            'follow_up_triggered' => $followUpTriggered,
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Track link click event
     * 
     * @param int $tenantId Tenant identifier
     * @param int $emailId Email campaign ID
     * @param int $contactId Contact who clicked the link
     * @param string $linkUrl URL that was clicked
     * @param array $metadata Additional metadata
     * @return array Tracking result
     */
    public function trackLinkClick(int $tenantId, int $emailId, int $contactId, string $linkUrl, array $metadata = []): array
    {
        $timestamp = date('Y-m-d H:i:s');
        
        // Normalize and validate URL
        $normalizedUrl = $this->normalizeUrl($linkUrl);
        
        $trackingData = [
            'tenant_id' => $tenantId,
            'email_id' => $emailId,
            'contact_id' => $contactId,
            'event_type' => 'click',
            'link_url' => $normalizedUrl,
            'timestamp' => $timestamp,
            'ip_address' => $metadata['ip'] ?? null,
            'user_agent' => $metadata['user_agent'] ?? null,
            'device_type' => $this->detectDeviceType($metadata['user_agent'] ?? ''),
            'browser' => $this->detectBrowser($metadata['user_agent'] ?? ''),
            'location' => $metadata['location'] ?? null
        ];
        
        // Save to database
        // $this->db->insert('email_tracking_events', $trackingData);
        
        // Higher engagement score for clicks
        $engagementScore = $this->updateEngagementScore($tenantId, $contactId, 'click', 15);
        
        // Check for automated follow-up triggers
        $followUpTriggered = $this->checkFollowUpTriggers($tenantId, $contactId, $emailId, 'click', $normalizedUrl);
        
        // Update link statistics
        $linkStats = $this->updateLinkStatistics($tenantId, $emailId, $normalizedUrl);
        
        return [
            'success' => true,
            'tracking_id' => uniqid('etl_', true),
            'link_url' => $normalizedUrl,
            'engagement_score' => $engagementScore,
            'follow_up_triggered' => $followUpTriggered,
            'link_click_count' => $linkStats['total_clicks'],
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Calculate engagement score for a contact
     * 
     * @param int $tenantId Tenant identifier
     * @param int $contactId Contact identifier
     * @return array Engagement score details
     */
    public function calculateEngagementScore(int $tenantId, int $contactId): array
    {
        // Fetch recent email interactions (last 90 days)
        $interactions = $this->getRecentInteractions($tenantId, $contactId, 90);
        
        $score = 0;
        $breakdown = [
            'opens' => 0,
            'clicks' => 0,
            'replies' => 0,
            'forwards' => 0,
            'unsubscribes' => 0,
            'recency_bonus' => 0,
            'frequency_bonus' => 0
        ];
        
        foreach ($interactions as $interaction) {
            switch ($interaction['event_type']) {
                case 'open':
                    $score += 5;
                    $breakdown['opens'] += 5;
                    break;
                case 'click':
                    $score += 15;
                    $breakdown['clicks'] += 15;
                    break;
                case 'reply':
                    $score += 25;
                    $breakdown['replies'] += 25;
                    break;
                case 'forward':
                    $score += 20;
                    $breakdown['forwards'] += 20;
                    break;
                case 'unsubscribe':
                    $score -= 50;
                    $breakdown['unsubscribes'] -= 50;
                    break;
            }
        }
        
        // Recency bonus (more recent interactions = higher bonus)
        $lastInteractionDate = $interactions[0]['timestamp'] ?? null;
        if ($lastInteractionDate) {
            $daysSinceLastInteraction = floor((time() - strtotime($lastInteractionDate)) / 86400);
            if ($daysSinceLastInteraction < 7) {
                $recencyBonus = 20;
            } elseif ($daysSinceLastInteraction < 30) {
                $recencyBonus = 10;
            } elseif ($daysSinceLastInteraction < 90) {
                $recencyBonus = 5;
            } else {
                $recencyBonus = 0;
            }
            $score += $recencyBonus;
            $breakdown['recency_bonus'] = $recencyBonus;
        }
        
        // Frequency bonus (consistent engagement)
        $interactionCount = count($interactions);
        if ($interactionCount > 20) {
            $frequencyBonus = 15;
        } elseif ($interactionCount > 10) {
            $frequencyBonus = 10;
        } elseif ($interactionCount > 5) {
            $frequencyBonus = 5;
        } else {
            $frequencyBonus = 0;
        }
        $score += $frequencyBonus;
        $breakdown['frequency_bonus'] = $frequencyBonus;
        
        // Normalize score to 0-100
        $normalizedScore = max(0, min(100, $score));
        
        // Determine engagement level
        $engagementLevel = $this->getEngagementLevel($normalizedScore);
        
        return [
            'contact_id' => $contactId,
            'score' => $normalizedScore,
            'level' => $engagementLevel,
            'breakdown' => $breakdown,
            'total_interactions' => $interactionCount,
            'last_interaction' => $lastInteractionDate
        ];
    }
    
    /**
     * Update contact engagement score
     * 
     * @param int $tenantId Tenant identifier
     * @param int $contactId Contact identifier
     * @param string $eventType Event type (open, click, reply, etc.)
     * @param int $points Points to add
     * @return int New engagement score
     */
    private function updateEngagementScore(int $tenantId, int $contactId, string $eventType, int $points): int
    {
        $currentScore = $this->calculateEngagementScore($tenantId, $contactId);
        $newScore = max(0, min(100, $currentScore['score'] + $points));
        
        // Update in database
        // $this->db->update('contacts', ['engagement_score' => $newScore], ['id' => $contactId, 'tenant_id' => $tenantId]);
        
        return $newScore;
    }
    
    /**
     * Check and trigger automated follow-up actions
     * 
     * @param int $tenantId Tenant identifier
     * @param int $contactId Contact identifier
     * @param int $emailId Email campaign ID
     * @param string $triggerEvent Event that triggered the check
     * @param string|null $linkUrl URL clicked (if applicable)
     * @return array Triggered actions
     */
    private function checkFollowUpTriggers(int $tenantId, int $contactId, int $emailId, string $triggerEvent, ?string $linkUrl = null): array
    {
        $triggeredActions = [];
        
        // Fetch active automation rules for this tenant
        $automationRules = $this->getAutomationRules($tenantId, $emailId);
        
        foreach ($automationRules as $rule) {
            $shouldTrigger = false;
            
            // Check if rule matches the trigger event
            if ($rule['trigger_event'] === $triggerEvent) {
                // Additional conditions
                if (!empty($rule['conditions'])) {
                    $conditionsMet = $this->evaluateConditions($contactId, $rule['conditions'], $linkUrl);
                    $shouldTrigger = $conditionsMet;
                } else {
                    $shouldTrigger = true;
                }
            }
            
            if ($shouldTrigger) {
                // Execute the action
                $actionResult = $this->executeAutomationAction($tenantId, $contactId, $rule);
                $triggeredActions[] = [
                    'rule_id' => $rule['id'],
                    'rule_name' => $rule['name'],
                    'action_type' => $rule['action_type'],
                    'result' => $actionResult
                ];
            }
        }
        
        return $triggeredActions;
    }
    
    /**
     * Generate tracking pixel URL for email open tracking
     * 
     * @param int $tenantId Tenant identifier
     * @param int $emailId Email campaign ID
     * @param int $contactId Contact identifier
     * @return string Tracking pixel URL
     */
    public function generateTrackingPixel(int $tenantId, int $emailId, int $contactId): string
    {
        $baseUrl = $this->getBaseUrl();
        $token = $this->generateTrackingToken($tenantId, $emailId, $contactId, 'open');
        
        return "{$baseUrl}/track/open/{$token}.gif";
    }
    
    /**
     * Generate tracked link URL for click tracking
     * 
     * @param int $tenantId Tenant identifier
     * @param int $emailId Email campaign ID
     * @param int $contactId Contact identifier
     * @param string $originalUrl Original destination URL
     * @return string Tracked URL
     */
    public function generateTrackedLink(int $tenantId, int $emailId, int $contactId, string $originalUrl): string
    {
        $baseUrl = $this->getBaseUrl();
        $token = $this->generateTrackingToken($tenantId, $emailId, $contactId, 'click', $originalUrl);
        
        return "{$baseUrl}/track/click/{$token}";
    }
    
    /**
     * Get email campaign analytics
     * 
     * @param int $tenantId Tenant identifier
     * @param int $emailId Email campaign ID
     * @param string|null $startDate Start date (YYYY-MM-DD)
     * @param string|null $endDate End date (YYYY-MM-DD)
     * @return array Campaign analytics
     */
    public function getCampaignAnalytics(int $tenantId, int $emailId, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $endDate ?? date('Y-m-d');
        
        // Fetch metrics from database
        $metrics = $this->fetchCampaignMetrics($tenantId, $emailId, $startDate, $endDate);
        
        $totalSent = $metrics['total_sent'] ?? 0;
        $totalOpens = $metrics['total_opens'] ?? 0;
        $totalClicks = $metrics['total_clicks'] ?? 0;
        $uniqueOpens = $metrics['unique_opens'] ?? 0;
        $uniqueClicks = $metrics['unique_clicks'] ?? 0;
        
        // Calculate rates
        $openRate = $totalSent > 0 ? round(($uniqueOpens / $totalSent) * 100, 2) : 0;
        $clickRate = $totalSent > 0 ? round(($uniqueClicks / $totalSent) * 100, 2) : 0;
        $clickToOpenRate = $uniqueOpens > 0 ? round(($uniqueClicks / $uniqueOpens) * 100, 2) : 0;
        
        // Get top performing links
        $topLinks = $this->getTopPerformingLinks($tenantId, $emailId, $startDate, $endDate, 10);
        
        // Get engagement timeline
        $timeline = $this->getEngagementTimeline($tenantId, $emailId, $startDate, $endDate);
        
        return [
            'email_id' => $emailId,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'metrics' => [
                'sent' => $totalSent,
                'delivered' => $metrics['delivered'] ?? $totalSent,
                'opens' => [
                    'total' => $totalOpens,
                    'unique' => $uniqueOpens,
                    'rate' => $openRate
                ],
                'clicks' => [
                    'total' => $totalClicks,
                    'unique' => $uniqueClicks,
                    'rate' => $clickRate
                ],
                'click_to_open_rate' => $clickToOpenRate,
                'bounces' => $metrics['bounces'] ?? 0,
                'unsubscribes' => $metrics['unsubscribes'] ?? 0,
                'spam_complaints' => $metrics['spam_complaints'] ?? 0
            ],
            'top_links' => $topLinks,
            'engagement_timeline' => $timeline,
            'device_breakdown' => $metrics['device_breakdown'] ?? [],
            'email_client_breakdown' => $metrics['email_client_breakdown'] ?? [],
            'location_breakdown' => $metrics['location_breakdown'] ?? []
        ];
    }
    
    /**
     * Detect device type from user agent
     */
    private function detectDeviceType(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false || strpos($userAgent, 'iphone') !== false) {
            return 'mobile';
        } elseif (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'tablet';
        } else {
            return 'desktop';
        }
    }
    
    /**
     * Detect email client from user agent
     */
    private function detectEmailClient(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'outlook') !== false) {
            return 'Outlook';
        } elseif (strpos($userAgent, 'gmail') !== false) {
            return 'Gmail';
        } elseif (strpos($userAgent, 'yahoo') !== false) {
            return 'Yahoo Mail';
        } elseif (strpos($userAgent, 'applemail') !== false || strpos($userAgent, 'ios') !== false) {
            return 'Apple Mail';
        } else {
            return 'Other';
        }
    }
    
    /**
     * Detect browser from user agent
     */
    private function detectBrowser(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'chrome') !== false) {
            return 'Chrome';
        } elseif (strpos($userAgent, 'firefox') !== false) {
            return 'Firefox';
        } elseif (strpos($userAgent, 'safari') !== false) {
            return 'Safari';
        } elseif (strpos($userAgent, 'edge') !== false) {
            return 'Edge';
        } else {
            return 'Other';
        }
    }
    
    /**
     * Normalize URL
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ?: $url;
    }
    
    /**
     * Get engagement level from score
     */
    private function getEngagementLevel(int $score): string
    {
        if ($score >= 80) {
            return 'highly_engaged';
        } elseif ($score >= 60) {
            return 'engaged';
        } elseif ($score >= 40) {
            return 'moderately_engaged';
        } elseif ($score >= 20) {
            return 'low_engagement';
        } else {
            return 'inactive';
        }
    }
    
    /**
     * Generate tracking token
     */
    private function generateTrackingToken(int $tenantId, int $emailId, int $contactId, string $eventType, ?string $data = null): string
    {
        $secret = getenv('APP_KEY') ?: 'default_secret_key';
        $payload = "{$tenantId}:{$emailId}:{$contactId}:{$eventType}:{$data}:" . time();
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }
    
    /**
     * Get base URL for tracking
     */
    private function getBaseUrl(): string
    {
        return rtrim(getenv('APP_URL') ?: 'http://localhost', '/');
    }
    
    // Placeholder methods - implement based on your database layer
    private function getRecentInteractions(int $tenantId, int $contactId, int $days): array { return []; }
    private function getAutomationRules(int $tenantId, int $emailId): array { return []; }
    private function evaluateConditions(int $contactId, array $conditions, ?string $linkUrl): bool { return true; }
    private function executeAutomationAction(int $tenantId, int $contactId, array $rule): array { return ['success' => true]; }
    private function fetchCampaignMetrics(int $tenantId, int $emailId, string $startDate, string $endDate): array { return []; }
    private function getTopPerformingLinks(int $tenantId, int $emailId, string $startDate, string $endDate, int $limit): array { return []; }
    private function getEngagementTimeline(int $tenantId, int $emailId, string $startDate, string $endDate): array { return []; }
    private function updateLinkStatistics(int $tenantId, int $emailId, string $url): array { return ['total_clicks' => 1]; }
}
