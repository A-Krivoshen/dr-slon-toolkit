# Dr.Slon Toolkit

Dr.Slon Toolkit is a modular WordPress plugin for practical client-site maintenance and hardening tasks.

## Current milestone (0.2.0)

This first real bootstrap milestone includes:

- Composer PSR-4 autoload bootstrap
- Central plugin coordinator class
- Activation and uninstall handlers
- Native WordPress admin settings screen
- Module toggles via the Settings API
- First production-safe modules:
  - Transliteration
  - Disable Comments
  - Cleanup
- The SEO Framework detection notice

## Requirements

- WordPress 6.6+
- PHP 8.1+

## Installed modules in this milestone

### Transliteration
- Converts non-Latin post slugs when needed.
- Converts non-Latin term slugs when needed.
- Converts uploaded filenames to safe Latin slugs.

### Disable Comments
- Closes comments and pingbacks globally.
- Removes comment/trackback support from post types.
- Hides comments menu and comments admin bar node.
- Redirects comment management screens to the dashboard.

### Cleanup
- Optional toggles for:
  - disable emojis
  - disable wp-embed script
  - disable XML-RPC
  - remove selected `<head>` tags safely

## Development

```bash
composer install
```

Then activate the plugin and configure modules under **Dr.Slon Toolkit** in wp-admin.
