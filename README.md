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

Access to the video browser requires the `Manage Bunny videos` permission (super admins always have it). Assign it to a role under Users > Roles in the Bunny Stream permission group.

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

Use a persistent Laravel queue connection, such as database or Redis. The configured `queue` is a queue **name**, not a connection. Run its workers continuously. Upload jobs allow one hour; set the connection's `retry_after` (or SQS visibility timeout) above 3660 seconds and the worker timeout to 3600 seconds. Encoding polls run once per minute with a bounded attempt count. Queue failures remain visible in the asset editor and Laravel's failed-job tooling. The CP status display polls for up to ten minutes; refresh to continue monitoring long encodes.

Configure PHP, your web server/proxy, and Statamic's upload limits for the largest original you expect. Back up both the asset disk and its `.meta` files. Originals inherit the disk's access policy; use a private disk when originals must not be publicly downloadable. Bunny iframe signing does not change asset-disk visibility.

Reuploads retain the previous stream until the replacement is ready. Renames retain stream identity. Remote cleanup is queued, retryable, and disabled by default, including the legacy dashboard's delete endpoint. Enable it only after every consumer of the library has migrated. A delete job refuses to remove a GUID still owned by another asset. Turning cleanup on does not retroactively delete videos retained while it was off.

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

These examples assume a single Assets field (`max_files: 1`). Loop through multi-asset fields as usual. Before the first upload is ready, `bunny_stream` is null; after a replacement starts, it continues to expose the previous stream. Existing `bunny` fields and their templates continue to work throughout migration.

## Migrating existing content

Migration is explicit: installing/updating the addon never rewrites content. Test on a staging copy first.

1. Configure the target container and its backups. Leave `delete_remote` disabled.
2. Inventory and import the Bunny library:

   ```bash
   php artisan bunny-stream:import-assets videos --dry-run
   php artisan bunny-stream:import-assets videos
   ```

   Imports download retained originals first and use an MP4 derivative when the original returns 404. GUIDs, remote thumbnails, and chapters are preserved; no new remote videos are created. Derivatives are labelled as such. Downloads are inspected before an asset is registered. Failed items remain in the import manifest and block conversion of their references. Rerun to resume; existing asset mappings are found by library/GUID, including renamed and replaced assets. Historical GUID aliases remain on the asset so old revisions keep resolving after a replacement.

   [Bunny originals](https://bunny.net/docs/stream/storage-structure) require “Keep original files” to have been enabled. [MP4 fallbacks](https://bunny.net/docs/stream/mp4-downloads) must also exist for the video. Protected downloads may require `BUNNY_STREAM_CDN_TOKEN_KEY` (the Pull Zone token key, distinct from iframe signing) and `BUNNY_STREAM_DOWNLOAD_REFERER`. API credentials are never sent to the CDN. Authentication failures are reported rather than treated as missing originals.

3. Prepare matching template changes, then preview a field batch:

   ```bash
   php artisan bunny-stream:migrate-fields --container=videos --field=bunny_video --dry-run
   # Or explicitly select every Bunny definition:
   php artisan bunny-stream:migrate-fields --container=videos --field='*' --dry-run
   ```

   `--field` selects raw definition handles across the site, including every use of a shared fieldset and its prefixed imports. Repeat the option for more handles. The target container may be omitted when exactly one is enabled. The tool converts single GUIDs to single asset paths and keeps handles, validation, conditions, and poster fields. It traverses Statamic's native Bard, Replicator, Grid, and Group fields. Forms, navigation/custom content owners, and unsupported nested fieldtypes are blocked when affected. All references in the selected batch must resolve before anything is written.

4. Back up content and blueprints, enter maintenance mode, and stop queue workers, scheduled tasks, and other content writers. Apply the same command without `--dry-run`, deploy the matching templates, restart workers, and leave maintenance mode after verification. The command prints a journal identifier before writing.
5. After every field and template has migrated, set `show_dashboard` to `false`. Legacy selection endpoints remain available. Retain the compatibility fieldtype and migration markers while historical revisions may still be restored.

The converter covers file-backed content, users, asset metadata, and working copies. Affected database/custom content stores require their own repository migration and are rejected rather than silently skipped. Historical revision files remain unchanged: saving/restoring a working copy translates mapped GUIDs in fields marked `bunny_stream_migrated`. Missing or ambiguous assets block that save. Do not remove these blueprint markers until legacy revisions are no longer needed.

Journals and import reports live in `storage/app/bunny-stream`. Journals contain the exact before/after contents, potentially including private content and user data; keep that directory private and backed up during the migration window. Under the same editing freeze:

```bash
php artisan bunny-stream:migrate-fields --resume=migration-YYYYMMDD-HHMMSS-identifier
php artisan bunny-stream:rollback migration-YYYYMMDD-HHMMSS-identifier
```

Resume and rollback check every file before writing and refuse subsequent edits. Rollback restores content and definitions, leaving imported assets and remote videos intact. Restore the corresponding templates before reopening the site. Roll back multiple batches in reverse order. This tool does not rewrite templates automatically or migrate historical revision files.

### Development checks

```bash
php tests/run.php
npm run build
```

The standalone checks boot the installed Laravel/Statamic runtime in a temporary directory and use fake queues/HTTP. The small video fixture is generated media; no external Bunny account is needed.
