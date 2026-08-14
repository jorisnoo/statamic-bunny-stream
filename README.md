# Video Stream GDPR Compliant (aka Bunny Stream)

> **Fork Notice:** This is a fork of [laborb/statamic-bunny-stream](https://github.com/niclasleonbock/statamic-bunny-stream) upgraded for **Statamic 6** and **Laravel 12**. Vue 2 components have been migrated to Vue 3. Public-facing frontend features (Antlers tags, frontend views) have been removed — this is now a **CP-only addon** for video management, the Bunny fieldtype, and uploads.

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
