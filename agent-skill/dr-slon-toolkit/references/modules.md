# Modules

Twelve modules. Each switch lives in `dstk_settings.modules` and defaults to `false`. The wp-admin menu is **Dr.Slon Toolkit**. Labels there are Russian. `ExistingSlugRewriter` is part of transliteration, not a thirteenth module.

| Key | What it does | Extra settings |
| --- | --- | --- |
| `hide_login` | Serves login on a slug and returns 404 for direct `wp-login.php`, except WordPress recovery mode. | `hide_login.slug`, default `login` |
| `yandex_captcha` | Yandex SmartCaptcha on the login form, including the custom slug. | `yandex_captcha.client_key`, `server_key`, `language` (`ru`) |
| `login_attempts` | Locks an IP after repeated failed logins. A correct password does not clear the lock. | `max_attempts` 5, `window_minutes` 15, `lockout_minutes` 15, `whitelist` |
| `rest_api_control` | REST open, authenticated only, or whitelist. | `rest_api.mode`, route and namespace lists, `trusted_capability` |
| `indexnow` | Manual and WP-Cron URL submission. The key is served at `/<key>.txt` with no file on disk. | `indexnow.key`, `endpoint`, `post_types` |
| `sitemap` | Paginated `/sitemap.xml` with `lastmod` and cache. Stays silent when The SEO Framework serves its own map. | `sitemap.enabled`, `post_types`, `taxonomies` |
| `ai_agents` | `/ai.txt`, `/llms.txt`, `/llms-full.txt`, `/agents.md`. Pulse is off. | `ai_agents.*` below |
| `redirects` | Exact 301/302 rules, first match wins. Order is priority. | `redirects.rules`, at most 100 |
| `transliteration` | Latin slugs and upload names. A button rewrites existing Cyrillic URLs and stores 301s. | Module switch, then the admin action |
| `disable_comments` | Closes comments, pings, the REST routes, and the UI. | Module switch |
| `cleanup` | Emoji, wp-embed, XML-RPC, extra `<head>` tags. | `cleanup.*` below |
| `update_controls` | Auto-updates for core, plugins, themes, translations, and the emails. | `update_controls.*` below |

## Hide login

Pretty permalinks use `/<slug>/`. Plain permalinks use the same slug through the `dstk_custom_login` route. The slug is a lowercase hyphenated string, at most 80 characters, and it cannot start with `wp-` or be a reserved WordPress base (`wp-admin`, `wp-json`, `feed`, `sitemap`, `ai`, `llms`, `agents`, and the other cores in `Settings::sanitize_hide_login_slug`). An invalid slug falls back to `login`.

A page or rewrite that already owns the slug marks the route unavailable and shows an admin notice. Direct `wp-login.php` then stays available. Recovery mode (`enter_recovery_mode` with `rm_token` and `rm_key`) also stays on `wp-login.php`. Lost-password and other login actions are rewritten onto the custom slug. Do not send people to `wp-login.php?dstk_custom_login=1`. That bypass was removed.

Emergency switch, in `wp-config.php`, above the "stop editing" line:

```php
define('KRV_DSTK_DISABLE_HIDE_LOGIN', true);
```

When that constant is true, the module registers no hooks, so `wp-login.php` works again even if the module switch is still on. Remove the constant after the slug is fixed. Keep a logged-in browser open while testing the new URL in a private window.

## Yandex SmartCaptcha

Both keys come from Yandex Cloud. With either key empty, the module shows a warning and does not render the widget. The check runs on `authenticate` at priority 100, so a failed captcha stays failed after WordPress accepts the password. It runs for the login action only. Password reset, XML-RPC, and REST are outside it. Store the keys only in the admin fields.

## Login attempts

The counter stores a hash of the IP, not the raw address. **Белый список IP** is one IPv4 or IPv6 per line. Listed addresses are not counted and not locked. Defaults are 5 failures in 15 minutes, then a 15-minute lock. A successful password does not lift a lock. Clear locks with the reset control under the settings form.

## REST API control

`rest_api.mode` is `allow_all` (default), `authenticated_only`, or `whitelist`. WordPress system routes stay allowed. The admin list only adds routes. Users with `trusted_capability` (default `edit_posts`) bypass the whitelist. Editor routes for posts, pages, and media require a logged-in user. In whitelist mode, guests lose public posts, pages, and media. Confirm Gutenberg and any headless client before leaving whitelist on.

## IndexNow, sitemap, AI Agents

IndexNow skips URLs that are discouraged, noindex, or pointed at another canonical by The SEO Framework. The default endpoint is `https://api.indexnow.org/indexnow`. Default post types are `post` and `page`.

Sitemap defaults, once the module is on: runtime enabled, posts and pages, categories and tags. The SEO Framework's own sitemap wins. The toolkit map is not served beside it. The admin notice about The SEO Framework appears only on Dr.Slon Toolkit screens.

AI Agents documents are built on request, UTF-8 without a BOM, and cached by `dstk_ai_cache_version`. Defaults inside the module: `/ai.txt`, `/llms.txt`, `/llms-full.txt`, and `/agents.md` on; `/feed/ai-pulse.md` off. The module switch itself is off. Noindex and an external canonical are respected. The **AI Agents** tab previews URLs and clears the cache.

## Redirects

A rule is `from`, `to`, `status` (`301` or `302`), and `enabled`. Disabled rows stay in the list and do not run. These paths are never redirected: `/wp-admin`, `/wp-login.php`, `/wp-json`, `/xmlrpc.php`.

Batch text, one rule per line, `#` comments ignored, at most 100 rules:

```text
/old-path -> /new-path
/promo -> /sale | 302
```

`->`, `→`, or `|` separates the paths. A missing status means 301. See `templates/redirects.example.txt`.

## Transliteration

New post slugs, term slugs, and upload names are transliterated while the module is on. **Перевести существующие URL** rewrites published Cyrillic URLs and keeps 301s from the old paths in `dstk_translit_redirects`. Run it only after the user agrees, and only while the module is on.

## Cleanup and update controls

Cleanup defaults when the module is on: emojis off, wp-embed off, extra head tags removed, XML-RPC left on (`cleanup.disable_xmlrpc` defaults false).

Update controls default to core channel `minor`, with plugins, themes, translations, and email notices on. Allowed core modes are `all`, `minor`, and `off`. A stored `security` value is treated as `minor`. There is no separate security mode in the UI.

## Comments

`disable_comments` closes comments and pings for the whole site, including the related UI and REST. Turn it on only when the user wants comments gone, not as a default hardening step.
