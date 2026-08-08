<?php

/*
|--------------------------------------------------------------------------
| Social networks
|--------------------------------------------------------------------------
|
| One entry per network under `networks`, one entry per publishing identity
| under `accounts`. A network is a transport (its credentials, its API base,
| its queue); an account is a place to post (an Instagram professional
| account, a YouTube channel, a Telegram chat).
|
| Account keys are the CONSUMER's vocabulary, not the network's. Sahmino keys
| Instagram by locale ('fa', 'en') because a share is routed by the language
| of the video it distributes; a network with one identity uses 'default'.
|
| NOTE for consumers publishing this file: `mergeConfigFrom` merges only the
| FIRST level of the array, so a published copy must stay the WHOLE file. A
| nested key you drop is NOT backfilled from the package's copy, and a nested
| key a later package version adds does not appear until you re-publish.
|
*/

return [

    'networks' => [

        'instagram' => [
            // Which registered driver serves this network. Present so an extra
            // network ('instagram_agency') can reuse a driver with its own
            // credentials, and so `SocialManager::extend()` can replace one.
            'driver' => 'instagram',

            'enabled' => (bool) env('SOCIAL_INSTAGRAM_ENABLED', false),

            // The Meta app behind the Instagram Login tokens. The app secret is
            // only needed to exchange/refresh a long-lived token.
            'app_id' => env('SOCIAL_INSTAGRAM_APP_ID', ''),
            'app_secret' => env('SOCIAL_INSTAGRAM_APP_SECRET', ''),

            // Instagram Login (graph.instagram.com), NOT the Facebook-login
            // Graph host: the token shape (IGAA…) and the deletion behaviour
            // differ between the two, and only this host accepts our tokens.
            'api_base' => 'https://graph.instagram.com/v23.0',

            // Meta PULLS the media from a public URL, so a container can sit in
            // IN_PROGRESS for a while on a long video.
            'poll_interval_seconds' => 5,
            'poll_budget_seconds' => 300,

            'queue' => 'shares',
        ],

        'youtube' => [
            'driver' => 'youtube',

            'enabled' => (bool) env('SOCIAL_YOUTUBE_ENABLED', false),

            'client_id' => env('SOCIAL_YOUTUBE_CLIENT_ID', ''),
            'client_secret' => env('SOCIAL_YOUTUBE_CLIENT_SECRET', ''),

            // One refresh token per connected Google account, obtained once
            // through the authorization-code flow and then never expiring
            // (unless the user revokes it or the project stays in testing).
            'refresh_token' => env('SOCIAL_YOUTUBE_REFRESH_TOKEN', ''),

            'api_base' => 'https://www.googleapis.com/youtube/v3',
            'upload_base' => 'https://www.googleapis.com/upload/youtube/v3',
            'analytics_base' => 'https://youtubeanalytics.googleapis.com/v2',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'authorize_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',

            'privacy_status' => 'public',
            'made_for_kids' => false,
            'category_id' => '25',

            'queue' => 'shares',
        ],

        'telegram' => [
            'driver' => 'telegram',

            'enabled' => (bool) env('SOCIAL_TELEGRAM_ENABLED', false),

            'token' => env('SOCIAL_TELEGRAM_TOKEN', ''),

            // Telegram's ceiling is ~20 messages/minute PER CHAT, and a forum
            // supergroup is one chat however many topics it has — so this is a
            // single shared budget and concurrency cannot buy throughput.
            // 3200ms is ~18/min with enough headroom that clock drift cannot
            // push a burst over the line.
            'spacing_ms' => 3200,

            // The documented multipart ceiling is 50MB but it governs the whole
            // REQUEST, not the file: a 49.9MB video plus its thumbnail and
            // fields answers 413. 45MB leaves room for the envelope.
            'upload_max_bytes' => 45 * 1024 * 1024,
            'photo_max_bytes' => 10 * 1024 * 1024,

            'queue' => 'telegram',
        ],

    ],

    'accounts' => [

        'instagram' => [
            'fa' => ['id' => '', 'handle' => '', 'token' => env('SOCIAL_INSTAGRAM_FA_TOKEN', '')],
            'en' => ['id' => '', 'handle' => '', 'token' => env('SOCIAL_INSTAGRAM_EN_TOKEN', '')],
        ],

        'youtube' => [
            'default' => ['id' => '', 'handle' => ''],
        ],

        'telegram' => [
            'default' => ['id' => env('SOCIAL_TELEGRAM_CHAT_ID', '')],
        ],

    ],

];
