<?php
/**
 * Tourfecto - Ad Campaign Model (إدارة الإعلانات)
 * @version 1.0.0
 */
class AdCampaign extends Model {
    protected $table = 'ad_campaigns';
    protected $fillable = [
        'user_id', 'website_id', 'platform_connection_id', 'platform', 'name',
        'objective', 'product_or_service', 'target_audience_brief',
        'daily_budget', 'budget_total', 'currency', 'status',
        'external_campaign_id', 'external_adset_id', 'external_budget_resource',
        'impressions', 'clicks', 'spend',
        'start_date', 'end_date', 'ai_generated', 'published_at', 'auto_optimize',
        'started_at', 'ended_at'
    ];
}
