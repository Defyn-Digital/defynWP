# P4.3a — New-finding Security Alerting — Design

**Status:** Approved (brainstorm 2026-06-15)
**Phase:** P4.3a — third slice of Phase 4 (Security scanning), first half of P4.3. Roadmap: Monitoring (DONE) → Security scanning [P4.1 DONE → P4.2 DONE → **P4.3a** → P4.3b] → Reporting (no Backups).
**Branch:** `p4-3a-security-alerting` (off `main` @ the P4.2 merge `7f199ed`)
**Footprint:** Dashboard plugin only (one optional SPA label). **Connector unchanged** (v0.1.7). **Schema UNCHANGED (v11)** — no migration. Dashboard **v0.14.0 → v0.15.0**.

---

## 1. Goal

When a security scan turns up a **genuinely-new** vulnerability on a managed site, email/Slack the site owner a single digest of what's new — reusing the P3.3 notification machinery and the existing per-site mute. "New" = present in this scan's findings but **not** in the immediately-prior snapshot, so a finding alerts **exactly once** when it first appears (no nagging, no `alerted_at` bookkeeping). **Alerting only; per-finding dismiss/ignore is the next slice (P4.3b).**

## 2. What already exists (reused, not rebuilt)

- **The scan + snapshot:** `Services\VulnerabilityScanService::scan(int $siteId)` (P4.1) builds `$results` (the current findings), calls `SiteVulnerabilitiesRepository::replaceForSite($siteId, $results, $now)` (a wholesale wipe+insert), `SitesRepository::markSecurityScannedAt`, then emits `site.vulnerabilities_detected` activity. Constructor takes nullable-injected deps with prod defaults. **The prior snapshot is readable via the existing `SiteVulnerabilitiesRepository::findForSite($siteId): SiteVulnerability[]`** — called BEFORE `replaceForSite` it returns the previous scan's findings (the diff baseline).
- **A finding's stable identity:** `(type, slug, source_id)` — `source_id` is the Wordfence vuln UUID (stable across re-scans), `type`+`slug` pin the affected component. `SiteVulnerability` exposes `->type`, `->slug`, `->sourceId`, `->severity`, `->componentName`, `->installedVersion`, `->cve`, `->fixedIn`, `->title`.
- **The notifier machinery (P3.3):** `Notify\Notifier` interface (`notifyDown`/`notifyRecovered`/`notifySslExpiring`) + `EmailNotifier` (resolves owner email via `get_userdata(site->userId)->user_email`, `wp_mail`, swallows Throwable) + `SlackNotifier` (owner webhook via `get_user_meta(site->userId,'defyn_slack_webhook_url')`, `wp_remote_post`, swallows Throwable) + `MultiNotifier` (fans out to `[EmailNotifier, SlackNotifier]`, each channel isolated via its `each()` try/catch).
- **The mute gate (P3.3):** `Site->alertsMuted` (the `alerts_muted` column) + the `IncidentService` pattern `if (!$site->alertsMuted && $this->safeNotify(...))`. **Reused as-is** — the existing per-site mute toggle silences security alerts too. No new mute column/endpoint/UI.
- **Activity:** `Services\ActivityLogger::log(?userId, ?siteId, eventType, ?details)`. The SPA Activity feed renders activity rows.

## 3. Scope

**In scope (P4.3a):**
- A `notifyNewVulnerabilities` method on the `Notifier` interface + its three implementations (Email, Slack, Multi).
- A snapshot-vs-snapshot diff inside `VulnerabilityScanService::scan` + a mute-gated, best-effort digest alert on the new findings.
- A `site.new_vulnerabilities` activity event (recorded whenever there are new findings; carries an `alerted` flag).
- Dashboard version bump v0.15.0.
- (Optional, plan-time) a one-line SPA friendly label for the new activity event if the activity renderer uses a known-event label map.

**Out of scope (P4.3b / later):**
- Per-finding dismiss/ignore (the `wp_defyn_security_dismissals` table + read-exclusion + dismiss UI) → **P4.3b**.
- Any schema change, any connector change, any new REST endpoint, any new mute surface.
- Configurable alert thresholds / per-severity opt-in. Digest-only, all-severities, fire-once.

## 4. The diff — snapshot-vs-snapshot

In `scan`, immediately **before** `replaceForSite`:
```php
$priorFingerprints = [];
foreach ($findings->findForSite($siteId) as $prior) {
    $priorFingerprints[$prior->type . '|' . $prior->slug . '|' . $prior->sourceId] = true;
}
```
After building `$results` (the current findings array), compute the new set:
```php
$newFindings = [];
foreach ($results as $r) {
    $fp = $r['type'] . '|' . $r['slug'] . '|' . $r['source_id'];
    if (!isset($priorFingerprints[$fp])) {
        $newFindings[] = $r;
    }
}
```
- **First-ever scan** (empty prior snapshot) → every finding is "new" → one baseline digest. Intentional: the owner should be told about pre-existing vulnerabilities, and it is still a single message per site.
- A finding that is fixed (drops out of the snapshot) and later reappears alerts again — acceptable and correct (it genuinely re-appeared).
- The fingerprint deliberately excludes `installed_version`: a vuln that stays present while the component version drifts is still "the same finding", not a new one.

## 5. The notifier method

`Notify\Notifier` gains:
```php
/**
 * @param list<array{type:string,slug:string,component_name:string,installed_version:string,severity:string,cve:?string,fixed_in:?string}> $newVulnerabilities
 * @param array{critical:int,high:int,medium:int,low:int} $severityCounts
 */
public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void;
```
Implemented in all three:
- **`EmailNotifier`** — subject: `🔒 {N} new {vulnerability|vulnerabilities} on {site->label}` (N = count). Body: an intro line (`{N} new security {finding(s)} on {label} ({url})`) + a severity summary (`Critical: X · High: Y · Medium: Z · Low: W`, omitting zero buckets) + one line per finding grouped by severity desc: `[{severity}] {component_name} ({type}) {installed_version}` + ` → fix {fixed_in}` when present + ` · {cve}` when present. Resolved owner email; swallow Throwable; reuse the existing `ownerEmail`/`send` helpers' shape.
- **`SlackNotifier`** — one `wp_remote_post` message: `🔒 *{N} new vulnerabilit{y/ies}* on *{label}* — {url}` + a newline-joined finding list (same per-line format). Owner webhook; swallow Throwable.
- **`MultiNotifier`** — `$this->each(fn (Notifier $n) => $n->notifyNewVulnerabilities($site, $newVulnerabilities, $severityCounts))` (per-channel isolation as for the other methods).

`$newVulnerabilities` is the plain finding arrays from `$newFindings` (the scan's row shape); the notifier reads `type`/`slug`/`component_name`/`installed_version`/`severity`/`cve`/`fixed_in`. Sort within the notifier by severity desc (critical→low→unknown) for stable, readable output.

## 6. `VulnerabilityScanService::scan` changes

- **Constructor:** add `private readonly ?Notifier $notifier = null` (prod default `new MultiNotifier()`), mirroring the other nullable-injected deps.
- **Flow (additions in bold):**
  1. load site; return if null (unchanged).
  2. **load prior fingerprints via `findings->findForSite($siteId)` (before replace).**
  3. build `$results` (unchanged).
  4. **compute `$newFindings` (§4).**
  5. `replaceForSite` + `markSecurityScannedAt` (unchanged).
  6. compute `$counts` (unchanged) + emit `site.vulnerabilities_detected` (unchanged).
  7. **if `$newFindings !== []`:** compute `$newCounts` (severity breakdown of the NEW findings); `$alerted = false`; **if `!$site->alertsMuted`:** wrap in try/catch (`safeNotify`-style — log + swallow, NEVER rethrow into the AS loop) → `$notifier->notifyNewVulnerabilities($site, $newFindings, $newCounts)` → `$alerted = true`; then emit `site.new_vulnerabilities` activity (`details: { new_count, critical, high, medium, low, alerted }`).
- **Ordering guarantee:** the alert/event step is the LAST thing in `scan` and is fully guarded — a throwing notifier can never prevent `replaceForSite` / `markSecurityScannedAt` / the `vulnerabilities_detected` event (those already ran). The scan always completes.

## 7. Activity event

- New event type `site.new_vulnerabilities`, emitted **only when `$newFindings` is non-empty**, with `details = { new_count:int, critical:int, high:int, medium:int, low:int, alerted:bool }`.
- Recorded **regardless of mute** (audit trail: "we detected N new vulns"), with `alerted:false` when the site is muted or the send was skipped/failed. `alerted:true` only when an unmuted send was attempted.
- This is additive to the existing per-scan `site.vulnerabilities_detected` event (which still fires every scan).

## 8. Error handling & edge cases

- **Best-effort alert:** the notify call is wrapped so it never throws into the daily `SecurityScan` AS job. Email/Slack notifiers are already individually best-effort; the scan-level try/catch is belt-and-suspenders so a programming error in the new path can't break scanning.
- **Muted site:** no email/Slack sent; the `site.new_vulnerabilities` event is still recorded with `alerted:false`.
- **No new findings:** no digest, no `site.new_vulnerabilities` event (the per-scan `vulnerabilities_detected` event is unaffected).
- **No owner email / no Slack webhook:** the respective notifier no-ops (existing behavior); a missing email is not an error.
- **First-ever scan:** alerts the full baseline once (one digest). Subsequent scans alert only genuine deltas.
- UTC throughout (`gmdate`).

## 9. Testing

**PHP (dashboard):**
- **Diff:** seed a prior snapshot via `replaceForSite`, run `scan` with a stubbed candidate/vuln set that yields the prior findings PLUS one new finding → assert only the new finding is passed to the notifier (use a `SpyNotifier` capturing `notifyNewVulnerabilities` args). A finding present in both snapshots is NOT re-alerted. Test isolation: explicit-COMMIT repos leak (guardrail #15) — purge `defyn_site_vulnerabilities` + `defyn_sites` in `setUp`; seed sites via `$wpdb->insert` (no `SitesRepository::create()`).
- **Mute gate:** muted site → notifier NOT called, but a `site.new_vulnerabilities` activity row exists with `alerted:false`. Unmuted → notifier called once + `alerted:true`.
- **Best-effort:** a `SpyNotifier` whose `notifyNewVulnerabilities` throws → `scan` still completes, `replaceForSite` applied, `markSecurityScannedAt` stamped, no exception propagates.
- **First scan baseline:** empty prior + 2 findings → both are "new" → one notify call with 2 findings.
- **No new findings:** prior == current → notifier not called, no `site.new_vulnerabilities` row.
- **Notifier content:** `EmailNotifier`/`SlackNotifier` unit tests (mock `wp_mail` via the `pre_*`/filter hook or a captured-args spy, mock `pre_http_request` for Slack) assert the subject count + that each new finding's component/severity appears + severity-summary line; owner-resolution + best-effort swallow.
- `Notify\Notifier` interface change ripples: any existing test `Notifier` stub/spy (e.g. in `IncidentServiceTest`) must implement the new method — update them (compile-time catch).

**SPA (apps/web):** only if the optional activity label is added — a tiny assertion that the new event type renders a friendly label. Otherwise none (P4.3a is backend). Carry-forward tolerated: PHP 1 (`UninstallTest`); SPA 4 (SiteDetail×2 + SiteCoreCard×2).

## 10. Release

- Dashboard version bump **v0.14.0 → v0.15.0**. **Schema unchanged (v11)** — no migration, no version-pin test bumps. **Connector unchanged** (v0.1.7).
- Symfony + `halaxa/json-machine` preserving zip (top-level `dashboard-plugin/`, repo-root `dist/`); SPA auto-deploys via Cloudflare from `main` (only if the optional label was added — otherwise the SPA bundle is unchanged but a rebuild is harmless).
- Production smoke (API curl only; login JWT field is **`access_token`**): the alerting is server-side (driven by the scan job), so the smoke is necessarily indirect — confirm the new code didn't break the existing security surface (`GET /security` 200, `POST /sites/{id}/security/scan` 404 for non-owned, `GET /sites/{id}/vulnerabilities` 401 no-auth). The happy alert path (a real new-finding email) is foreclosed by the zero-sites + no-API-key prod state and is covered by the green PHP tests. Note in MEMORY that end-to-end alerting is verifiable only once the operator sets `DEFYN_WORDFENCE_API_KEY` + has sites.
- Tag `p4-3a-security-alerting-complete`. (Next: **P4.3b** — per-finding dismiss/ignore + read-exclusion + dismiss UI, schema v12.)

## 11. Guardrails (plan-bug traps to surface in the plan)

1. **No schema change, no connector change, no new REST endpoint** — alerting is internal to the scan job; version-pin tests stay at v11.
2. **Best-effort alert NEVER throws into the AS scan loop** — the notify call is the last step in `scan`, fully try/caught; `replaceForSite` + `markSecurityScannedAt` + `vulnerabilities_detected` always run regardless.
3. **Diff is snapshot-vs-snapshot** via fingerprint `(type|slug|source_id)` — load the prior `findForSite` BEFORE `replaceForSite`; fingerprint excludes `installed_version`; a finding alerts exactly once when first seen.
4. **Mute reuses `alerts_muted`** (`!$site->alertsMuted` gate, mirroring `IncidentService`); the `site.new_vulnerabilities` event is recorded even when muted (`alerted:false`), only the SEND is gated.
5. **One digest per scan** — a single `notifyNewVulnerabilities` call listing all new findings, grouped by severity; NOT one message per finding.
6. The `Notifier` interface gains a 4th method — **all existing implementations AND test stubs/spies must implement it** (compile-time ripple; update them).
7. First-ever scan alerts the full baseline once (empty prior → all new); subsequent scans alert only deltas. UTC everywhere.
