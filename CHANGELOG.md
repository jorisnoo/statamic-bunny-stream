# Changelog

## [2.0.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/2.0.0) (2026-02-13)

## [2.1.1](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.1.1) (2026-03-01)

### Features

- proxy Bunny video list through CP route and prefill initial title in fieldtype ([7631c6e](https://github.com/jorisnoo/statamic-bunny-stream/commit/7631c6eeb8a5f0ecf4f5348128f8c53ae5ed5853))

### Build System

- compile frontend assets ([5635fbe](https://github.com/jorisnoo/statamic-bunny-stream/commit/5635fbeb8f928e910e8c484a66695b24b5006a3a))
## [2.1.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.1.0) (2026-03-01)

### Features

- auto-publish assets after addon installation with cache cleanup ([e5f9569](https://github.com/jorisnoo/statamic-bunny-stream/commit/e5f9569203134576db6155bd7ca2f6d3d3a1cd46))
- rename addon assets to bunny-stream, add cleanup command, and enable cache-busting hashes ([22dd7d7](https://github.com/jorisnoo/statamic-bunny-stream/commit/22dd7d77d1ea2332cd3c58144285820233e77cc1))
- add tailwind CSS prefix to avoid style conflicts and rebuild assets ([d266e8b](https://github.com/jorisnoo/statamic-bunny-stream/commit/d266e8b80ed1d742d0ebac5c1f31d49d4f327a29))
- add augment method to Bunny fieldtype to generate HLS playlist URLs ([8acf088](https://github.com/jorisnoo/statamic-bunny-stream/commit/8acf088ebd9e599d2c53c3ef9cf57f040c56a899))

### Bug Fixes

- rename addon from "Video Stream GDPR Compliant" to "Bunny Stream" in composer.json ([697761f](https://github.com/jorisnoo/statamic-bunny-stream/commit/697761f8d0c848d7eae7e8844536c347d0da6aa1))
- rename "Select Video" to "Select Media" in fieldtype placeholder and translations ([1556865](https://github.com/jorisnoo/statamic-bunny-stream/commit/1556865f2f4b7ff4135f40112f0ade822ad1bfae))
- correct encoding progress display in Bunny fieldtype and rebuild assets ([e52cf68](https://github.com/jorisnoo/statamic-bunny-stream/commit/e52cf688f42f17a8f85a9ee57fb901d7b16d36e0))
- simplify config publish tag from statamic-bunny-stream-config to bunny-stream-config ([5cf1e53](https://github.com/jorisnoo/statamic-bunny-stream/commit/5cf1e53de144a089c81463fd60f9beb551eb9f67))
- update Bunny fieldtype to use correct encoding progress calculation and rebuild assets ([58d94d5](https://github.com/jorisnoo/statamic-bunny-stream/commit/58d94d554f6e963b56b47d84a39d60cd9647e168))
- simplify Bunny fieldtype by removing unused data properties and rebuild assets ([60652ed](https://github.com/jorisnoo/statamic-bunny-stream/commit/60652ed6611d097bfe61098451356ff11be7c30a))
- simplify Bunny fieldtype component and rebuild assets ([392f266](https://github.com/jorisnoo/statamic-bunny-stream/commit/392f266243b394d257fd9260e1b0da9294a0eae1))

### Build System

- compile frontend assets ([e64188c](https://github.com/jorisnoo/statamic-bunny-stream/commit/e64188c25939c2a1a84c5832e658eda1735318d3))
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
