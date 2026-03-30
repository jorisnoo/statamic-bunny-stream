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
];
