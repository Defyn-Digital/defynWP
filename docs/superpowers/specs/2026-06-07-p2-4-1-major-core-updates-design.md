# P2.4.1 — Major-Version WordPress Core Updates (Design Spec)

**Date:** 2026-06-07
**Status:** Approved (brainstorming complete §1→§8)
**Predecessor:** P2.4 (minor-only core updates), spec `docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md`, plan `docs/superpowers/plans/2026-06-07-p2-4-core-updates.md`, tag `p2-4-core-updates-complete`
**Successor:** P2.5 — Operator overview dashboard (deferred)
**Spec scope:** Extend the upgrade pipeline so operators can opt a site into major-version WP core updates (e.g. 7.4 → 8.0), with stronger guardrails than the minor path. Tight scope — most P2.4 plumbing carries forward unchanged.

---

## §1. Architecture overview

P2.4 shipped a complete core-update pipeline (connector `CoreUpgraderService` → dashboard `UpdateSiteCore` AS job → REST → SPA `SiteCoreCard`) that **explicitly blocks major version bumps** at two enforcement points:

1. **Connector** — `CoreUpgraderService::upgrade()` throws `MajorUpdateBlockedException` when the target version's major or minor differs from the installed version's major+minor.
2. **Dashboard** — `SitesCoreUpdateController` preflight #4 returns 409 `core.major_update_blocked` before scheduling the job.

P2.4.1 **relaxes both block points** when an operator-set per-site flag is enabled, and adds:

- **Schema v5 → v6** with `core_allow_major TINYINT(1) DEFAULT 0` on `wp_defyn_sites` plus `tested_up_to VARCHAR(20)` on plugin + theme tables (to feed the compat list shown in the confirmation dialog).
- **One new REST endpoint:** `POST /sites/{id}/core/allow-major` (toggle flag on/off, dedicated 10/hr bucket).
- **Connector `$allowMajor` parameter** on `CoreUpgraderService::upgrade()` (default `false`, preserves P2.4 behavior).
- **Dashboard `UpdateSiteCore` job** reads `core_allow_major` from the site row, threads it into the connector request body.
- **SPA SiteCoreCard 5th visual state** ("blocked-major-available") plus a "Manage settings" link, plus a settings row to toggle the flag, plus a red-tier confirmation dialog variant for major upgrades (stop-sign + 3rd compat banner + type-the-version input + red button).

**Architecture choice (per brainstorming):** Approach A — persistent per-site column on `wp_defyn_sites`. Operator toggles once per site, flag persists across upgrade attempts. No per-attempt re-confirmation in addition (that would duplicate the dialog's existing red-tier warning).

**Compatibility check choice:** Approach B — surface tested_up_to data we already collect during plugin/theme inventory, no live api.wordpress.org calls. The dialog shows installed plugins/themes whose `tested_up_to < target` as a soft compat list. **The list does NOT block — operator can proceed anyway, but is informed.**

**Surface choice for "blocked-major-available":** Yes — even when the flag is off, the SPA surfaces "Major update available · Enable in settings" so operators know an upgrade exists without having to dig into Settings.

**Naming consistency:** Connector `/status` response gains an `is_major_update_available` boolean alongside the existing `is_minor_update` field — no need to negate `is_minor_update`.

---

## §2. Schema v5 → v6 migration

Schema bumps to `SCHEMA_VERSION = 6`. Three new columns guarded by `SHOW COLUMNS` ALTER (same idempotent pattern proven through v4 + v5):

### 2.1 `wp_defyn_sites`

```sql
ALTER TABLE wp_defyn_sites
ADD COLUMN core_allow_major TINYINT(1) NOT NULL DEFAULT 0
AFTER last_core_update_attempt_at;
```

No new index — the column is read on a single-site basis (preflight, job dispatch), never queried across sites.

### 2.2 `wp_defyn_site_plugins`

```sql
ALTER TABLE wp_defyn_site_plugins
ADD COLUMN tested_up_to VARCHAR(20) NULL
AFTER update_version;
```

Populated by `PluginListCollector` via `get_file_data($plugin_file, ['Tested up to' => 'Tested up to'])`. `null` if header absent (older plugins).

### 2.3 `wp_defyn_site_themes`

```sql
ALTER TABLE wp_defyn_site_themes
ADD COLUMN tested_up_to VARCHAR(20) NULL
AFTER update_version;
```

Populated by `ThemeListCollector` via `wp_get_theme($slug)->get('TestedUpTo')` (or `get_file_data` fallback). `null` if absent.

### 2.4 Migration tests

- `SchemaVersionMigrationV6Test`:
  - `testSchemaVersionConstantIsSix`
  - `testActivationAddsCoreAllowMajorColumn`
  - `testActivationAddsTestedUpToOnPlugins`
  - `testActivationAddsTestedUpToOnThemes`
  - `testV6MigrationIsIdempotent` (re-running `ensureSchema()` is a no-op)

### 2.5 Self-heal

P2.4's `Activation::maybeRunSelfHeal()` on `plugins_loaded` covers this migration automatically. No manual deactivate+reactivate needed (proven through P2.4 install).

---

## §3. Connector changes (v0.1.6 → v0.1.7)

### 3.1 `CoreUpgraderService::upgrade()` signature

**Before (P2.4):**
```php
public function upgrade(string $targetVersion): array
```

**After (P2.4.1):**
```php
public function upgrade(string $targetVersion, bool $allowMajor = false): array
```

Body change — when `isMinorUpgrade($targetVersion)` returns false AND `$allowMajor === false`, throw `MajorUpdateBlockedException` (existing P2.4 behavior). When false AND `$allowMajor === true`, proceed with the upgrade.

The default `false` preserves P2.4 behavior for any existing callers (defensive — there are none in production, but tests of the unparameterized signature should continue to pass).

### 3.2 `CoreUpdateController::handle()` body parsing

```php
$body = $request->get_json_params();
$allowMajor = isset($body['allow_major']) && $body['allow_major'] === true;
```

Pass through to the service:
```php
$result = $this->upgrader->upgrade($targetVersion, $allowMajor);
```

If `allow_major` is missing or any value other than the literal boolean `true`, treat as `false` (defensive — the contract is "explicit opt-in").

### 3.3 `Collector::collectCore()` extension

Add `is_major_update_available` field alongside the existing `is_minor_update`:

```php
return [
    'wp_version' => $currentVersion,
    'update_available' => $hasUpdate,
    'update_version' => $newVersion ?: null,
    'is_minor_update' => $hasUpdate ? $this->isMinorUpgrade($currentVersion, $newVersion) : false,
    'is_major_update_available' => $hasUpdate ? !$this->isMinorUpgrade($currentVersion, $newVersion) : false,
    'is_minor_auto_update_enabled' => $this->isMinorAutoUpdateEnabled(),
];
```

The dashboard ignores `is_major_update_available` (it derives the same fact from comparing `wp_version` and `update_version`), but the field aids SPA debugging and operator clarity in raw `/status` inspection.

### 3.4 `PluginListCollector::collect()` extension

Add `tested_up_to` per plugin via `get_file_data`:

```php
$headers = get_file_data(
    WP_PLUGIN_DIR . '/' . $pluginFile,
    ['TestedUpTo' => 'Tested up to']
);
$row['tested_up_to'] = $headers['TestedUpTo'] ?: null;
```

Output a normalized string (`'7.4'`, `'8.0.1'`) or `null` if header missing.

### 3.5 `ThemeListCollector::collect()` extension

Same pattern using `wp_get_theme($slug)->get('TestedUpTo')` (returns `false` if absent, normalize to `null`).

### 3.6 Connector tests

- `CollectorPluginsTestedUpToTest::testCollectorEmitsTestedUpToWhenHeaderPresent`
- `CollectorPluginsTestedUpToTest::testCollectorEmitsNullWhenHeaderAbsent`
- `CollectorThemesTestedUpToTest::testCollectorEmitsTestedUpToWhenHeaderPresent`
- `CollectorThemesTestedUpToTest::testCollectorEmitsNullWhenHeaderAbsent`
- `CoreUpgraderServiceTest::testUpgradeAcceptsAllowMajorParamAndProceedsOnMajor`
- `CoreUpgraderServiceTest::testUpgradeDefaultsToBlockedForMajorWithoutAllowFlag` (regression against parameter drift)
- `CoreUpdateTest::testAllowMajorBodyParamPassesThroughToService` (integration — POST `{allow_major: true}` against a major-target stub)
- `CollectorCoreTest::testIsMajorUpdateAvailableTrueWhenMajorBumpPending`
- `CollectorCoreTest::testIsMajorUpdateAvailableFalseWhenMinorOnly`

### 3.7 Version + readme

- `defyn-connector.php`: `Version: 0.1.7`
- `readme.txt`: stable tag bump + changelog entry

```
= 0.1.7 =
* Add per-request `allow_major` opt-in to /core/update for major version upgrades.
* Add `is_major_update_available` field to /status core block.
* PluginListCollector + ThemeListCollector now emit `tested_up_to` from plugin/theme headers.
```

---

## §4. Dashboard changes (v0.5.0 → v0.6.0)

### 4.1 `Activation::SCHEMA_VERSION`

Bump from `5` → `6`. Add three private methods invoked from `ensureSchema()`:

- `addCoreAllowMajorColumn(\wpdb $wpdb): void`
- `addPluginsTestedUpToColumn(\wpdb $wpdb): void`
- `addThemesTestedUpToColumn(\wpdb $wpdb): void`

Same `SHOW COLUMNS LIKE` guarded ALTER pattern as v4 + v5 migrations.

### 4.2 `Models\Site` extension

Add one new readonly property:

```php
public function __construct(
    // ... existing P2.4 fields ...
    public readonly bool $coreAllowMajor = false,
) {}
```

`toJson()` extends to surface `core_allow_major` to the SPA.

### 4.3 `Models\Plugin` + `Models\Theme` extension

Both models add:

```php
public readonly ?string $testedUpTo = null,
```

`toJson()` emits `tested_up_to` to the SPA.

### 4.4 `SitesRepository` extension

Add ONE new method:

```php
public function setCoreAllowMajor(int $siteId, bool $allow): void
{
    $this->wpdb->update(
        $this->table,
        ['core_allow_major' => $allow ? 1 : 0],
        ['id' => $siteId],
        ['%d'],
        ['%d']
    );
}
```

`findById()` selects + hydrates the new column.

### 4.5 `SyncPluginsService::sync` + `SyncThemesService::sync`

Both services persist the new `tested_up_to` field from the connector payload into their respective `_plugins` / `_themes` tables. Null-safe — older connectors not yet upgraded to 0.1.7 will omit the field, in which case write `null`.

### 4.6 `SitesCoreUpdateController` preflight #4 relaxation

Current P2.4 preflight #4:
```php
if (!$isMinorUpgrade) {
    return new \WP_Error('core.major_update_blocked', ...);
}
```

New P2.4.1 logic:
```php
if (!$isMinorUpgrade && !$site->coreAllowMajor) {
    return new \WP_Error(
        'core.major_update_blocked',
        'Major WordPress version upgrades require enabling major updates for this site first.',
        ['status' => 409]
    );
}
```

Note the updated error message (carries the affordance — "enable major updates for this site first" — to the SPA caller).

### 4.7 New `SitesCoreAllowMajorController`

`POST /sites/{id}/core/allow-major`

**Request body:** `{"allow": true|false}`

**Validation:**
- Owner check (404 if not owned)
- Body must include literal boolean (400 if missing or wrong type)

**Side effects:**
- `$repo->setCoreAllowMajor($siteId, $allow)`
- Activity log event: `core_allow_major.toggled` with `{enabled: true|false}`

**Response:** `200 {"site_id": $id, "core_allow_major": true|false}`

### 4.8 `RateLimit` bucket

Add new bucket key:

```php
public const coreAllowMajor = ['key' => 'sites_core_allow_major', 'limit' => 10, 'window' => 3600];
```

10/hr — toggle is cheap, but still bounded. **Different from the 3/hr coreUpdate bucket** — toggling the flag is not an upgrade.

### 4.9 `Jobs\UpdateSiteCore` body extension

Where P2.4 sent:
```php
$body = ['target_version' => $targetVersion];
```

P2.4.1 reads the flag and forwards:
```php
$site = $repo->findById($siteId);
$body = [
    'target_version' => $targetVersion,
    'allow_major' => $site->coreAllowMajor,
];
```

The job ALSO records `allow_major` in the `core_update.started` activity event payload (audit trail — operators can see whether a given attempt was a major or not).

### 4.10 Dashboard tests

**Schema:**
- `SchemaVersionMigrationV6Test::testSchemaVersionConstantIsSix`
- `SchemaVersionMigrationV6Test::testActivationAddsCoreAllowMajorColumn`
- `SchemaVersionMigrationV6Test::testActivationAddsTestedUpToOnPlugins`
- `SchemaVersionMigrationV6Test::testActivationAddsTestedUpToOnThemes`
- `SchemaVersionMigrationV6Test::testV6MigrationIsIdempotent`

**Repository:**
- `SitesRepositoryAllowMajorTest::testSetCoreAllowMajorPersistsTrue`
- `SitesRepositoryAllowMajorTest::testSetCoreAllowMajorPersistsFalse`
- `SitesRepositoryAllowMajorTest::testFindByIdReturnsCoreAllowMajor`

**REST:**
- `SitesCoreAllowMajorTest::testHappyPath200OnEnable`
- `SitesCoreAllowMajorTest::testHappyPath200OnDisable`
- `SitesCoreAllowMajorTest::testNotOwnedReturns404`
- `SitesCoreAllowMajorTest::testInvalidPayloadReturns400`
- `SitesCoreAllowMajorTest::testRateLimit429AfterEleventhCall` (10/hr bucket, exact name)
- `SitesCoreAllowMajorTest::testActivityLogEventEmitted`

**SitesCoreUpdate preflight relaxation:**
- `SitesCoreUpdateTest::testMajorBumpProceedsWhenAllowMajorFlagIsOn`
- `SitesCoreUpdateTest::testMajorBumpStillReturns409WhenFlagIsOff`

**Jobs:**
- `UpdateSiteCoreTest::testJobPassesAllowMajorBodyWhenFlagIsOn`
- `UpdateSiteCoreTest::testJobPassesAllowMajorFalseWhenFlagIsOff`

### 4.11 Version + readme

- `defyn-dashboard.php`: `Version: 0.6.0`
- `readme.txt`: stable tag bump + changelog entry

```
= 0.6.0 =
* Per-site opt-in for major WordPress version upgrades via /sites/{id}/core/allow-major.
* Schema v6: core_allow_major on sites table + tested_up_to on plugins/themes tables.
* UpdateSiteCore job threads allow_major flag to connector on upgrade requests.
```

---

## §5. SPA UI (apps/web)

### 5.1 Zod schema extensions

`apps/web/src/types/api.ts`:

```ts
// On siteSchema:
core_allow_major: z.boolean(),

// On pluginSchema:
tested_up_to: z.string().nullable(),

// On themeSchema:
tested_up_to: z.string().nullable(),
```

### 5.2 `SiteCoreCard` 5th visual state

P2.4 ships 4 states (idle-no-update, idle-update-available, updating, failed). P2.4.1 adds:

**State 5: "blocked-major-available"** — when `core_update_available === true` AND `is_minor_update !== true` (major bump) AND `core_allow_major === false`.

Visual:
- Same card chrome as state 2 (idle-update-available)
- Header copy: **"Major update available — WordPress {target_version}"**
- Subhead: small grey "Major upgrades are disabled for this site"
- **No Update button.** Instead: a "Manage settings" link/button (ghost variant) that scrolls to the new SiteMajorUpdatesSettingsRow.

**State 2 "idle-update-available" UNCHANGED for minor.** State 5 only applies when the available update is a major.

When `core_allow_major === true` AND the update is a major (call it state 5a, "allowed-major-available"):
- Card chrome shifts to **red-tier** (border-red-200 + bg-red-50 — distinct from amber minor)
- Header copy: **"Major update available — WordPress {target_version}"**
- Subhead: red small "Major upgrade — review compatibility before proceeding"
- Primary button: **red** `bg-red-600 hover:bg-red-700` labelled "Update WordPress" → opens the red-tier ConfirmUpdateCoreDialog variant

### 5.3 `SiteMajorUpdatesSettingsRow` (new component)

Inline component on `SiteDetail` route, below the new SiteCoreCard, above the SiteSummaryCard. Renders a single switch row:

```
[switch]  Allow major WordPress upgrades for this site
          When off (default), only minor updates are eligible. When on,
          you can install major versions but compatibility is your responsibility.
```

Switch state bound to `site.core_allow_major`. Toggling calls `useToggleCoreAllowMajor` mutation. Loading spinner during the mutation. Toast on 429 ("Too many setting changes — try again in an hour").

### 5.4 `useToggleCoreAllowMajor` mutation hook

`apps/web/src/lib/mutations/useToggleCoreAllowMajor.ts`:

```ts
export function useToggleCoreAllowMajor(siteId: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (allow: boolean) => {
      const res = await api.post(`/sites/${siteId}/core/allow-major`, { allow })
      return CoreAllowMajorResponseSchema.parse(res.data)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sites', siteId] })
    },
  })
}
```

No polling pin (the response is the new state — TanStack Query refetches `useSite` once and shows it).

### 5.5 `ConfirmUpdateCoreDialog` major variant

When the target version is a major bump (computed from current + target), the dialog renders in **major-variant mode**:

**Header:** 🛑 (stop sign emoji) + "Run MAJOR WordPress upgrade — {current} → {target}"

**Three warning banners** (vs P2.4's two):
1. **Downtime + cache flush** (existing)
2. **Cannot be reversed without a backup** (existing — stronger framing for major)
3. **NEW: Plugin & theme compatibility** — lists installed plugins/themes whose `tested_up_to < target`. If none, soft success line: "All installed plugins & themes report compatibility with {target}."

**Conditional "Auto-updates ON" paragraph** — REMOVED in major variant (auto-updates only apply to minor — this section is irrelevant).

**Type-the-version input:**
```
To confirm this major upgrade, type the target version:
[input placeholder="e.g. 8.0"]
```
Primary button disabled until input matches `core_update_version` exactly.

**Primary button:** red `bg-red-600 hover:bg-red-700`, label **"Yes, run MAJOR upgrade {current} → {target}"** (e.g. "Yes, run MAJOR upgrade 7.4 → 8.0").

**Cancel button:** default focus (same as P2.4).

### 5.6 `SiteDetail` route additions

Below the existing `SiteCoreCard` (which switches its own visual state), the `SiteMajorUpdatesSettingsRow` ALWAYS renders in a small "Site settings" section — not conditional on `core_update_available`. Pre-emptive opt-in is a real operator flow (rolling readiness across a fleet).

### 5.7 MSW handlers for tests

`apps/web/src/test/handlers.ts` extends:
- `POST /sites/:id/core/allow-major` → 200 with new state
- Mocked site responses include `core_allow_major` + plugins/themes include `tested_up_to`

### 5.8 SPA tests

- `siteSchema.test.ts::parses core_allow_major boolean`
- `pluginSchema.test.ts::parses tested_up_to nullable`
- `themeSchema.test.ts::parses tested_up_to nullable`
- `useToggleCoreAllowMajor.test.tsx::postsCorrectEndpointAndPayload`
- `useToggleCoreAllowMajor.test.tsx::invalidatesSitesQueryOnSuccess`
- `useToggleCoreAllowMajor.test.tsx::showsToastOn429`
- `SiteCoreCard.test.tsx::testBlockedMajorAvailableStateRendersManageButton`
- `SiteCoreCard.test.tsx::testAllowedMajorAvailableStateRendersRedUpdateButton`
- `SiteMajorUpdatesSettingsRow.test.tsx::rendersSwitchInCorrectStateForFlagOff`
- `SiteMajorUpdatesSettingsRow.test.tsx::rendersSwitchInCorrectStateForFlagOn`
- `SiteMajorUpdatesSettingsRow.test.tsx::togglesViaMutationOnSwitchClick`
- `ConfirmUpdateCoreDialog.test.tsx::testMajorVariantRendersStopSignAndRedButton`
- `ConfirmUpdateCoreDialog.test.tsx::testMajorVariantShowsCompatList`
- `ConfirmUpdateCoreDialog.test.tsx::testMajorVariantShowsAllCompatibleMessage`
- `ConfirmUpdateCoreDialog.test.tsx::testTypeVersionRequiredToEnableConfirm`
- `ConfirmUpdateCoreDialog.test.tsx::testButtonLabelIncludesFromAndTargetVersions`

---

## §6. Testing strategy

Tight — ~40 new tests target the divergent surfaces only. Most P2.4 pipeline tests carry forward unchanged (the upgrade execution path is identical once the flag check passes).

**Connector:** ~10 tests covering the new `$allowMajor` parameter on `CoreUpgraderService::upgrade()`, the new `allow_major` body parameter on `/core/update`, the `is_major_update_available` field on `/status`, and the `tested_up_to` field on plugin + theme list collectors.

**Dashboard:** ~20 tests covering schema v6 (3 columns + idempotency), `SitesRepository::setCoreAllowMajor()`, new `SitesCoreAllowMajorController` (happy path + ownership + payload validation + rate limit + activity logging), preflight relaxation on `SitesCoreUpdateController`, and `UpdateSiteCore` job threading the `allow_major` body.

**SPA:** ~10 tests covering Zod extensions, `useToggleCoreAllowMajor` hook, the 5th `SiteCoreCard` visual state, the new `SiteMajorUpdatesSettingsRow` component, and the red-tier `ConfirmUpdateCoreDialog` variant (with the type-the-version gating + compat list + button label).

**Coverage gate:** ≥80% on new modules. CI workflow auto-discovers new tests.

**What we explicitly do NOT test:**
- Live compatibility data from api.wordpress.org (Approach C, rejected)
- Auto-disable of `core_allow_major` after a failed major upgrade (operator manages it)
- Per-major-version opt-in granularity (current flag is boolean — applies to all majors)
- Pre-upgrade backup creation (out of scope — phase 3+)

---

## §7. Manual smoke flow

Run after CI is green, before tagging `p2-4-1-major-core-updates-complete`.

### 7.1 Pre-smoke setup

1. Build artifacts (same `composer dump-autoload --no-dev --classmap-authoritative` discipline + vendor exclusions established in P2.4):
   - `~/Desktop/defyn-connector-v0.1.7-<date>.zip` (~70KB)
   - `~/Desktop/defyn-dashboard-v0.6.0-<date>.zip` (~550KB)
2. Install on production via "Replace current with uploaded version".
3. Schema v5 → v6 self-heal auto-runs on first `wp-admin` page load.
4. SmartCoding handshake should persist from P2.4 smoke. If the install accidentally wipes the sites table (WP Uninstaller hook quirk), re-handshake.

### 7.2 Smoke matrix — 10 steps

| # | Action | Expected | Verifies |
|---|---|---|---|
| 1 | `curl GET /sites/1` | Response includes `core_allow_major: false` (default) | Schema v6 + Site model wiring |
| 2 | `curl POST /sites/1/plugins/refresh` then GET `/sites/1/plugins` | Each plugin row has `tested_up_to` (null or version) | Plugin collector + persistence |
| 3 | `curl POST /sites/1/themes/refresh` then GET `/sites/1/themes` | Each theme row has `tested_up_to` | Theme collector + persistence |
| 4 | Manually `UPDATE wp_defyn_sites SET core_update_available=1, core_update_version='8.0' WHERE id=1` then `curl GET /sites/1` | `core_update_available=true`, `core_update_version=8.0`, `core_allow_major=false` | Setup for downstream steps |
| 5 | `curl POST /sites/1/core/update` | `409 core.major_update_blocked` with new message ("require enabling major updates for this site first") | Preflight #4 relaxation respects flag-off state |
| 6 | `curl POST /sites/1/core/allow-major -d '{"allow":true}'` | `200 {"site_id":1,"core_allow_major":true}` | New REST endpoint works |
| 7 | `curl GET /sites/1/activity` | `core_allow_major.toggled enabled=true` event present | Activity logging |
| 8 | `curl POST /sites/1/core/update` (after #6) | `202 {"scheduled":true, ..."core_update_state":"queued"}` — preflight passes | Flag-on relaxes preflight #4 |
| 9 | Tick wp-cron, then `curl GET /sites/1/activity` | `core_update.requested → core_update.started → core_update.{succeeded\|failed}` triplet. `core_update.started` payload includes `allow_major: true` | Full pipeline relays flag to connector |
| 10 | SPA at `app.defynwp.defyn.agency/sites/1` → SiteCoreCard | Flag OFF + major available → "Major update available · Enable in settings" + Manage button (no Update). Flag ON → red Update button. Click → red-tier dialog with stop-sign + 3 banners + compat list + type-the-version input + red confirm button | SPA divergent UI |

### 7.3 Cleanup after smoke

```sql
UPDATE wp_defyn_sites
SET core_update_available=0,
    core_update_version=NULL,
    core_allow_major=0
WHERE id=1;
```

(Or via SPA: toggle Allow-major off, then `curl POST /sites/1/core/refresh` to clear the synthetic state.)

### 7.4 Tag + push

```
git tag -a p2-4-1-major-core-updates-complete -m "P2.4.1 — major-version WP core updates shipped"
git push origin p2-4-1-major-core-updates-complete
```

**Tag only after all 10 smoke steps + cleanup pass.**

---

## §8. Deliberately out of scope

| Deferred to | What's not in P2.4.1 |
|---|---|
| **P2.5 (next phase)** | Operator overview dashboard — cross-site aggregate of pending updates |
| **Future** | Per-major-version opt-in (e.g. allow 7→8 only, block 8→9) — current flag is boolean across all majors |
| **Future** | Auto-disable `core_allow_major` after failed major upgrade — operator manages manually |
| **Future** | `api.wordpress.org/plugins/info` live compatibility lookup (Approach C, rejected) |
| **Future** | Email/Slack notification when major update becomes available |
| **Future** | Pre-upgrade backup creation (Phase 3+) |
| **Future** | "Requires WP" header vs "Tested up to" header — currently surfacing "Tested up to" (more conservative signal) |
| **Future** | Historic upgrade outcome UI (currently lives in activity log, no dedicated panel) |

---

## §9. Plan-author notes (carry-overs for writing-plans)

**Branch off `p2-4-core-updates` (NOT main).** P2.4 isn't merged yet. Schema v6 builds on v5 from P2.4. Branch name: `p2-4-1-major-core-updates`.

**Plan-bug traps to brief implementers about up front** (learned from P2.4 execution):

1. `RateLimit::coreAllowMajor` is **10/hr** — test method MUST be `testRateLimit429AfterEleventhCall` (NOT 4th like coreUpdate, NOT 7th like themes — common copy-paste trap).

2. `CoreUpgraderService::upgrade(string $targetVersion, bool $allowMajor = false)` — the default `false` is **mandatory** for backward compatibility (P2.4 callers pass single arg). Test the default explicitly with `testUpgradeDefaultsToBlockedForMajorWithoutAllowFlag`.

3. `CoreUpdateController` body parsing: `$allowMajor = isset($body['allow_major']) && $body['allow_major'] === true;` — strict `=== true` check, NOT truthy. Defends against `'true'` strings, `1`, etc. coming through.

4. Activity event names: `core_allow_major.toggled` (NOT `site.allow_major.changed`, NOT `core.allow_major.toggled` — exact match expected by SitesCoreAllowMajorTest).

5. `Models\Plugin::testedUpTo` and `Models\Theme::testedUpTo` are **nullable string** — older connector versions or plugins without the header emit `null`. Tests of toJson() must cover both branches.

6. The `SitesCoreUpdateController` preflight relaxation MUST short-circuit `!isMinor && !coreAllowMajor` — implementers may accidentally write `!isMinor || !coreAllowMajor` (wrong condition, blocks even when flag is on).

7. `UpdateSiteCore` job body: `'allow_major' => $site->coreAllowMajor` — pass the boolean directly, NOT `(int)` cast. Connector expects boolean and the controller's `=== true` check rejects ints.

8. SPA `ConfirmUpdateCoreDialog` major-variant: the type-the-version input compares against `core_update_version` exactly (case-sensitive, no whitespace trim). Test asserts disabled state with mismatched input.

9. The SPA `SiteMajorUpdatesSettingsRow` ALWAYS renders below SiteCoreCard on SiteDetail (per §5.6 decision) — not conditional on `core_update_available`. Pre-emptive opt-in flow.

10. Connector zip build: same lessons as P2.4 — `composer dump-autoload --no-dev --classmap-authoritative` FIRST, exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + dev packages. Target ~70KB connector + ~550KB dashboard.

11. Final task (smoke matrix) is §7.2 verbatim — 10 steps. Tag `p2-4-1-major-core-updates-complete` only after all 10 pass + cleanup.

**Estimated plan size:** ~12 TDD tasks across 5 phases (Connector, Dashboard schema + models + repository, Dashboard REST + jobs, SPA, Ship). Mirrors P2.4's pattern at smaller scale.

---

## §10. Acceptance criteria (recap)

P2.4.1 is shipped when:

- [ ] All ~40 new tests green in CI
- [ ] Connector v0.1.7 + Dashboard v0.6.0 zips built per §7.1 discipline
- [ ] Production install via "Replace current with uploaded version" succeeds; schema v6 self-heals
- [ ] Smoke matrix §7.2 steps 1-10 all green
- [ ] Cleanup §7.3 applied
- [ ] Tag `p2-4-1-major-core-updates-complete` pushed
- [ ] MEMORY.md updated with any plan-bug lessons surfaced during execution
