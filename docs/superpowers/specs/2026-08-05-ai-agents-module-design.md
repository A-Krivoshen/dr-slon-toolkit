# AI Agents Module — Design Spec

**Product:** Dr.Slon Toolkit  
**Target version:** 0.10.0  
**Date:** 2026-08-05  
**Status:** Approved (user)  
**Architecture choice:** Single module `AiAgentsModule` + dedicated admin tab (approach 1)

---

## 1. Goal

Make client WordPress sites AI-agent friendly by serving machine-readable discovery documents and a Markdown pulse feed, with **phased toggles** (e.g. llms only, no feed) and **no files written to disk**.

Reference quality bar: public AI surface on [krivoshein.site](https://krivoshein.site) (`llms.txt`, `ai.txt`, `agents.md`, pulse feed), generalized for arbitrary WP installs.

## 2. Non-goals (v1 / 0.10.0)

- OpenAPI / `openapi.json`
- `.well-known/agent.json` / API catalog (linkset)
- Content negotiation `Accept: text/markdown` on HTML routes
- Writing physical files under ABSPATH or uploads
- Training-data claims or “rank #1 in AI search” promises
- Multiple micro-modules per endpoint

## 3. Scope — endpoints and features

| Feature | Default public URL | Settings key | Default when module ON |
|---------|-------------------|--------------|------------------------|
| Short AI index | `/ai.txt` | `ai_txt` | on |
| Site facts for LLMs | `/llms.txt` | `llms_txt` | on |
| Extended dump + posts | `/llms-full.txt` | `llms_full` | on |
| Agent instructions | `/agents.md` | `agents_md` | on |
| Markdown pulse feed | `/feed/ai-pulse.md` | `pulse_md` | **off** (opt-in) |
| HTML discovery links | `<head>` `rel="describedby"` / alternate | `html_links` | on |
| robots.txt pointers | soft discovery lines | `robots` | on |

Master switch: `modules.ai_agents` (default **false**, same pattern as other modules).

When master is off, the module does not register. When master is on, only enabled sub-toggles register rewrites / head / robots contributions.

## 4. Architecture

### 4.1 Placement

Follow existing modular architecture (`ARCHITECTURE.md`):

- `src/Modules/AiAgentsModule.php` — `ModuleInterface`, hooks, rewrites, serve, robots, html links
- `src/Modules/AiAgents/DocumentBuilder.php` — build `ai` / `llms` / `llms-full` / `agents` bodies
- `src/Modules/AiAgents/PulseFeedBuilder.php` — build pulse markdown
- Shared private helpers inside those classes or a tiny `Utf8Response` helper for headers + body normalization

Register in `Plugin::register_modules()` under slug `ai_agents`.

### 4.2 Runtime pattern

Mirror `SitemapModule` / IndexNow key serve:

1. `init` → `add_rewrite_rule` **only** for enabled endpoints
2. `query_vars` → e.g. `dstk_ai_doc` = `ai|llms|llms_full|agents|pulse`
3. `template_redirect` priority `0` → if query matches, send headers + body + `exit`
4. Disabled toggle → rule not registered; unknown → normal 404
5. `RewriteManager::schedule()` on settings change that affects rules (existing pattern)

### 4.3 Caching

- Transient key: `dstk_ai_doc_{kind}_{blog_id}_{cache_version}`
- TTL: 600 seconds (align with sitemap spirit)
- Cache version option: `dstk_ai_cache_version` (bump on content/settings change)
- Invalidate (debounced if needed) on: `save_post`, `deleted_post`, `transition_post_status`, term CRUD if menus/terms appear in docs, `update_option_dstk_settings`
- Admin “Сбросить кеш” forces version bump

### 4.4 Content sources (hybrid)

**Automatic (WordPress):**

- `bloginfo('name')`, `bloginfo('description')`, `home_url('/')`
- Public published posts/pages for selected `post_types` (no password, not private)
- Optional noindex exclusion when `exclude_noindex` is true (reuse filter approach compatible with TSF if already used by Sitemap: `dstk_sitemap_is_noindex` or parallel `dstk_ai_is_noindex`)
- Sitemap URL if Sitemap module or core/TSF exposes a known public sitemap URL (best-effort; omit if unknown)
- Site language (`get_bloginfo('language')`)

**Manual (settings textareas, optional):**

- `site_blurb` — who / what
- `contacts` — contact block
- `facts` — key facts / price hints
- `ai_policy` — allowed use for agents
- `do_not_invent` — explicit “do not invent …”

Empty manual fields → documents still valid from auto data only.

### 4.5 Document outlines

**`ai.txt`**

```text
# {site_name}
# {home_url}
# Full: {llms_url if enabled}
# Instructions: {agents_url if enabled}

> {blurb or description}

## Quick links
- ...
## Contacts (if any)
- ...
```

**`llms.txt`**

- Title + one-line value
- Manual blurb / facts / contacts
- Auto: important pages (pages first), recent post titles+URLs (small list)
- Policy sections
- Links to full / agents / pulse / sitemap

**`llms-full.txt`**

- Everything in llms + expanded recent posts (title, date ISO, URL, plain excerpt/content, length-capped)

**`agents.md`**

- How to load context (order: ai → llms → full → pulse → sitemap → single URL)
- Prefer MD/text over HTML scrape
- Policy + do_not_invent
- Public endpoints table (only enabled ones)

**`/feed/ai-pulse.md`**

```markdown
# AI Pulse · {site_name}
Generated: {iso8601}
Home: {url}

## {post_title}
- Date: ...
- URL: ...
- ...
{excerpt or truncated content}
```

Limits (clamped on sanitize):

- `pulse_limit`: 1–50, default 20  
- `full_posts_limit`: 1–100, default 30  

## 5. Encoding and HTTP contract (mandatory)

Every AI response:

| Header | Value |
|--------|--------|
| `Content-Type` | `text/plain; charset=UTF-8` for `*.txt`; `text/markdown; charset=UTF-8` for `*.md` |
| `X-Content-Type-Options` | `nosniff` |
| Body encoding | UTF-8 **without BOM** |
| Newlines | Prefer `\n` |

Body pipeline:

1. Build string in PHP (internal UTF-8)
2. Strip invalid sequences (`wp_check_invalid_utf8` or equivalent)
3. Decode entities with `html_entity_decode(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')` where source is HTML
4. `wp_strip_all_tags` for post content before MD inclusion
5. Never emit Windows-1251 or raw binary

PHPUnit must assert: valid UTF-8, no `\xEF\xBB\xBF`, Cyrillic fixture round-trip.

## 6. Settings schema

Under `dstk_settings`:

```php
'modules' => [
    // existing...
    'ai_agents' => false,
],
'ai_agents' => [
    'ai_txt'           => true,
    'llms_txt'         => true,
    'llms_full'        => true,
    'agents_md'        => true,
    'pulse_md'         => false,
    'html_links'       => true,
    'robots'           => true,
    'site_blurb'       => '',
    'contacts'         => '',
    'facts'            => '',
    'ai_policy'        => '',
    'do_not_invent'    => '',
    'post_types'       => ['post', 'page'],
    'pulse_limit'      => 20,
    'full_posts_limit' => 30,
    'exclude_noindex'  => true,
],
```

Sanitize via `Settings::merge_with_defaults` (extend existing method):

- Booleans cast
- Textareas: `sanitize_textarea_field` (or wp_kses with empty allowed for plain text)
- `post_types`: intersect with public queryable post types
- Limits clamped to documented ranges
- `_submitted` flag pattern if multi-checkbox groups need “all unchecked” detection (same as sitemap/indexnow)

## 7. Admin UI

### 7.1 Navigation

Extend hero tabs in `SettingsPage::render_admin_header`:

1. Настройки (`dr-slon-toolkit`)
2. **AI Agents** (`dr-slon-toolkit-ai`) — new submenu
3. Помощь (`dr-slon-toolkit-help`)

### 7.2 Settings tab

- Module master checkbox label: **AI Agents** — short description only

### 7.3 AI Agents tab

Sections:

1. **Status** — master state, list of live URLs (enabled vs paused), “Сбросить кеш”
2. **Endpoints** — seven toggles
3. **Content (hybrid)** — five textareas
4. **Sources** — post types, limits, exclude_noindex
5. **Preview** — open-in-new-tab links for each enabled public URL

Capability: `manage_options`.  
UI language: Russian (match existing admin copy).  
Reuse `assets/admin/settings.css` / existing card patterns; no remote JS.

## 8. robots.txt and HTML head

**`html_links`:** on front-end public requests, `wp_head`:

- `link rel="describedby"` type `text/plain` or `text/markdown` for each enabled document URL
- Prefer absolute `home_url('/llms.txt')` style URLs

**`robots`:** filter `robots_txt` priority ~20:

- Append short comment block listing enabled AI discovery URLs
- Do **not** re-declare Sitemap if Sitemap module already adds it (avoid duplicate Sitemap lines; AI module may mention sitemap as a markdown link inside documents instead)

## 9. Security and privacy

- Only `publish` posts; exclude password-protected
- Respect `exclude_noindex` when enabled
- No admin-only content, no user emails from private profiles unless in manual contacts field
- REST API Control does **not** gate these endpoints (they are public rewrites, not REST)
- Hide Login / Cleanup must not break public `*.txt` / `*.md` routes
- Capability for settings remains `manage_options`

## 10. Testing and verification

### 10.1 Automated (PHPUnit)

- Endpoint enabled → 200, correct Content-Type + charset, body UTF-8, no BOM
- Endpoint disabled / master off → not served (404 or non-match)
- Cyrillic site name + post title preserved
- Password / non-publish excluded from builders
- Settings sanitize clamps limits and filters post types
- Cache invalidation bumps version (unit-level where practical)

### 10.2 Manual release checklist

1. Enable master + all endpoints; open each URL; confirm charset in Network tab  
2. Disable pulse only; `/feed/ai-pulse.md` 404; others 200  
3. Disable master; all AI URLs gone after permalink flush if needed  
4. Publish a post; after cache TTL or purge, full/pulse update  
5. View source on front page: describedby links present when `html_links` on  
6. robots.txt contains AI pointers when `robots` on  
7. Russian content displays correctly in browser and `curl -s | file -`

### 10.3 Encoding recheck (explicit)

Before release:

- `file` / hexdump first bytes of each endpoint (no EF BB BF)
- `mb_check_encoding($body, 'UTF-8')` in tests
- Spot-check with `curl -sI` Content-Type includes `charset=UTF-8`

## 11. Documentation and release

### 11.1 GitHub README (required)

Update root `README.md` for 0.10.0:

- Version badge / version line → 0.10.0
- New **AI Agents** section: what it does, endpoint table, phased toggles example (“llms without feed”), hybrid content note, encoding note
- Keep install via Releases ZIP instructions
- No marketing fluff; match existing practical tone

Also: `readme.txt` (WP.org-style), `CHANGELOG.md`, `ARCHITECTURE.md` module bullet, help page short section.

### 11.2 Version bumps

- `dr-slon-toolkit.php` header Version + `DSTK_VERSION` = `0.10.0`
- `readme.txt` Stable tag = `0.10.0`
- Release ZIP via existing `tools/build-release.sh`

## 12. Risks and mitigations

| Risk | Mitigation |
|------|------------|
| Large sites → heavy full dump | hard limits + cache |
| Another plugin owns `/llms.txt` | rewrite `top`; document flush permalinks; last-registered wins caveat in help |
| REST lockdown breaks agents | public rewrites, not REST |
| Duplicate sitemap in robots | AI robots block only lists AI URLs |
| Encoding mojibake | single encoder path + tests with Cyrillic |
| SettingsPage.php already large | prefer dedicated `Admin/AiAgentsPage.php` for AI tab render/sanitize hooks, thin wiring from SettingsPage menu/header |

## 13. File touch list (implementation guide)

**Create:**

- `src/Modules/AiAgentsModule.php`
- `src/Modules/AiAgents/DocumentBuilder.php`
- `src/Modules/AiAgents/PulseFeedBuilder.php`
- `src/Admin/AiAgentsPage.php` (recommended)
- `tests/...` covering builders + serve/sanitize as per project test layout

**Modify:**

- `src/Core/Plugin.php` — register module
- `src/Core/Settings.php` — defaults + merge/sanitize
- `src/Admin/SettingsPage.php` — module checkbox, hero tab link, optional sanitize delegation
- `dr-slon-toolkit.php`, `readme.txt`, `README.md`, `CHANGELOG.md`, `ARCHITECTURE.md`
- Admin CSS only if new layout bits need it

## 14. Success criteria

1. Fresh install, enable AI Agents + defaults → `/ai.txt`, `/llms.txt`, `/llms-full.txt`, `/agents.md` return UTF-8 docs; pulse off by default  
2. Enable pulse → `/feed/ai-pulse.md` returns markdown list of recent posts  
3. Hybrid fields appear in docs; empty fields do not break layout  
4. All automated encoding/serve tests pass; `composer check` green  
5. README documents AI Agents clearly for GitHub visitors  
6. Version 0.10.0 release ZIP builds with existing tooling  

---

## Approval record

- Scope A (core endpoints, no well-known/OpenAPI) — approved  
- Hybrid content — approved  
- Separate MD pulse endpoint — approved  
- Architecture approach 1 (single module + tab) — approved  
- Encoding + full recheck — approved  
- GitHub README for release — approved  
- Full design — approved 2026-08-05 (“дизайн ок”)
