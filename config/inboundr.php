<?php

return [

    // An `inb_` key, from the console under API keys.
    'api_key' => env('INBOUNDR_API_KEY'),

    'base_url' => env('INBOUNDR_BASE_URL', 'https://inboundr.net'),

    // Seconds.
    'timeout' => (float) env('INBOUNDR_TIMEOUT', 30),

    'webhook' => [

        // The endpoint's `whsec_` signing secret, returned when it was created.
        'secret' => env('INBOUNDR_WEBHOOK_SECRET'),

        // How old a signature may be, in seconds.
        'tolerance' => 300,

        // Skip deliveries already handled, by delivery id. Retries reuse it.
        'dedupe' => true,

        // How long to remember a delivery id, in seconds.
        'dedupe_ttl' => 86400,

        // Cache store for delivery ids; null uses the default store.
        'cache_store' => env('INBOUNDR_CACHE_STORE'),

    ],

];
