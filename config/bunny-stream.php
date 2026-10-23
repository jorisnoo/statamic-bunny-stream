<?php
return [
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

    // Token expiry time in hours. Defaults to 24 hours.
    'token_expiry' => env('BUNNY_STREAM_TOKEN_EXPIRY', 24),

    // Safari and iOS start playback on the first rendition in the HLS playlist, which
    // Bunny lists in encoding order (usually 360p). The playlist is served through
    // the site with the sharpest rendition up to this many lines moved to the front.
    // Set to null to link to Bunny's playlist directly.
    'playlist_start_resolution' => env('BUNNY_STREAM_PLAYLIST_START_RESOLUTION', 720),

    // Enable chapter editing and automatic chapter generation.
    'chapters' => env('BUNNY_STREAM_CHAPTERS', false),

    // The Laravel filesystem disk to use for video backups.
    // Set to a disk name (e.g., 'local', 's3') to enable the bunny-stream:backup command.
    // Leave null to disable the backup feature.
    'backup_disk' => env('BUNNY_STREAM_BACKUP_DISK'),
];
