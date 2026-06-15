# P4.3b — Dismiss / Ignore Security Findings — Design

**Status:** Approved 2026-06-15
**Phase:** 4 (Security scanning) — the LAST slice, completing the arc P4.1 (Detect & Show) → P4.2 (`/security` fleet page) → P4.3a (new-finding alerting) → **P4.3b (dismiss/ignore)**.
**Branch:** `p4-3b-dismiss-findings` off `main` (tip `ab65041`, P4.3a merged).
**Versions:** dashboard `v0.15.0` → `v0.16.0`; connector **unchanged** (`v0.1.7`); schema **v11 → v12** (first bump since P4.1).
**Predecessor spec:** `docs/superpowers/specs/2026-06-15-p4-3a-security-alerting-design.md`.

---

## 1. Problem

P4.1 detects per-site vulnerabilities and snapshots them into `wp_defyn_site_vulnerabilities` (wiped + rebuilt every scan). P4.2 rolls them up into the `/security` fleet page. P4.3a alerts the owner when a **new** finding appears (snapshot-vs-snapshot diff, fingerprint `type|slug|source_id`, fires once).

But an operator often has findings they've **assessed and accepted** — a false positive, a back-ported fix the version string doesn't reflect, or an acceptable risk. Today those findings:
- keep inflating the at-risk counts on `/security`,
- clutter the per-site `SiteSecurityPanel`, and
- (worst) **re-alert via P4.3a every time they leave and re-enter the snapshot** (e.g. the feed momentarily drops the advisory, then re-adds it).

P4.3b lets the operator **dismiss** a finding per-site, which (a) moves it out of the active view into a muted "Dismissed" section, (b) excludes it from the fleet at-risk counts, and (c) permanently excludes it from P4.3a's new-finding alert diff. Dismissals are reversible (**Restore**).

## 2. Approved decisions (from brainstorm)

| Decision | Choice |
| --- | --- |
| Dismissed-findings UI treatment | **B — collapsed "Dismissed (N)" section** below the active severity groups, with per-row Restore. |
| Fleet at-risk counts | **Exclude dismissed.** A site whose only findings are all dismissed reads clean (0 at-risk). |
| Dismiss/restore interaction | **One-click toggle, no dialog, no reason note** (reversible → low-risk, like the P3.3 mute toggle). |
| Surface scope | **Per-site panel only.** No fleet-level dismissed-management view this slice. |
| Storage architecture | **Read-time overlay** — a separate dismissals table; the scan snapshot is untouched; reads exclude/flag dismissed. |

## 3. Architecture — read-time overlay

The crux: the snapshot is replace-for-site each scan, so dismissal state lives in a **separate, stable table** keyed by the per-site fingerprint `type|slug|source_id` (the same identity P4.3a uses — `source_id` is the Wordfence advisory UUID, so a dismissal sticks to a specific advisory on a specific site). Every read path consults this overlay:

- **Per-site panel read** → each finding tagged `dismissed: bool`; SPA splits active vs dismissed.
- **Fleet rollup** → dismissed findings dropped from the conditional severity SUMs/COUNT (`NOT EXISTS`).
- **Alert diff** → dismissed fingerprints skipped when building `$newFindings`.
- **Restore** → delete the overlay row; the finding is still in the snapshot, so it reappears in the active view instantly with **no rescan**.

Rejected: write-time removal (strip dismissed from the snapshot during scan) — restore would need a rescan, the snapshot would stop reflecting "installed & vulnerable," and the Dismissed section would have no data.

### Fingerprint stability note
Because the fingerprint includes `source_id` (advisory UUID) but **not** `installed_version` or `severity`, a dismissal is **sticky for that advisory on that site**: a severity escalation or version bump on the *same* advisory stays dismissed; a *genuinely new* advisory (new UUID) gets a fresh fingerprint and therefore re-alerts and shows as active. This matches the "accepted-risk for this specific advisory" intent and needs no expiry logic.

## 4. Schema (v11 → v12)

New table `wp_defyn_dismissed_vulnerabilities` (new `Schema\DismissedVulnerabilitiesTable implements SchemaTable`):

```sql
CREATE TABLE {prefix}defyn_dismissed_vulnerabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(10) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    source_id VARCHAR(64) NOT NULL,
    dismissed_by BIGINT UNSIGNED NOT NULL,
    dismissed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_dismissed_fp (site_id, type, slug, source_id),
    KEY idx_dismissed_site (site_id)
) {charset};
```

- Append `DismissedVulnerabilitiesTable::class` to `Activation::TABLES`; bump `Activation::SCHEMA_VERSION` **11 → 12**. Created via `dbDelta` (brand-new table — **no guarded ALTER** needed). Self-heal on `plugins_loaded` installs it on silent upgrade.
- **Version-pin tests** that assert `SCHEMA_VERSION` must bump to 12 (the existing V*/Uninstall carry-forward set — keep them updated, not silently carry-forwarded).
- `Uninstaller` drops the table.
- Site-delete cleanup: wherever site deletion already cascades per-site rows (the same place P4.1 cleans `site_vulnerabilities`), add a `DELETE FROM defyn_dismissed_vulnerabilities WHERE site_id = …`.

Dates are UTC `Y-m-d H:i:s` (`gmdate`), consistent with the whole project.

## 5. Repository — `Services\DismissedVulnerabilitiesRepository`

```
dismiss(int $siteId, string $type, string $slug, string $sourceId, int $userId, string $now): void
    // idempotent INSERT — INSERT ... ON DUPLICATE KEY UPDATE dismissed_at = VALUES(dismissed_at)
    //   (or INSERT IGNORE); a repeat dismiss is a no-op-ish refresh, never an error.

restore(int $siteId, string $type, string $slug, string $sourceId): void
    // DELETE by the 4-tuple; idempotent (deleting a non-existent row is fine).

findFingerprintsForSite(int $siteId): array
    // returns ['type|slug|source_id' => true, ...] for fast membership tests
    //   in the read-split, scan-diff, and validation paths.
```

Like the other explicit-transaction repos, integration tests that seed rows must purge in `setUp` (guardrail #15) — but this table uses plain `$wpdb->insert`/`delete` (no explicit `START TRANSACTION`), so standard `WP_UnitTestCase` rollback applies; still, since it's read alongside the explicit-COMMIT `site_vulnerabilities`, tests that seed both should purge both for a clean slate.

## 6. Read path

### `SiteVulnerability::toJson`
Add two fields:
- `source_id` (already a DTO property `sourceId` — currently NOT emitted; expose it so the client can build the fingerprint for the dismiss call).
- `dismissed: bool` — **not** a DB column; injected at read time. Give the DTO an immutable `withDismissed(bool): self` helper (or include `dismissed` as a nullable-defaulting constructor field), so `toJson` can emit it. Default `false`.

### `SiteVulnerabilitiesRepository::findForSite`
After loading the snapshot rows, fetch `DismissedVulnerabilitiesRepository::findFingerprintsForSite($siteId)` and tag each `SiteVulnerability` with `dismissed = isset($fps[$fp])`. Return the enriched list (active + dismissed together — the SPA splits them). `SitesVulnerabilitiesController`'s envelope is unchanged in shape; each `vulnerabilities[]` element now carries `source_id` + `dismissed`.

### `SiteVulnerabilitiesRepository::findFleetSummariesForUser` (P4.2 rollup)
Exclude dismissed from every per-severity SUM and the total COUNT. Add to the `LEFT JOIN {site_vulnerabilities} sv` either a correlated `NOT EXISTS` against `defyn_dismissed_vulnerabilities` or a `LEFT JOIN … IS NULL` anti-join on the `(site_id, type, slug, source_id)` tuple, so a dismissed finding contributes 0 to `critical/high/medium/low/total`. A site with only-dismissed findings then reports zero counts (and is still scanned, so it reads "clean", not "never scanned").

## 7. Alert-diff exclusion — `VulnerabilityScanService::scan`

The P4.3a diff loads prior fingerprints, builds `$newFindings` = results whose fp wasn't prior. P4.3b adds: load `DismissedVulnerabilitiesRepository::findFingerprintsForSite($siteId)` and **skip any result whose fp is dismissed** when building `$newFindings`. Effect: a dismissed finding that leaves + re-enters the snapshot never fires `notifyNewVulnerabilities`. Order: dismissed-skip happens inside the same loop that builds `$newFindings`/`$newCounts` (before the fp is counted as new).

`site.vulnerabilities_detected` keeps its **raw** total (the factual on-disk scan count) — the overlay applies only to at-risk/display/alert surfaces. This is a deliberate choice (confirmed in brainstorm): the detected-event is a scan fact; dismissal is a presentation/alert overlay.

## 8. REST — one toggle endpoint

`POST /defyn/v1/sites/{id}/vulnerabilities/dismiss` (new `Rest\SitesVulnerabilitiesDismissController`), mirroring the P3.3 mute toggle shape.

- **Body:** `{ "type": "plugin|theme|core", "slug": string, "source_id": string, "dismissed": bool }`.
- **Auth/ownership:** 404 `sites.not_found` when the site isn't owned by the authenticated user (via `SitesRepository::findByIdForUser`).
- **Validation (dismiss=true):** the `(type, slug, source_id)` must correspond to a finding in the **current** snapshot for that site → else `400 vulnerabilities.unknown_finding`. (Prevents dismissing arbitrary fingerprints; restore skips this check so a stale row can always be cleared.)
- **dismissed=true** → `DismissedVulnerabilitiesRepository::dismiss(...)`, emit `site.vulnerability_dismissed`.
- **dismissed=false** → `restore(...)`, emit `site.vulnerability_restored`.
- **Activity details:** `{ type, slug, source_id, component_name }` (component_name resolved from the snapshot row for readability; null-safe).
- **Response:** `200` enveloped `{ data: { dismissed: bool }, error: null }`.
- **Rate limit:** new `RateLimit::vulnerabilitiesDismiss` — **30/HOUR** per user (dismiss is cheap + reversible; an operator may triage several findings in a sitting). Distinct bucket from the 6/hr scan + 30/min read.
- **CORS:** add the route to the CORS allow-list regression test (the project pattern — every new route gets a CORS assertion).
- Register in `RestRouter`.

## 9. SPA

### Schema (`apps/web/src/types/api.ts`)
`vulnerabilitySchema` gains `source_id: z.string()` and `dismissed: z.boolean()`. (MSW fixtures + any existing vulnerability fixtures updated to include both.)

### `SiteSecurityPanel`
- Split `vulnerabilities` into `active = vulns.filter(v => !v.dismissed)` and `dismissed = vulns.filter(v => v.dismissed)`.
- **Active**: render in the existing severity groups; each row gets a per-row dismiss affordance (`ti`-style eye-off / "Dismiss" button) calling the mutation with `dismissed: true`.
- **Dismissed**: a collapsed **"Dismissed (N)"** section below the active groups — muted/struck rows, each with a **Restore** action (`dismissed: false`). Hidden entirely when `dismissed.length === 0`.
- **Meta line** counts **active only** (e.g. "1 vulnerability · scanned 2m ago" reflects active; optionally append "· N dismissed"). The `isClean` state = `scanned && active.length === 0` (dismissed-only sites show clean + a Dismissed section).
- Row key must include `source_id` (today it's `${slug}-${type}`, which collides when a component has multiple advisories) → use `${type}|${slug}|${source_id}`.

### Mutation (`useDismissVulnerability(siteId)`)
`POST /sites/{id}/vulnerabilities/dismiss` with `{ type, slug, source_id, dismissed }`; on success invalidate `['siteVulnerabilities', siteId]`. (Optimistic update optional; invalidation is sufficient and matches the project's mutation pattern.)

## 10. Activity events

- `site.vulnerability_dismissed` — details `{ type, slug, source_id, component_name }`.
- `site.vulnerability_restored` — same details shape.

No central SPA event-label map exists (confirmed in P4.3a), so these surface generically in the activity tail — **no SPA event-label work**.

## 11. Testing

- **Repository:** dismiss is idempotent (double-dismiss = one row); restore deletes; `findFingerprintsForSite` returns the right keyed set; restore of a non-existent row is a no-op.
- **Scan diff:** a dismissed fingerprint that reappears in the snapshot does **not** appear in `$newFindings` → no `notifyNewVulnerabilities`, no `site.new_vulnerabilities` for it. A non-dismissed new finding still alerts.
- **Fleet rollup:** `findFleetSummariesForUser` excludes dismissed from counts; a site whose only findings are dismissed reports zero counts + non-null `last_security_scan_at` (clean, not never-scanned).
- **Read enrichment:** `findForSite` tags `dismissed` correctly; `toJson` emits `source_id` + `dismissed`.
- **Controller:** dismiss inserts + 200; restore deletes + 200; unknown fingerprint on dismiss → 400; non-owned site → 404; no-auth → 401; rate-limit → 429; emits the right activity events.
- **SPA:** panel splits active/dismissed; dismiss button calls mutation with `dismissed:true`; Restore calls `dismissed:false`; Dismissed section hidden at 0; meta line counts active only; `isClean` true for dismissed-only site.
- **Schema:** version-pin tests bump to 12; Uninstaller drops the new table.
- **Carry-forward tolerances:** PHP 1 (`UninstallTest`) + SPA 4 (SiteDetail×2 + SiteCoreCard×2). The schema-version-pin tests are **updated**, not added to carry-forward.

## 12. Release

Dashboard `v0.16.0`; connector unchanged; schema v12. Symfony + json-machine-preserving zip build → repo-root `dist/defyn-dashboard-0.16.0.zip`. SPA build + Cloudflare deploy (this slice **does** touch the SPA). Merge to `main`, manual Kinsta install + cache clear (schema self-heal applies v12), indirect curl smoke (security surface still 200/401/404; dismiss endpoint reachable — `POST /sites/999999/vulnerabilities/dismiss` → 404; no-auth → 401), tag `p4-3b-dismiss-findings-complete`, MEMORY. End-to-end dismiss/restore happy path is foreclosed by the zero-sites + no-API-key prod state (covered by green tests).

## 13. Guardrails

1. **No connector change**, no connector version bump, no re-handshake.
2. **Snapshot untouched** — the scan still replace-for-site writes every finding; dismissal is a pure read-time overlay. Restore never needs a rescan.
3. **Dismissed never re-alerts** — the scan diff skips dismissed fingerprints (the core promise of this slice).
4. **Fleet + panel exclude dismissed; the raw `vulnerabilities_detected` total does not** (deliberate).
5. **Idempotent dismiss/restore** — repeat calls are safe; validation only gates dismiss-of-unknown-fingerprint.
6. **Per-site scope** — the same advisory on two sites is dismissed independently (fingerprint includes `site_id`).
7. **Schema via self-heal** (`SCHEMA_VERSION=12` + `TABLES` entry); version-pin tests bump; Uninstaller + site-delete clean the new table.
8. **One toggle endpoint** (dismiss/restore via a `dismissed` bool), 30/hr write bucket, CORS-tested, ownership-gated.
