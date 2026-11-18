# FAQ

Read `references/modules.md` for what each module does. This file is the recovery and install path.

## The owner cannot open wp-login.php

The hide-login module returns 404 for direct `wp-login.php` when its custom route is available. Recovery mode still uses `wp-login.php`.

1. Add the emergency constant from the hide-login section of `references/modules.md` to `wp-config.php`.
2. Load `wp-login.php` again and sign in.
3. Fix the slug or turn the module off in **Dr.Slon Toolkit**.
4. Confirm the custom URL in a private window while the other browser stays logged in.
5. Remove the constant.

Do not look for a query-string bypass. `dstk_custom_login=1` is not a way in.

## The custom login URL is also a 404

The slug collides with a page or a rewrite, or it was rejected and fell back to `login`. The admin notice names the collision. Pick another slug from the reserved list in `references/modules.md`, save, and let the plugin flush rewrites. Stay logged in while testing.

## verify.sh says the layout is wrong

The copy on disk is a GitHub source archive or a partial tree. `composer.json` or `tests/` must not be inside the plugin directory. `src/Core/Plugin.php` and `vendor/autoload.php` must be there. Run `scripts/install.sh` so it replaces the directory with the latest release asset `dr-slon-toolkit-<version>.zip`.

## Captcha rejects a correct password

The captcha failure is applied after WordPress checks the password. Ask the person to complete the checkbox. If the widget is missing, both Yandex keys are required. The module does not protect password reset or XML-RPC.

## An office IP is locked out

Add that IP on its own line under **Белый список IP**. Listed addresses are not counted. Existing locks stay until they expire or someone uses the reset control under the settings form. A correct password does not clear a lock. The counter stores a hash, not the raw address.

## Gutenberg or a headless client broke after a REST change

Switch `rest_api.mode` back to `allow_all` or `authenticated_only`. Whitelist mode hides public posts, pages, and media from guests. The built-in system allowlist cannot be emptied. Editor routes still require a logged-in user.

## Visitors see a reconstruction page

The maintenance module is on. Guests get HTTP 503, `noindex`, and the stub. An administrator still sees the site, and the login URL still works. Turn the module off under **Dr.Slon Toolkit**. The warning on wp-admin screens stays until that switch is off. IndexNow does not submit URLs while the site is closed.

## Two sitemaps, or no toolkit sitemap

When The SEO Framework serves a sitemap, Dr.Slon Toolkit does not serve a second one. That is intentional. IndexNow and AI Agents still skip noindex URLs and URLs with an external canonical. The compatibility notice is shown only on Dr.Slon Toolkit admin screens.

## Where updates come from

Updates are the GitHub Release asset only, with SHA-256, size, version, and layout checks. Source archives are ignored. This skill follows the same rule and always resolves `/releases/latest`. The site does not need Composer.

## WP-CLI refuses to run as root

Run the script as the user that owns the site. Set `WP_CLI_ALLOW_ROOT=1` only after the user explicitly accepts a root run. The scripts do not set that variable themselves.

## Multisite

Pass `--network` to `install.sh` only when the user asked to network-activate. The flag is rejected on a single site. Without it, the plugin activates on the site WP-CLI is targeting.

## Uninstall is not an off switch

`uninstall.php` deletes the plugin options, including `dstk_settings`, the version, and the AI and sitemap cache keys. Turn a module off in settings instead.
