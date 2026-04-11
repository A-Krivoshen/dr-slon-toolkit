# Architecture

## Directory layout

- `dr-slon-toolkit.php`
  - Minimal bootstrap entrypoint.
  - Loads Composer autoloader, translations, activation hook, and plugin runtime.
- `src/Core`
  - Runtime and lifecycle classes (`Plugin`, `Activator`, `Settings`, `ModuleInterface`).
- `src/Admin`
  - Native wp-admin screen and Settings API wiring.
- `src/Modules`
  - Feature modules with clear responsibilities.
- `src/Integrations`
  - Third-party compatibility helpers (currently The SEO Framework detector).

## Core classes

- `DrSlon\Toolkit\Core\Plugin`
  - Boots admin wiring and registers enabled modules.
- `DrSlon\Toolkit\Core\Settings`
  - Provides defaults and option access (`dstk_settings`).
- `DrSlon\Toolkit\Core\Activator`
  - Ensures baseline options/version on activation.

## Modules

Each module implements `ModuleInterface` and registers only its own hooks.

- `TransliterationModule`
  - Handles post slug, term slug, and filename transliteration.
- `DisableCommentsModule`
  - Disables comments globally and removes comments-related UI surfaces.
- `CleanupModule`
  - Applies conservative cleanup toggles based on plugin settings.

## Data model

- Main option: `dstk_settings`
  - `modules` map for module toggles.
  - `cleanup` map for cleanup sub-toggles.
- Version option: `dstk_version`
