# AGENTS.md

## Project
This repository contains a new WordPress plugin named **Dr.Slon Toolkit**.

The plugin is a clean-room implementation of a modular toolkit for client WordPress sites.
It must not copy code, strings, file structure, comments, UI, or identifiers from any third-party commercial plugin.

## Main goals
Build a production-oriented modular plugin with these planned modules:

- Transliteration
- Disable Comments
- Cleanup
- Hide Login
- Redirect Manager
- Login Attempts
- REST API Control
- IndexNow
- Sitemap
- Update Controls

## Technical baseline
- WordPress 6.6+
- PHP 8.1+
- Namespace: `DrSlon\Toolkit`
- Text domain: `dr-slon-toolkit`
- Plugin slug: `dr-slon-toolkit`
- Prefix for options/helpers: `dstk_`

## Architecture rules
- Keep the main plugin file minimal.
- Use modular architecture.
- Put core bootstrap logic under `src/Core`.
- Put admin UI logic under `src/Admin`.
- Put modules under `src/Modules`.
- Put integrations under `src/Integrations`.
- Prefer readable code over clever code.
- Avoid overengineering.

## WordPress rules
- Sanitize all input.
- Escape all output.
- Check capabilities for every admin action.
- Use nonces for state-changing actions.
- Use prepared SQL queries.
- Avoid expensive work on every request.

## Compatibility rules
- The plugin must be compatible with **The SEO Framework**.
- Do not duplicate title, meta description, canonical, schema, robots, or sitemap behavior if TSF already handles it.
- If TSF is active, conflict-prone modules should enter compatibility mode or disable themselves with a clear admin notice.

## Product rules
- Do not add fake placeholder implementations.
- Do not pretend a feature works if it is not fully implemented.
- Build in small, reviewable increments.
- Prefer one finished feature over five half-working ones.

## UI rules
- Keep admin UI simple and native to WordPress.
- Avoid bloated dashboards.
- Use plain, clear labels and descriptions.
- No copied UI patterns from commercial plugins.

## Git rules
- Make focused changes.
- Keep commits small and logical.
- Do not reformat unrelated files.
- Do not rename files unless necessary.

## Release build rules
- Composer is a developer/build-time tool only. Never require Composer on client servers.
- Client delivery is a ready-to-install ZIP from **GitHub Releases** (`dr-slon-toolkit-<version>.zip`), not the Code → Download ZIP button.
- Use `tools/build-release.sh` (or `composer build-release`) to assemble the release ZIP.
- Release archive must include runtime files (`src/`, production `vendor/` autoload, main plugin file, uninstall, readme, license, assets) and exclude development junk.
- Do not commit `vendor/` to git. Keep repository sources clean.
- The main plugin file must boot without Composer: prefer `vendor/autoload.php` when present, otherwise a built-in PSR-4 loader for `src/`.
- Publish by pushing a `vX.Y.Z` tag; `.github/workflows/release.yml` builds, verifies, and attaches the ZIP to the GitHub Release.

## When unsure
- Choose the simpler implementation.
- Leave a clear TODO in `TODO.md` instead of inventing unfinished behavior.
