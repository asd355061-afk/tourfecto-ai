# Business Control Center — Phases 20–24 Changelog

**Branch:** `feat/business-control-center`
**Date:** 2026-08-17
**Scope:** DB quality pass, validation hardening, API design review, Frontend UX, Notifications expansion

---

## Phase 20 — DB quality pass

`database/migrations/2026_08_15_000058_business_db_quality_fks.sql` — closes 4 FK/index gaps found in the Phase 8–17 tables. **Additive only** (`ADD INDEX` / `ADD CONSTRAINT`, no `DROP`):

| Table / Column | Action | Rationale |
|---|---|---|
| `business_members.user_id` | `ADD INDEX` + FK → `users.id` **ON DELETE CASCADE** | When a joined user's account is deleted, their membership row must go too (no orphan rows / blocked deletes). |
| `business_members.invited_by_user_id` | `ADD INDEX` + FK → `users.id` **ON DELETE SET NULL** | The inviter may delete their account while invitations stay pending — keep the invite, drop the pointer. |
| `business_members.invited_email` | `ADD INDEX` | The accept-invite lookup filters by `invited_email`; without an index that scan grows linearly. |
| `business_api_keys.created_by_user_id` | `ADD INDEX` + FK → `users.id` **ON DELETE SET NULL** | API keys belong to the **Business**, not the creator. If ownership was transferred before the creator deletes their account, the keys must keep working — hence SET NULL, not CASCADE. |

`2026_08_15_000056_create_business_api_keys_table.sql` updated so `created_by_user_id` is `DEFAULT NULL`, consistent with SET NULL.

## Phase 21 — Validation hardening

Backend stays the single source of truth for input validation across six controllers:

- **BusinessController** — `website_url` / `logo_url` validated via `filter_var` with a temporary `https://` prefix when the scheme is missing; `primary_language` / `supported_languages` constrained to ISO 639 (2–3 alpha); timezone validated against `timezone_identifiers_list()`.
- **BusinessLocationController** — `lat` clamped to `[-90, 90]`, `lng` to `[-180, 180]`; new `validateLocationInput(bool $isUpdate)` keeps `name` required on create but optional on update.
- **BusinessTargetMarketController** — `target_languages` values must be ISO 639 (2–3 alpha), not free text.
- **BusinessServiceController** — new `validateTargetArrays()` merged into `store`/`update`; `applyOptionalFields()` filters target arrays to strings only.
- **BusinessAiContextController** — `competitors[]`: every entry must have a non-empty `name` and a valid URL.
- **BusinessBrandSettingsController** — `terminology` accepts a string or `{term, ...}` object; `favicon_url` must be a valid URL.

## Phase 22 — API design review

Consistency pass over the new endpoints (uniform `success`/`error` envelopes, 401/403/404/422/500 statuses) surfaced one design flaw: **API-key and audit-log access were coupled to team-management permission**.

- `BusinessAccessService` gains `CAP_MANAGE_KEYS` and `CAP_READ_AUDIT` capabilities + `canManageKeys()` / `canReadAudit()` helpers (owner + admin).
- `BusinessApiKeyController` now gates on `canManageKeys()` (was `canManageTeam()`) — and its `index` was **also** hardened: viewing the key list is owner/admin only, not every member.
- `BusinessAuditLogController` gates on `canReadAudit()`.
- Capability model is now semantically distinct (`manage_team` vs `manage_keys` vs `read_audit`), future-proof even though owner/admin hold all three today.

## Phase 23 — Frontend UX

New **Business Control Center** panel page at `/business-center` (`BusinessCenterController`) — a single pane that assembles the business module instead of the dashboard stitching six endpoints:

- **Overview tab**: readiness ring + grade, per-category progress bars, 4 quick-stat tiles (locations / services / markets / AI+Brand), top-5 recommended next steps from the AI audit.
- **Profile tab**: create/update the business profile (business-type dropdown populated from `Business::allowedBusinessTypes()` server-side).
- **Team tab**: member list with role/status, invite-by-email (member/viewer/admin), resend pending invite.
- **API Keys tab**: key list (prefix masked), create key with scope + one-time raw key display, revoke.
- **Audit tab**: searchable audit log with pagination and success/fail pills.

Wiring & consistency:
- Web route `GET /business-center` behind `AuthMiddleware`.
- Sidebar entry "Business Center" in the Business Intelligence group (`app/Core/Controller.php` `renderPanelSidebar`).
- All `business_center.*` i18n keys in `en/ar/fr/de`.
- New `bc-*` styles appended to `public_html/assets/css/panel.css` (readiness ring, category bars, alert box).
- Controller registered in `$optionalNewClassFiles` (public_html/index.php).

## Phase 24 — Notifications expansion

New `app/Services/BusinessNotificationService.php` — in-app notifications for business events, split into pure builders (testable offline) + thin `push()` wrapper that calls `Notification::notify` when available and fails silently otherwise:

- **Team events** wired into `BusinessTeamService`: member added (notify the added member), invite sent (notify owner), invite accepted (notify owner), member removed (notify the removed member), role changed (notify the member). `acceptInvite` also refactored to fetch the business once instead of twice.
- **API key events** wired into `BusinessApiKeyService`: create + revoke notify the business owner with the key name.
- All events link back to `/business-center` and use distinct types (`business_team_*`, `business_api_key_*`).
- Registered in `$optionalNewClassFiles`.

New `tests/Unit/Business/BusinessNotificationServiceTest.php` — **8/8** covering every builder payload + `push()` no-op safety.

## Tests

- `tests/Unit/Business/BusinessCenterWiringTest.php` — **new**, 6/6: route registered, sidebar entry, classmap registration, controller exports `index()`, all five API endpoints wired in JS, translation keys complete across 4 languages.
- `tests/Unit/Business/BusinessAccessServiceTest.php` — extended with a `testSensitiveCapabilities()` case for `manage_keys` / `read_audit`; now **8/8**.
- Regressions: `BusinessCenterPhase8912Test.php` 9/9, `BusinessReadinessServiceTest.php` 7/7.
- `php -l` clean on every touched file; route-duplication check clean (the `/sitemap.xml` double registration at HomeController:14 / AssetController:246 is pre-existing and unrelated).
