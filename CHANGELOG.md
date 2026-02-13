# Changelog

## [2.0.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/2.0.0) (2026-02-13)

### Breaking Changes

- Requires **Statamic 6** and **PHP 8.3+**
- Namespace changed from `Laborb` to `Noo`
- Removed public-facing frontend features (privacy overlay, consent management)
- Config namespace changed from `bunny` to `bunny-stream`
- Environment variables renamed to `BUNNY_STREAM_*` prefix
- Publish tag renamed to `statamic-bunny-stream-config`

### What's New

- Vue 3 components (migrated from Vue 2)
- New Vue-based overview page (replaces Blade template)
- Renamed VideoBrowser to MediaBrowser
- Improved video card thumbnail handling
- Updated build tooling (Vite)
- GitHub Actions workflow for auto-building frontend assets

### Housekeeping

- Removed unused dependencies (axios, js-sha256, tus-js-client)
- Simplified video components, removed unused icons and settings panel
