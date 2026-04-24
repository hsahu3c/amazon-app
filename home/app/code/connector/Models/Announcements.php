<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;

/**
 * Announcements model – table: announcements
 *
 * Schema (per plan): category, announcement_type, title, summary, content, severity,
 * cta (object), display_rules (pin, highlight, requires_acknowledgement, expiry_at),
 * visibility (scope, shop_ids, target_shop_ids, marketplace_ids, user_ids), status,
 * created_by, approved_by, approved_at, created_at, updated_at, version
 */
class Announcements extends BaseMongo
{
    protected $table = 'announcements';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_RESOLVED = 'resolved';

    public const SCOPE_GLOBAL = 'GLOBAL';
    public const SCOPE_SHOP = 'shop';
    public const SCOPE_TARGET = 'target';
    public const SCOPE_MARKETPLACE = 'marketplace';
    public const SCOPE_USER = 'user';

    public const CATEGORY_PRODUCT_FEATURE = 'product_feature';
    public const CATEGORY_SYSTEM_PLATFORM = 'system_platform';
    public const CATEGORY_ENGAGEMENT = 'engagement';
    public const CATEGORY_BUSINESS_MARKETING = 'business_marketing';
    public const CATEGORY_CRITICAL_COMPLIANCE = 'critical_compliance';

    /** Product & Feature */
    public const TYPE_NEW_FEATURE = 'new_feature';
    public const TYPE_FEATURE_UPDATE = 'feature_update';
    public const TYPE_BETA_PROGRAM = 'beta_program';
    public const TYPE_DEPRECATION_NOTICE = 'deprecation_notice';
    public const TYPE_ROADMAP_PREVIEW = 'roadmap_preview';

    /** System & Platform */
    public const TYPE_SYSTEM_MAINTENANCE = 'system_maintenance';
    public const TYPE_INCIDENT_REPORT = 'incident_report';
    public const TYPE_POLICY_UPDATE = 'policy_update';
    public const TYPE_API_CHANGE = 'api_change';
    public const TYPE_PERFORMANCE_UPDATE = 'performance_update';

    /** Engagement (survey_request, user_feedback are form-eligible) */
    public const TYPE_SURVEY_REQUEST = 'survey_request';
    public const TYPE_USER_FEEDBACK = 'user_feedback';
    public const TYPE_COMMUNITY_UPDATE = 'community_update';
    public const TYPE_RELEASE_NOTES = 'release_notes';
    public const TYPE_TRAINING_WEBINAR = 'training_webinar';
    /** Business & Marketing */
    public const TYPE_PROMOTION = 'promotion';
    public const TYPE_PRICING_UPDATE = 'pricing_update';
    public const TYPE_UPSELL_OFFER = 'upsell_offer';
    public const TYPE_PARTNER_ANNOUNCEMENT = 'partner_announcement';
    /** Critical / Compliance */
    public const TYPE_SECURITY_NOTICE = 'security_notice';
    public const TYPE_COMPLIANCE_ALERT = 'compliance_alert';
    public const TYPE_ACCOUNT_RISK = 'account_risk';
    public const TYPE_ACTION_REQUIRED = 'action_required';

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_PRODUCT_FEATURE,
            self::CATEGORY_SYSTEM_PLATFORM,
            self::CATEGORY_ENGAGEMENT,
            self::CATEGORY_BUSINESS_MARKETING,
            self::CATEGORY_CRITICAL_COMPLIANCE,
        ];
    }

    /** Full allowlist of valid announcement_type values (24 types). */
    public static function getAnnouncementTypes(): array
    {
        return [
            self::TYPE_NEW_FEATURE,
            self::TYPE_FEATURE_UPDATE,
            self::TYPE_BETA_PROGRAM,
            self::TYPE_DEPRECATION_NOTICE,
            self::TYPE_ROADMAP_PREVIEW,
            self::TYPE_SYSTEM_MAINTENANCE,
            self::TYPE_INCIDENT_REPORT,
            self::TYPE_POLICY_UPDATE,
            self::TYPE_API_CHANGE,
            self::TYPE_PERFORMANCE_UPDATE,
            self::TYPE_SURVEY_REQUEST,
            self::TYPE_USER_FEEDBACK,
            self::TYPE_COMMUNITY_UPDATE,
            self::TYPE_RELEASE_NOTES,
            self::TYPE_TRAINING_WEBINAR,
            self::TYPE_PROMOTION,
            self::TYPE_PRICING_UPDATE,
            self::TYPE_UPSELL_OFFER,
            self::TYPE_PARTNER_ANNOUNCEMENT,
            self::TYPE_SECURITY_NOTICE,
            self::TYPE_COMPLIANCE_ALERT,
            self::TYPE_ACCOUNT_RISK,
            self::TYPE_ACTION_REQUIRED,
        ];
    }

    /** Announcement types that may have a form (survey_request, user_feedback). */
    public static function getFormEligibleTypes(): array
    {
        return [self::TYPE_SURVEY_REQUEST, self::TYPE_USER_FEEDBACK];
    }

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }
}
