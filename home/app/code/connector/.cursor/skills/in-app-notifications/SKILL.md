---
name: in-app-notifications
description: Architectural reference for the in-app notification and announcement system — bell badge, notification CRUD, announcement lifecycle (create/approve/publish), delivery tracking, acknowledge/read flows, form engagement, and WebSocket realtime push. Use when working on bell UI, notification panel, announcement cards, engagement APIs, or admin announcement management.
---

# In-App Notifications & Announcements

This skill covers the two notification subsystems (Notifications and Announcements) that feed the bell icon badge and popover panel, plus the admin workflow for managing announcements.

## Architecture Overview

```
Bell Badge Count = Notifications (unread, non-archived)
                 + Announcements (unread OR read-but-not-acknowledged)
                 + QueuedTasks (attention_required)
```

Three independent data sources are aggregated by `BellNotificationHelper::getBellCount()` into a single badge number with breakdown.

### Notifications vs Announcements

| Aspect | Notifications | Announcements |
|--------|--------------|---------------|
| Origin | System-generated (product sync, errors, tasks) | Admin-authored (editorial content) |
| Lifecycle | Create -> Read -> Archive | Draft -> Approved -> Published -> Disabled/Resolved |
| Tracking | Simple `is_read` / `is_archived` flags | Delivery records with read + acknowledge state |
| Scope | Per user/shop/appTag | GLOBAL, shop, target, marketplace, user |
| Forms | No | Yes (survey_request, user_feedback types) |

## Key Paths

### Controllers
- `controllers/BellNotificationController.php` — Bell count, mark-read, archive APIs
- `controllers/AnnouncementController.php` — Merchant-facing: list, read, acknowledge, form, engagement
- `controllers/AdminAnnouncementController.php` — Admin: create, update, approve, publish, disable, resolve, list, get, setForm

### Business Logic (Components)
- `Components/BellNotificationHelper.php` — Aggregated bell count from all 3 sources
- `Components/AnnouncementHelper.php` — Core announcement logic: visibility filter, delivery management, form validation, engagement tracking, WebSocket push
- `Components/Helper.php` — WebSocket trigger (`triggerMessage`, `handleMessage`)

### Models (MongoDB)
- `Models/Notifications.php` — Collection: `notifications`. Activity notifications with filter operators
- `Models/Announcements.php` — Collection: `announcements`. Status/category/type constants (24 types across 5 categories)
- `Models/AnnouncementDeliveries.php` — Collection: `announcement_deliveries`. Per-user delivery state (read/ack)
- `Models/AnnouncementEngagement.php` — Collection: `announcement_engagement`. Append-only audit log
- `Models/AnnouncementForms.php` — Collection: `announcement_forms`. Survey/feedback form schemas
- `Models/AnnouncementFormResponses.php` — Collection: `announcement_form_responses`. User submissions with duplicate prevention

### Tests
- `Test/Models/NotificationsTest.php` — Unit tests for notification filtering

## API Endpoints

### Bell / Notifications
| Method | Endpoint | Action |
|--------|----------|--------|
| POST | `/connector/bell-notification/bellCount` | Get bell count with breakdown |
| POST | `/connector/bell-notification/markAsRead` | Mark single (`notification_id`) or all (`mark_all: true`) |
| POST | `/connector/bell-notification/archive` | Archive single (`notification_id`) or all (`archive_all: true`) |

### Announcements (Merchant)
| Method | Endpoint | Action |
|--------|----------|--------|
| GET | `/connector/announcement/getList` | Bell list with delivery state |
| POST | `/connector/announcement/markRead` | Mark one read (`id`) |
| POST | `/connector/announcement/markAllRead` | Mark all read |
| POST | `/connector/announcement/acknowledge` | Acknowledge critical/requires_ack (`id`) |
| GET | `/connector/announcement/getForm` | Get form schema (`id`) |
| POST | `/connector/announcement/submitForm` | Submit form (`announcement_id`, `form_id`, `responses`) |
| POST | `/connector/announcement/engagement` | Record event (`announcement_id`, `event`, `metadata`) |

### Announcements (Admin)
| Method | Endpoint | Action |
|--------|----------|--------|
| POST | `/connector/admin-announcement/create` | Create draft |
| PATCH | `/connector/admin-announcement/update` | Update draft/approved |
| POST | `/connector/admin-announcement/approve` | Admin approval |
| POST | `/connector/admin-announcement/publish` | Publish to audience + WebSocket push |
| POST | `/connector/admin-announcement/disable` | Disable published |
| POST | `/connector/admin-announcement/resolve` | Mark resolved |
| GET | `/connector/admin-announcement/list` | List with filtering/pagination |
| GET | `/connector/admin-announcement/get` | Get single announcement |
| POST | `/connector/admin-announcement/setForm` | Attach form to announcement |

## Key Patterns

### Scope-Aware Delivery
Announcements have 5 visibility scopes: `GLOBAL`, `shop`, `target`, `marketplace`, `user`. Delivery tracking is scope-aware:
- **GLOBAL**: Delivery tracked at user level only (no shop context). Read/ack uses OR-merge across all shops.
- **Scoped**: Delivery tracked at full context level (user + shop + target + marketplace).

The `AnnouncementDeliveries::getScopeFilter()` method is the single source of truth for scope-based query filtering.

### Create-on-First-Seen
Announcement delivery records are not pre-created. When a user first sees an announcement in `getAnnouncementsForBell()`, `upsertDelivery()` creates the delivery record with `delivered_at` timestamp. This avoids creating millions of delivery records for GLOBAL announcements.

### Badge Count Logic
`BellNotificationHelper::getBellCount()` aggregates:
1. `Notifications::countUnread()` — unread, non-archived notifications
2. `AnnouncementHelper::getUnreadCountForBadge()` — unread OR (critical/requires_ack AND not acknowledged)
3. `QueuedTasks::countAttentionRequired()` — tasks needing attention

The announcement badge uses a `$lookup` aggregation pipeline joining `announcement_deliveries` with `announcements` to check category/display_rules.

### Acknowledge Flow
Acknowledge is gated — only allowed when:
- `category === 'critical_compliance'`, OR
- `display_rules.requires_acknowledgement === true`, OR
- `announcement_type` is form-eligible (`survey_request`, `user_feedback`)

Acknowledging records an `acknowledged` engagement event and sets `is_acknowledged = true` + `acknowledged_at` on the delivery.

### Form Engagement
Only `survey_request` and `user_feedback` announcement types support forms.
- Schema: fields with `id`, `label`, `type` (text, textarea, rating, radio, select, checkbox), `required`, `options`, `scale`
- Validation: text (500 chars), textarea (2000 chars), rating (1-scale), radio/select (must match options), checkbox (bool or array)
- Duplicate prevention: 409 Conflict if already submitted (per user/shop/form)
- Auto-marks announcement as read on submission

### Admin Workflow
```
Draft --> Approved --> Published --> Disabled
                                --> Resolved
```
- Only `draft` and `approved` can be updated
- `approve` sets `approved_by` and `approved_at`
- `publish` triggers `pushAnnouncementToAudience()` for WebSocket delivery
- Visibility validation enforces: one scope, matching IDs (e.g. shop scope requires shop_ids)

### Engagement Tracking
Append-only audit log in `announcement_engagement`. Events:
- `viewed` — recorded on `markAnnouncementRead()`
- `clicked`, `cta_clicked` — client-reported
- `acknowledged` — recorded on `acknowledgeAnnouncement()`
- `form_started`, `form_submitted` — form lifecycle
- `beta_join_clicked` — special: also marks as read

## WebSocket / Realtime

WebSocket push happens at two points:
1. **Notifications**: `Notifications::addNotification()` calls `Helper::handleMessage()` after insert
2. **Announcements**: `AnnouncementHelper::pushAnnouncementToAudience()` on publish

Flow:
```
Model/Helper -> Helper::handleMessage($data)
             -> checks websocket config: app_tags.[appTag].websocket.{client_id, allowed_types}
             -> Helper::triggerMessage($params, $userId)
             -> HTTP POST to https://websocket-notify.cifapps.com
             -> JWT token (RS256) with user_id from connector.pem
```

For scoped announcements, `resolveUserIdsByVisibility()` looks up `user_details` collection to find matching users. GLOBAL scope returns empty (deferred to client-side on-open).

## MongoDB Collections

| Collection | Purpose | Key Fields |
|-----------|---------|------------|
| `notifications` | Activity notifications | user_id, shop_id, appTag, is_read, is_archived, severity, tag |
| `announcements` | Editorial content | status, category, announcement_type, visibility, display_rules, version |
| `announcement_deliveries` | Per-user delivery state | announcement_id, user_id, shop_id, is_read, is_acknowledged |
| `announcement_engagement` | Audit trail (append-only) | announcement_id, user_id, event, metadata, created_at |
| `announcement_forms` | Survey/feedback schemas | announcement_id, title, fields[] |
| `announcement_form_responses` | User submissions | announcement_id, form_id, user_id, shop_id, responses |

## Announcement Categories & Types (24 total)

| Category | Types |
|----------|-------|
| `product_feature` | new_feature, feature_update, beta_program, deprecation_notice, roadmap_preview |
| `system_platform` | system_maintenance, incident_report, policy_update, api_change, performance_update |
| `engagement` | survey_request*, user_feedback*, community_update, release_notes, training_webinar |
| `business_marketing` | promotion, pricing_update, upsell_offer, partner_announcement |
| `critical_compliance` | security_notice, compliance_alert, account_risk, action_required |

\* = form-eligible types

## Checklist — Adding a New Notification Feature

- [ ] Determine if this is a Notification (system event) or Announcement (editorial)
- [ ] For Notifications: add via `Notifications::addNotification()` — handles WebSocket trigger
- [ ] For Announcements: use Admin API (create -> approve -> publish) workflow
- [ ] Verify bell badge logic includes the new source if needed (`BellNotificationHelper`)
- [ ] If adding a new announcement type: add constant to `Announcements.php`, add to `getAnnouncementTypes()`
- [ ] If form-eligible: add to `getFormEligibleTypes()`, attach form via `setFormAction`
- [ ] Test scope-aware delivery: GLOBAL vs scoped (shop/target/marketplace/user)
- [ ] Verify WebSocket push works: check `app_tags.[appTag].websocket` config has `allowed_types`
- [ ] Check engagement tracking covers the new flow's events

## Checklist — Debugging Bell Count Issues

- [ ] Check `BellNotificationHelper::getBellCount()` breakdown — which source is off?
- [ ] For notifications: verify `is_read` / `is_archived` flags in `notifications` collection
- [ ] For announcements: check `announcement_deliveries` for correct scope filter (GLOBAL = user-level only)
- [ ] Badge includes unacknowledged critical/requires_ack — check `display_rules` and `category`
- [ ] Verify visibility filter: `AnnouncementHelper::getVisibilityFilter()` checks status=published + not expired + scope match
