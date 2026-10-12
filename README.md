# Video Stream GDPR Compliant (aka Bunny Stream)

> **Fork Notice:** This is a fork of [laborb/statamic-bunny-stream](https://github.com/niclasleonbock/statamic-bunny-stream) upgraded for **Statamic 6** and **Laravel 12**. Vue 2 components have been migrated to Vue 3. Public-facing frontend features (Antlers tags, frontend views) have been removed — the addon supports native Statamic Assets, legacy Bunny fields, and streaming playback helpers.

Bunny Stream is a Statamic addon that integrates the Bunny Stream API for single stream libraries into the Statamic CP.

## Features

- Native Statamic CP integration for video uploads and management
- Browse, search, and manage Bunny Stream videos from the Control Panel
- Upload videos directly via TUS protocol
- Custom thumbnails and cover images for full branding control
- Bunny fieldtype for selecting videos in blueprints (works with Bard and Replicator)
- GDPR/DSGVO-compliant video hosting with no cookies or consent manager required

## Requirements

- PHP 8.3+
- Statamic 6

## Bunny Account Required

To use this addon, you'll need a Bunny.net account. If you don't have one yet, you can sign up using the original author's
[affiliate link](https://bunny.net?ref=uhvsqhaw0n).

## Installation

Install the addon using composer:

```bash
composer require jorisnoo/statamic-bunny-stream
```

## Configuration

You need to provide the following .env variables:

```bash
BUNNY_STREAM_LIBRARY_ID=yourid            # Your Bunny Stream Library ID
BUNNY_STREAM_API_KEY=yourapikey           # Your Library API Key
BUNNY_STREAM_CDN_HOSTNAME=yourcdnhostname # Your Library CDN Hostname
```

You can find these values in your Bunny Stream Dashboard at [https://dash.bunny.net/stream/](https://dash.bunny.net/stream/) `Delivery > Stream > API`

Chapter editing and automatic chapter generation are disabled by default. To enable them, add:

```bash
BUNNY_STREAM_CHAPTERS=true
```

### Custom CDN Hostname

To add a custom hostname you can do the following:

1. Login to your bunny dashboard and head over to `Delivery > Stream > API`
2. At Pull zone click `Manage`
3. Create a CName entry in your DNS settings pointing to the displayed bunny CDN hostname
4. Enter your custom hostname in the bunny settings and activate SSL
5. Use your custom hostname in the .env `BUNNY_CDN_HOSTNAME=yourcdnhostname`

Now your videos are delivered over your custom hostname.

### Publish Configuration (optional)

To customize the default configuration, publish the config file:

```bash
php artisan vendor:publish --tag=bunny-stream-config
```

This will create `config/statamic/bunny-stream.php` where you can override the default values.

## Usage

This addon provides a Bunny fieldtype that you can add to any blueprint. It also includes a basic `bunny_video` fieldset with the Bunny field and a poster image field.

Use the video browser in the Control Panel (under the Bunny Stream navigation item) to upload, browse, and manage your videos.

Access to the video browser requires the `Manage Bunny videos` permission (super admins always have it). Assign it to a role under Users > Roles in the Bunny Stream permission group. The Bunny field's video picker is available to users with that permission or any editing permission (for example `edit articles entries`).

### Frontend Templates

The Bunny fieldtype augments to a `BunnyVideo` object. When used directly, it outputs the HLS playlist URL (backward compatible). You can also access the embed player, embed URL, thumbnail, and GUID.

#### Antlers

```antlers
{{# HLS playlist URL (backward compatible) #}}
{{ bunny_video }}

{{# Bunny's iframe embed player (responsive 16:9) #}}
{{ bunny_video:embed }}

{{# Just the embed URL (for custom iframe markup) #}}
{{ bunny_video:embed_url }}

{{# Thumbnail image URL #}}
{{ bunny_video:thumbnail }}

{{# Raw video GUID #}}
{{ bunny_video:guid }}
```

#### Blade

```blade
{{-- HLS playlist URL --}}
{{ $bunny_video }}

{{-- Embed player with default options --}}
{!! $bunny_video->embed() !!}

{{-- Embed with custom options --}}
{!! $bunny_video->embed(['autoplay' => 'true', 'muted' => 'true']) !!}

{{-- Just the embed URL --}}
{{ $bunny_video->embedUrl(['loop' => 'true']) }}

{{-- Thumbnail --}}
{{ $bunny_video->thumbnail() }}
```

#### Available Embed Parameters

Pass these as an array to `embed()` or `embedUrl()` in Blade:

| Parameter | Default | Description |
|---|---|---|
| `autoplay` | `false` | Auto-start playback |
| `preload` | `true` | Pre-download video data |
| `responsive` | `true` | Enable responsive sizing |
| `muted` | - | Start muted |
| `loop` | - | Loop playback |
| `captions` | - | Load specific caption track |
| `t` | - | Start timestamp (e.g. `45s`, `1h20m45s`) |
| `showSpeed` | - | Show playback speed controls |
| `showHeatmap` | - | Show viewer engagement heatmap |
| `playsinline` | - | Inline playback on mobile |
| `chromecast` | - | Enable Chromecast |
| `disableAirplay` | - | Disable AirPlay |
| `rememberPosition` | - | Resume from last position |

### Token Authentication

For private or protected videos, configure token authentication by adding these to your `.env`:

```bash
BUNNY_STREAM_TOKEN_KEY=your-token-key        # From Bunny Dashboard > Library > Security
BUNNY_STREAM_TOKEN_EXPIRY=24                  # Token lifetime in hours (default: 24)
```

When configured, embed URLs will automatically include signed `token` and `expires` parameters.

## Disclaimer

This addon is not affiliated with, endorsed by, or sponsored by Bunny.net. It is an independent project designed to
integrate Bunny.net's streaming services with Statamic. All trademarks, service marks, and company names mentioned
are the property of their respective owners.

Users of this addon are responsible for complying with Bunny.net's terms of service and any applicable usage policies.
We recommend reviewing Bunny.net's official documentation and support channels for any inquiries related to their
services.

## Issues

If you find any bugs or have feature requests, please [open an issue](https://github.com/jorisnoo/statamic-bunny-stream/issues) on GitHub.

## Native Assets workflow

Enable existing asset containers in `config/statamic/bunny-stream.php`:

```php
'asset_containers' => ['videos'],
'queue' => 'default',
'delete_remote' => false,
'show_dashboard' => true,
```

Editors upload originals to these containers and select them with ordinary Assets fields in entries, Bard, and Replicator. Video uploads queue a transfer to Bunny. The asset editor automatically gains a **Bunny Stream** field with status, playback, retry, custom thumbnails, and the optional chapter tools. Images and other files are unaffected. Metadata is managed by the integration; editing the form cannot replace remote GUIDs.

Use a persistent Laravel queue connection, such as database or Redis. The configured `queue` is a queue **name**, not a connection. Run its workers continuously. Upload jobs allow one hour; set the connection's `retry_after` (or SQS visibility timeout) above 3660 seconds and the worker timeout to 3600 seconds. After upload, the job checks Bunny's encoding status once a minute for up to 12 hours. Queue failures remain visible in the asset editor and Laravel's failed-job tooling. The CP status display polls for up to ten minutes; refresh to continue monitoring long encodes.

Configure PHP, your web server/proxy, and Statamic's upload limits for the largest original you expect. Back up both the asset disk and its `.meta` files. Originals inherit the disk's access policy; use a private disk when originals must not be publicly downloadable. Bunny iframe signing does not change asset-disk visibility.

Reuploads retain the previous stream until the replacement is ready. Renames retain stream identity. Once `asset_containers` is set, remote cleanup is queued, retryable, and disabled by default, including the legacy dashboard's delete endpoint. With no asset containers configured, the dashboard deletes immediately as before. Enable it only after every consumer of the library has migrated. A delete job refuses to remove a GUID still owned by another asset. Turning cleanup on does not retroactively delete videos retained while it was off.

If creating a remote video is interrupted before its GUID is saved, retries deliberately stop to avoid creating duplicates. Find the remote video with the generation identifier shown in its Bunny title, then use **Attach and retry** in the asset editor. If no such video exists, investigate the failed request before restarting that upload.

### Streaming in templates

Keep the native asset `url` for the stored source. The asset's `bunny_stream` field augments to the same `BunnyVideo` helper as the legacy field:

```antlers
{{ video:bunny_stream:embed }}
{{ video:bunny_stream:url }}
{{ video:bunny_stream:thumbnail }}
```

```blade
@php($stream = $video->augmentedValue('bunny_stream')->value())
@if ($stream)
    {!! $stream->embed() !!}
@endif
```

To write templates that work both before and after migrating a legacy `bunny` field, use the `bunny_stream` modifier, or `BunnyVideo::from()` in Blade. Each accepts either a legacy value or an asset:

```antlers
{{ stream = video | bunny_stream }}
{{ if stream }}{{ stream:embed }}{{ /if }}
```

These examples assume a single Assets field (`max_files: 1`). Loop through multi-asset fields as usual. Before the first upload is ready, `bunny_stream` is null; after a replacement starts, it continues to expose the previous stream. Existing `bunny` fields and their templates continue to work throughout migration.

## Migrating existing content

Moving a site from legacy `bunny` fields to assets takes two steps:

1. `bunny-stream:import-assets` imports the Bunny library as assets while the site stays live.
2. `bunny-stream:migrate-fields` converts fields and content in a short maintenance window. It is journaled, so it can be resumed or rolled back.

Nothing changes until you run them. **Follow the step-by-step guide in [MIGRATION.md](MIGRATION.md).** It covers Bunny settings, queue setup, template changes, a dry run, verification, rollback and troubleshooting.

### Development checks

```bash
php tests/run.php
npm run build
```

The standalone checks boot the installed Laravel/Statamic runtime in a temporary directory and use fake queues/HTTP. The small video fixture is generated media; no external Bunny account is needed.

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
