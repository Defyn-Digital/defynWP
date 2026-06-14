# Wordfence Intelligence v3 vulnerability feed — pinned contract (P4.1 Task 1)

Research spike for P4.1 Security scanning. Goal: pin the live feed contract so a later
`VulnFeedService` (Task 7 mapper) reads the right JSON keys. **No live keyed pull was
possible** (we have no API key in this environment, by design) — endpoint + auth are
confirmed via the 401 error body; the record schema is reconstructed from Wordfence's own
docs and from three independent open-source projects that consume the v3 feed directly.

Date probed: 2026-06-14/15. Endpoints are live.

---

## 1. Endpoint + auth (DEFINITIVE)

**Use the PRODUCTION feed:**

```
GET https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production
Authorization: Bearer <DEFYN_WORDFENCE_API_KEY>
```

**Scanner feed (slimmer, detection-only superset — see §5):**

```
GET https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner
Authorization: Bearer <DEFYN_WORDFENCE_API_KEY>
```

**Auth mechanism = `Authorization: Bearer <key>` HTTP header.** Not a query param, not Basic.

### Evidence (status codes observed live, no key)

| Request | Status | Body |
|---|---|---|
| `GET v3/vulnerabilities/production` (no auth) | **401** | `{"errors":[{"status":401,...,"detail":"API key must be supplied using a Bearer token."}]}` |
| `GET v3/vulnerabilities/scanner` (no auth) | **401** | same `"API key must be supplied using a Bearer token."` |
| `GET v3/...production` with a **bogus** `Authorization: Bearer not-a-real-key` | 401 (outer) | body changed to `429 "Rate limit exceeded, try again later."` — i.e. the bogus token got *past* the missing-key gate into per-key rate-limiting, proving the header IS the auth channel |
| `GET v3/...production?token=bogus` (query param) | 401 | no change vs no-auth → query param is NOT the auth channel |
| `GET v2/vulnerabilities/production` (old keyless) | **410 Gone** | confirmed dead |
| `GET v2/vulnerabilities/scanner` (old keyless) | **410 Gone** | confirmed dead |

The `"detail":"API key must be supplied using a Bearer token."` string is the load-bearing
quote — Wordfence's own server states the scheme. Corroborated by consumers:
- `szepeviktor/plugin-vulnerability` (`command.php`) hits the exact production URL above and
  sends `'Authorization' => 'Bearer ' . $apiKey` where `$apiKey = getenv('WORDFENCE_API_KEY')`.
- `Chocapikk/wpprobe` and `topscoder/nuclei-wordfence-cve` both use a `WORDFENCE_API_KEY` /
  Bearer token; wpprobe notes "Since March 9, 2026, Wordfence deprecated their v2 API."

> Sources: live `curl` probes; <https://github.com/szepeviktor/plugin-vulnerability>;
> <https://github.com/Chocapikk/wpprobe>; <https://github.com/topscoder/nuclei-wordfence-cve>;
> v3 help page (JS-gated, URL confirmed via search):
> <https://www.wordfence.com/help/wordfence-intelligence/v3-accessing-and-consuming-the-vulnerability-data-feed/>

---

## 2. Free-tier terms

- **Free, registered API key.** Register a free account
  (`https://www.wordfence.com/sign-in/?action=register`), then **Account → Integrations →
  generate API key**. The v3 production AND scanner feeds are available on the **free** key —
  multiple OSS tools (wpprobe, szepeviktor, topscoder) document the free key as sufficient
  for the full bulk download. The key just replaces the old (now-410) keyless access; it is
  **not** a paywall to a paid tier.
- **Commercial use permitted, free.** Wordfence explicitly markets the Intelligence
  vulnerability database as "free for personal and commercial use" with "no limitation on the
  use of this data, other than an attribution requirement for vulnerabilities sourced from
  MITRE." → **local caching in our MySQL is exactly the intended use case.**
  Source: <https://www.wordfence.com/blog/2022/12/wordfence-free-vulnerability-database/>
- **Size:** the production feed is **large — ~117 MB as of Oct 2025** (per
  `typisttech/wordfence-api` README). ~12,000+ vuln records covering 7,600+ plugins/themes.
  **Implication for VulnFeedService:** do NOT `json_decode()` the whole body in memory in one
  go on a shared host (typisttech explicitly warns this exhausts PHP memory). Stream to a temp
  file and parse incrementally, or chunk the insert. Daily download cadence is fine.
- **Rate limits:** a per-key rate limit exists (we tripped a `429 "Rate limit exceeded"` with
  a single bogus token), but the documented daily-full-download pattern is well within it.
  Exact numeric cap not published; one full GET/day is the design intent.

---

## 3. Record JSON schema (for the Task 7 mapper)

**Top-level shape:** a single JSON **object whose keys are the per-vuln UUIDs** Wordfence
assigns. Not an array. Iterate `Object.entries()` / `foreach ($feed as $uuid => $record)`.
(Confirmed for v2 in the Wordfence blog: "the root element being an object where the keys are
UUIDs"; v3 keeps this shape — consumers index by UUID.)

Representative **synthetic** record (field names are real; values invented):

```jsonc
{
  "a1b2c3d4-0000-0000-0000-000000000000": {     // <- this UUID key === our source_id
    "id": "a1b2c3d4-0000-0000-0000-000000000000",
    "title": "Contact Form Plugin <= 4.2.1 - Authenticated (Subscriber+) Stored XSS",
    "description": "Stored XSS in the contact-form plugin ...",
    "software": [
      {
        "type": "plugin",                        // "plugin" | "theme" | "core"  (lowercase)
        "name": "Contact Form Plugin",
        "slug": "contact-form-7",                // wp.org-style slug — our match key
        "affected_versions": {                   // <-- OBJECT keyed by a range-label string,
          "<= 4.2.1": {                          //     NOT an array. Label is human text.
            "from_version": "*",                 // "*" === unbounded lower bound
            "from_inclusive": true,
            "to_version": "4.2.1",
            "to_inclusive": true
          }
        },
        "patched": true,                         // boolean: is a fix available at all
        "patched_versions": ["4.2.2"],           // ARRAY of first-fixed versions (may be [])
        "remediation": "Update to 4.2.2 or later",
        "cve": "CVE-2026-12345"                  // CVE can also live at record level (see below)
      }
    ],
    "cve": "CVE-2026-12345",
    "cve_link": "https://www.cve.org/CVERecord?id=CVE-2026-12345",
    "cvss": {
      "score": "6.4",                            // STRING in feed, e.g. "9.8" — cast to float
      "vector": "CVSS:3.1/AV:N/AC:L/PR:L/UI:R/S:N/C:L/I:L/A:N",
      "rating": "Medium"                         // "Critical"|"High"|"Medium"|"Low" (Title-case)
    },
    "cwe": "CWE-79",
    "references": ["https://...", "https://..."],
    "researchers": ["Jane Doe"],
    "published": "2026-01-15 10:30:00",          // "YYYY-MM-DD hh:mm:ss" string, UTC
    "updated":   "2026-01-20 09:00:00",
    "copyrights": "..."
  }
}
```

### Field-name confidence — where guesses were CONFIRMED vs UNCERTAIN

**CONFIRMED against live v3-consuming source code** (these are safe to map 1:1):

| Field | Confirmed by |
|---|---|
| top-level keyed by UUID; `id`, `title`, `description`, `cve`, `cvss`, `references`, `software` | topscoder parser does `json_object.get('title'/'id'/'description'/'cve'/'cvss'/'references'/'software')`; typisttech `Record.php` declares `id,title,software,references,copyrights,cve,cvss,published` |
| `software[].type` (values `plugin`/`theme`/`core`, **lowercase**) | topscoder: `item.get('type')` then `== 'plugin'/'theme'/'core'`; typisttech `Software.php` has `type` |
| `software[].slug` | topscoder `item.get('slug')`; szepeviktor `$node['slug']`; typisttech `Software.php` `slug` |
| `software[].patched_versions` (**array of version strings**) | szepeviktor reads `$node['patched_versions']`, asserts `is_array`, iterates strings |
| `software[].affected_versions` is an **object keyed by range-label**, not an array | topscoder: `for version_range, version_data in affected_versions.items()` |
| inner range keys **`from_version`, `from_inclusive`, `to_version`, `to_inclusive`** | topscoder `get_affected_version()` reads all four by exact name |
| unbounded lower bound sentinel = literal string **`"*"`** in `from_version` | topscoder: `if affected_version['from_version'] == "*"` |
| `*_inclusive` are booleans (feed may emit string `"true"`/`"false"` — topscoder normalizes both) | topscoder coerces `"true"`→`True` |
| `cvss.score`, `cvss.vector`, `cvss.rating` | topscoder `json_object.get('cvss')['score'/'rating'/'vector']`; typisttech `Cvss.php` declares `vector,score,rating` (**all `string`** — score is a string!) |

**ALL FOUR of Task-1's guessed version-range key names are correct** — `from_version`,
`from_inclusive`, `to_version`, `to_inclusive` are exact. No surprises there.

**SURPRISES / things the mapper MUST handle (these differ from a naive guess):**

1. **`affected_versions` is a dict, not a list.** Naive code expecting an array will break.
   Key = a display label (e.g. `"<= 4.2.1"`), value = the `{from_version,...,to_inclusive}`
   object. Iterate the **values**; ignore/keep the label as cosmetic only.
2. **Patched/first-fixed lives in `patched_versions` (a JSON array of strings)**, plus a
   boolean `patched`. There is **no** single `patched_version` scalar at the software level in
   the feed — szepeviktor builds a "max patched version" index by iterating the array. Map our
   `first_fixed`/`patched_version` column from `min(patched_versions)` (or store the array).
3. **`cvss.score` is a STRING** (`"9.8"`), not a number — cast before numeric compare.
   `typisttech/Cvss.php` types it `string`.
4. **Unbounded bound = `"*"`** (string), not null/missing. Treat `from_version == "*"` as
   "from the beginning". `to_version` is expected to always be present.
5. **CVE may be absent** (scanner/candidate records often have no CVE yet → `cve` null/empty).
   Don't make CVE a NOT NULL key. The **UUID** is the stable primary key → our `source_id`.
6. **CVE can appear both at record level (`record.cve`) and inside the software node** in some
   records — prefer record-level, fall back as needed. (topscoder even regex-extracts a CVE
   from the slug `"UNKNOWN-CVE-2021-24916-1"` as a last resort.)
7. `published`/`updated` are **`"YYYY-MM-DD hh:mm:ss"` strings (UTC, no TZ suffix)** — parse
   explicitly as UTC.
8. Field names `cwe`, `cve_link`, `researchers`, `copyrights` are documented by Wordfence but
   **not exercised by the parsers I read** → treat their exact spelling as *likely-correct but
   verify against one real keyed response* once a key exists. They are non-load-bearing for
   matching (we match on slug + version range), so low risk.

> Schema sources: live error bodies; `topscoder/nuclei-wordfence-cve`
> `src/lib/wordfence_api_parser.py` (raw v3 JSON field access); `szepeviktor/plugin-vulnerability`
> `command.php` (v3 `patched_versions`/`slug`/`type`); `typisttech/wordfence-api`
> `Record.php`/`Software.php`/`Cvss.php` (typed properties); Wordfence v2 docs / 2023 blog
> (root-keyed-by-UUID, field list).

---

## 4. Severity mapping (record → bucket)

Derive our `severity` bucket directly from **`cvss.rating`**, lowercased — it already maps 1:1:

| `cvss.rating` | our bucket |
|---|---|
| `Critical` | `critical` |
| `High` | `high` |
| `Medium` | `medium` |
| `Low` | `low` |
| missing / empty / no `cvss` object | `unknown` |

Fallback when `rating` is absent (e.g. scanner-only records) — derive from `cvss.score`
(after casting the string to float):

| score range | bucket |
|---|---|
| 9.0–10.0 | critical |
| 7.0–8.9 | high |
| 4.0–6.9 | medium |
| 0.1–3.9 | low |
| no score | unknown |

(Standard CVSS v3.1 qualitative bands; matches Wordfence's own Critical/High/Medium/Low.)
Do **not** copy topscoder's title-keyword "downscale authenticated issues" heuristic — that's
their public-scanner noise-reduction, not appropriate for a fleet-management owner's view.

---

## 5. Go / No-Go

**GO.** The free, registered-key **v3 production feed**
(`https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production`, Bearer auth) fully
satisfies "download a bulk feed + cache in MySQL + match offline." It returns the entire
~12k-record set in one authenticated GET as a UUID-keyed JSON object, is free for commercial
use, and explicitly permits local storage/caching. The operator sets one
`DEFYN_WORDFENCE_API_KEY` constant and we pull daily.

- **Production vs scanner:** use **production** — it's the fully-analyzed record set with
  CVSS/CVE/patched data, which is what our matcher needs. The **scanner** feed is a *superset*
  in record count (adds candidate/not-yet-CVE'd vulns with minimal detail) but slimmer per
  record; skip it for v1. Same auth, same top-level UUID-keyed shape, fewer populated fields
  (often no `cve`, sparser `cvss`). Can be added later if we want "actively-researched"
  early-warning coverage.
- **One operational caveat (not a blocker):** the production feed is ~117 MB — stream-parse to
  avoid PHP memory exhaustion; do not load+`json_decode` whole in one shot.

No escalation condition hit — a usable free bulk feed **does** exist.
