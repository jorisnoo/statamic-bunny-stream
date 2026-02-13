<?php
return [
    // The unique identifier for your Bunny Stream library.
    // This is required to interact with the Bunny Stream API and manage your videos.
    'library_id' => env('BUNNY_LIBRARY_ID'),

    // The hostname for your Bunny CDN, used for streaming videos.
    // Typically, this is a custom domain or a Bunny.net-provided URL.
    'hostname' => env('BUNNY_CDN_HOSTNAME'),

    // The API key for authenticating requests to the Bunny Stream API.
    // This should be kept secure and not exposed in frontend code.
    'api_key' => env('BUNNY_API_KEY'),
];
