---
name: dr-slon-toolkit
description: >-
  Installs the free Dr.Slon Toolkit WordPress plugin from the latest GitHub
  Release asset dr-slon-toolkit-<version>.zip with WP-CLI, then checks the
  install and configures its modules. Use when the user asks to install, update,
  or set up Dr.Slon Toolkit: hide wp-login, limit REST, IndexNow, sitemap,
  redirects, transliteration, comments, cleanup, update policy, Yandex
  SmartCaptcha, login lockouts, or AI discovery files (ai.txt, llms.txt, agents.md).
summary: Install the latest Dr.Slon Toolkit release and configure its WordPress modules.
version: "1.0.0"
tags: [wordpress, security, maintenance, seo, indexnow, redirects]
keywords: [wp-cli, hide-login, indexnow, yandex-smartcaptcha, llms.txt, sitemap]
use_cases:
  - Install or update Dr.Slon Toolkit from the latest GitHub Release.
  - Enable hide-login, REST limits, redirects, or AI files with the owner's consent.
  - Confirm the active copy is a release ZIP and recover wp-login with the emergency constant.
docs_url: https://github.com/A-Krivoshen/dr-slon-toolkit
license: GPL-2.0-or-later
compatibility: WordPress 6.6+, PHP 8.1+, WP-CLI, bash, curl, and Info-ZIP unzip. Installs track the latest GitHub Release.
---

# Dr.Slon Toolkit

Install and configure the free WordPress plugin Dr.Slon Toolkit on the site the user named. Leave every module off until the user chooses it.

## Requires

- WordPress 6.6 or newer and PHP 8.1 or newer, on the site WP-CLI uses.
- WP-CLI, bash, curl, PHP 8.1 or newer in the CLI, and Info-ZIP `unzip`.
- Minimum verified plugin version: 0.13.1. `scripts/install.sh` always installs the latest stable GitHub Release asset `dr-slon-toolkit-<version>.zip`. Run the script. Do not type a version or a download URL from memory.
- A clear yes from the user for that site. Ask which site when they did not name one.

## Run the scripts as shipped

`scripts/install.sh` and `scripts/verify.sh` are each self-contained. Execute the files. Do not retype them and do not factor them into a shared library.

```bash
bash scripts/install.sh --path /path/to/wordpress
bash scripts/verify.sh --path /path/to/wordpress
```

Omit `--path` when the current directory is the WordPress root. Add `--network` only when the user asked for multisite network activation. `install.sh --check` prints the latest asset and stops before any download.

The installer rejects a draft, a prerelease, a wrong asset name, a wrong URL, a bad size, or a bad SHA-256. It then activates the plugin. When that release is already active, it exits without reinstalling.

`verify.sh` exits 0 when WordPress, PHP, an active plugin, a release layout, and HTTP 200 from the site home URL all pass. A release layout has `src/Core/Plugin.php` and `vendor/autoload.php`, and has neither `composer.json` nor `tests/`. Read `update_available` in the output. Run `install.sh` again when the user wants the newer release.

## Configure

Read `references/modules.md` before changing settings. Copy `templates/site-plan.md` and fill in only what the user decided. Change settings in the **Dr.Slon Toolkit** wp-admin screen. The option key is `dstk_settings`. An edit through WP-CLI must merge into the saved option and leave every unrelated key in place.

Hide-login recovery is in `references/faq.md`. The emergency switch is `KRV_DSTK_DISABLE_HIDE_LOGIN`. Redirect lines use `templates/redirects.example.txt`.

Keep captcha keys and other secrets in the wp-admin fields. Leave them out of the site plan, the chat, and the repository.

## Author / Support

Aleksey Krivoshein (Dr.Slon) · https://krivoshein.site · https://drslon.ru · https://github.com/A-Krivoshen/dr-slon-toolkit
