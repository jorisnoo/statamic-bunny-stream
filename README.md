# Video Stream GDPR Compliant (aka Bunny Stream)

> Bunny Stream is a Statamic add-on that integrates the Bunny Stream API for single stream libraries into the Statamic CP.

## Features

- Native Statamic CP integration for video uploads and management
- Browse, search, and manage Bunny Stream videos from the Control Panel
- Upload videos directly via TUS protocol
- Custom thumbnails and cover images for full branding control
- Bunny fieldtype for selecting videos in blueprints (works with Bard and Replicator)
- GDPR/DSGVO-compliant video hosting with no cookies or consent manager required

## Bunny Account Required 🐰

To use this addon, you'll need a Bunny.net account. If you don't have one yet, you can sign up using our 
[affiliate link](https://bunny.net?ref=uhvsqhaw0n). By doing so, you'll be supporting the development of this 
addon at no extra cost to you. 💙

Thank you for your support! 🚀

## Installation

Install the addon using composer:

```bash
composer require laborb/statamic-bunny-stream
```

## Configuration

You need to provide the following .env-Variables:

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

## Support

If you have any questions, feature requests or find any bugs, feel free to [contact us](mailto:support@laborb.de).

You can also just create an issue on Github. We will get back to you as soon as possible.
