=== Dr.Slon Toolkit ===
Contributors: A-Krivoshen
Tags: wordpress, toolkit, maintenance, comments, transliteration, cleanup
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular WordPress toolkit for practical client website tasks.

== Description ==

Dr.Slon Toolkit is a clean-room, modular toolkit plugin for WordPress client projects.

This release includes:
- plugin bootstrap with Composer PSR-4 autoloading
- central plugin runtime
- admin settings page using Settings API
- module toggles
- Transliteration module
- Disable Comments module
- Cleanup module
- The SEO Framework compatibility detection notice

== Installation ==

1. Upload the plugin to `/wp-content/plugins/dr-slon-toolkit`.
2. Run `composer install` inside the plugin directory.
3. Activate through **Plugins** in wp-admin.
4. Configure the plugin in **Dr.Slon Toolkit**.

== Changelog ==

= 0.2.0 =
* First production bootstrap milestone.
* Added Transliteration, Disable Comments, and Cleanup modules.
* Added modular settings architecture and TSF detection notice.
* Hardened nested settings merge, slug transliteration edge cases, and conservative cleanup behavior.
