<?php
return [
    // Existing asset containers whose video uploads should be sent to Bunny.
    'asset_containers' => [],

    // Use a persistent queue connection (not sync) for uploads and encoding polls.
    'queue' => 'default',

    // Keep remote videos during migration, including videos used by legacy fields.
    // Enable only after every consumer of this library has migrated.
    'delete_remote' => false,

    // Hide after legacy fields have been migrated. Their endpoints remain available.
    'show_dashboard' => true,

    // The unique identifier for your Bunny Stream library.
    // This is required to interact with the Bunny Stream API and manage your videos.
    'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),

    // The hostname for your Bunny CDN, used for streaming videos.
    // Typically, this is a custom domain or a Bunny.net-provided URL.
    'hostname' => env('BUNNY_STREAM_CDN_HOSTNAME'),

    // The API key for authenticating requests to the Bunny Stream API.
    // This should be kept secure and not exposed in frontend code.
    'api_key' => env('BUNNY_STREAM_API_KEY'),

    // Token authentication key for signed embed URLs (private/protected videos).
    // Found in Bunny Dashboard under your library's Security settings.
    // Leave null to disable token authentication.
    'token_key' => env('BUNNY_STREAM_TOKEN_KEY'),

    // Pull Zone token key (distinct from iframe signing), for protected source downloads.
    'cdn_token_key' => env('BUNNY_STREAM_CDN_TOKEN_KEY'),
    'download_referer' => env('BUNNY_STREAM_DOWNLOAD_REFERER'),

    // Token expiry time in hours. Defaults to 24 hours.
    'token_expiry' => env('BUNNY_STREAM_TOKEN_EXPIRY', 24),

    // Enable chapter editing and automatic chapter generation.
    'chapters' => env('BUNNY_STREAM_CHAPTERS', false),

    // The Laravel filesystem disk to use for video backups.
    // Set to a disk name (e.g., 'local', 's3') to enable the bunny-stream:backup command.
    // Leave null to disable the backup feature.
    'backup_disk' => env('BUNNY_STREAM_BACKUP_DISK'),
];
