# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0](https://github.com/jorisnoo/statamic-bunny-stream/releases/tag/v2.0.0) (2026-02-13)

### Features

- enhance VideoCard component with improved thumbnail handling and rebuild assets ([2eb019f](https://github.com/jorisnoo/statamic-bunny-stream/commit/2eb019f309171ddd4c4f83b02f1affcf99962a36))
- [US-009] - Verify end-to-end CP functionality ([1e372a3](https://github.com/jorisnoo/statamic-bunny-stream/commit/1e372a3308e86cb4582c22251d0ee56286384959))
- [US-008] - Clean up removed files and unused code ([c6426a6](https://github.com/jorisnoo/statamic-bunny-stream/commit/c6426a6aa444ddf3e433feecb77ce6a46bf45b52))
- [US-007] - Update CP controllers and repository ([a02f246](https://github.com/jorisnoo/statamic-bunny-stream/commit/a02f246d9c909d1dc5b1bb71450c549fc00a25b3))
- [US-006] - Update ServiceProvider for Statamic 6 ([304bc1a](https://github.com/jorisnoo/statamic-bunny-stream/commit/304bc1afb31fa324e99b11981a88c41c404a8bcf))
- [US-005] - Update Fieldtype for Statamic 6 API ([5c57153](https://github.com/jorisnoo/statamic-bunny-stream/commit/5c5715331b97f9394e0c043922c9bb663ae277c7))
- [US-004] - Update frontend build tooling ([a6d422e](https://github.com/jorisnoo/statamic-bunny-stream/commit/a6d422e6a47cc2fe70f01d802bb4a9b740a077ad))
- [US-003] - Migrate Vue 2 components to Vue 3 ([a4ef0a4](https://github.com/jorisnoo/statamic-bunny-stream/commit/a4ef0a4237bc69a40c55c7f692c45d1d748a23a9))
- [US-002] - Update composer.json for Statamic 6 ([6ea3e38](https://github.com/jorisnoo/statamic-bunny-stream/commit/6ea3e38a78ab5e1da5749c58ad5fe9c108442cef))
- [US-001] - Remove public-facing frontend features ([0d88ad8](https://github.com/jorisnoo/statamic-bunny-stream/commit/0d88ad85d86959df83af6a3887c972e3c5b55f08))

### Bug Fixes

- update video card thumbnail fallback and rebuild assets ([51711c1](https://github.com/jorisnoo/statamic-bunny-stream/commit/51711c153c07207a61db86ff85d38875b97bef63))
- update Overview controller namespace from Laborb to Noo ([9c44cfb](https://github.com/jorisnoo/statamic-bunny-stream/commit/9c44cfbec9a330b5b6d9beeddd7a4ba6d5589a95))
- update video card thumbnail fallback and rebuild assets ([9d484af](https://github.com/jorisnoo/statamic-bunny-stream/commit/9d484af885216a388c6faecbd2211d333c072152))
- update video card thumbnail fallback and rebuild assets ([1511aab](https://github.com/jorisnoo/statamic-bunny-stream/commit/1511aab1aa27727ec56643e29aef30cc924d3d61))
- simplify tailwindcss import and rebuild frontend assets ([94cc58d](https://github.com/jorisnoo/statamic-bunny-stream/commit/94cc58dace821d0fb5d70695ca224868ccb1f9a6))
- use BasePlugin instead of UIPlugin for UppyBunnyCreator and rebuild assets ([f10d840](https://github.com/jorisnoo/statamic-bunny-stream/commit/f10d840ea0a457b2d3753783a9b5bd22e7edc012))
- use $inertia.register instead of $components.register for BunnyOverview and rebuild assets ([7a297ea](https://github.com/jorisnoo/statamic-bunny-stream/commit/7a297eaa08f9c0f340e9b2c02ab3847d06a83d0f))
- use $components.register instead of $inertia.register for BunnyOverview component ([5443075](https://github.com/jorisnoo/statamic-bunny-stream/commit/544307541bb625046f114cdb348d3dfd67e2ca2e))
- rename publish tag to statamic-bunny-stream-config for consistency with package namespace ([bbba444](https://github.com/jorisnoo/statamic-bunny-stream/commit/bbba4440713fc9ee152a18e203feda9b69c63cf5))

### Code Refactoring

- rename VideoBrowser to MediaBrowser and update video references to media ([a455aba](https://github.com/jorisnoo/statamic-bunny-stream/commit/a455abad828dc530b0dfeb437b6f07d8bdacec8e))
- simplify video components, remove unused icons and settings panel, rebuild assets ([be312d2](https://github.com/jorisnoo/statamic-bunny-stream/commit/be312d200a9b4fb5f50053c38e163533e0639a75))
- remove FetchAssets controller and simplify video components ([3ff7c7d](https://github.com/jorisnoo/statamic-bunny-stream/commit/3ff7c7d5dfc9f9da4d7aa3129aeac2b50c5cf09d))
- remove unused dependencies (axios, js-sha256, tus-js-client) and custom spinner CSS, rebuild assets ([dd8ffc1](https://github.com/jorisnoo/statamic-bunny-stream/commit/dd8ffc102ca78147936f669f3fb634d4c0e9a0cb))
- replace blade overview with Vue component and rebuild assets ([9f469b9](https://github.com/jorisnoo/statamic-bunny-stream/commit/9f469b9263559a4ebb85e38439b1f06860f6756f))

### Build System

- compile frontend assets ([0f94fed](https://github.com/jorisnoo/statamic-bunny-stream/commit/0f94fed00d1bfaeaad0906da1503934dbf9a762b))
- compile frontend assets ([03ab0f2](https://github.com/jorisnoo/statamic-bunny-stream/commit/03ab0f25bd8b981b5e2bdb30b1aa207c45bd7a16))
- compile frontend assets ([473d85c](https://github.com/jorisnoo/statamic-bunny-stream/commit/473d85c6a6737ac1ee8c300119e7567b88a3b347))
- rebuild frontend assets with updated uploader component ([14e182f](https://github.com/jorisnoo/statamic-bunny-stream/commit/14e182fb11df439a80ca4cc6fe74c01f87458fbd))
- rebuild frontend assets with updated vite config ([91827e1](https://github.com/jorisnoo/statamic-bunny-stream/commit/91827e1795915b69c50819e288b2edc224d13b02))
- remove content hashes from asset filenames in vite config and rebuild ([dc560e2](https://github.com/jorisnoo/statamic-bunny-stream/commit/dc560e27c590c09c7b5ab2be085bf74563a17cbf))
- rebuild frontend assets after vite config update ([4c23ba3](https://github.com/jorisnoo/statamic-bunny-stream/commit/4c23ba3f09a58fec8bd843bc7728377925f26feb))
- rebuild frontend assets and update vite config ([530ba63](https://github.com/jorisnoo/statamic-bunny-stream/commit/530ba63e1e14b4f44365baf3f7017ff07c737ab1))
- add compiled assets, update gitignore, and rename env variables to BUNNY_STREAM prefix ([3428335](https://github.com/jorisnoo/statamic-bunny-stream/commit/342833587fb05a26a4d626323531a3daaad0607d))
- update composerfile ([6ff9b9f](https://github.com/jorisnoo/statamic-bunny-stream/commit/6ff9b9f08033e65edc9bb0bfb58e44cf3f92a9e5))

### Continuous Integration

- add GitHub Actions workflow to auto-build frontend assets on push ([c2f704a](https://github.com/jorisnoo/statamic-bunny-stream/commit/c2f704a50e6b9c17c5e8be90901db49a3c560353))

### Chores

- rename namespace from Laborb to Noo, update license, and add shipmark config ([1b8f71f](https://github.com/jorisnoo/statamic-bunny-stream/commit/1b8f71ff424c1e188fee80cde4f3156ea6152294))
