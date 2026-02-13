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
BUNNY_LIBRARY_ID=yourid            # Your Bunny Stream LibraryID
BUNNY_API_KEY=yourapikey           # Your Libraries API Key
BUNNY_CDN_HOSTNAME=yourcdnhostname # Your Libraries CDN Hostname
```

You can find these values in your Bunny Stream Dashboard at [https://dash.bunny.net/stream/](https://dash.bunny.net/stream/) `Delivery > Stream > API`

### Custom CDN Hostname

To add a custom hostname you can do the following:

1. Login to your bunny dashboard and head over to `Delivery > Stream > API`
2. At Pull zone click `Manage`
3. Create a CName entry in your DNS settings pointing to the displayed bunny CDN hostname
4. Enter your custom hostname in the bunny settings and activate SSL
5. Use your custom hostname in the .env `BUNNY_CDN_HOSTNAME=yourcdnhostname`

Now your videos are delivered over your custom hostname.

### Publish Configuration (optional)

After installing the addon you can publish and update the default configuration:

```bash
php artisan vendor:publish --tag=bunny-config
```

## Usage

This addon provides a Bunny fieldtype that you can add to any blueprint. It also includes a basic `bunny_video` fieldset with the Bunny field and a poster image field.

Use the video browser in the Control Panel (under the Bunny Stream navigation item) to upload, browse, and manage your videos.

## Disclaimer

This addon is not affiliated with, endorsed by, or sponsored by Bunny.net. It is an independent project designed to
integrate Bunny.net's streaming services with Statamic. All trademarks, service marks, and company names mentioned
are the property of their respective owners.

Users of this addon are responsible for complying with Bunny.net's terms of service and any applicable usage policies.
We recommend reviewing Bunny.net's official documentation and support channels for any inquiries related to their
services.

## Issues

If you find any bugs or have feature requests, please [open an issue](https://github.com/jorisnoo/statamic-bunny-stream/issues) on GitHub.
