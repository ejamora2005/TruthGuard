<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'google_picker' => [
        'client_id' => env('GOOGLE_PICKER_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'key' => env('GOOGLE_PICKER_API_KEY', env('GOOGLE_FACT_CHECK_API_KEY')),
        'app_id' => env('GOOGLE_PICKER_APP_ID'),
        'scope' => env('GOOGLE_PICKER_SCOPE', 'https://www.googleapis.com/auth/drive.readonly'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'live_verification' => [
        'enabled' => env('LIVE_VERIFICATION_ENABLED', true),
        'refresh_on_view' => env('LIVE_VERIFICATION_REFRESH_ON_VIEW', false),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.4'),
        'analysis_enabled' => env('OPENAI_ANALYSIS_ENABLED', true),
        'claim_extraction_enabled' => env('OPENAI_CLAIM_EXTRACTION_ENABLED', true),
        'timeout' => env('OPENAI_TIMEOUT', 40),
        'max_completion_tokens' => env('OPENAI_MAX_COMPLETION_TOKENS', 1400),
        'image_context_enabled' => env('OPENAI_IMAGE_CONTEXT_ENABLED', true),
        'image_max_bytes' => env('OPENAI_IMAGE_MAX_BYTES', 4194304),
        'social_search_enabled' => env('OPENAI_SOCIAL_SEARCH_ENABLED', true),
        'social_search_context_size' => env('OPENAI_SOCIAL_SEARCH_CONTEXT_SIZE', 'medium'),
        'social_search_max_results' => env('OPENAI_SOCIAL_SEARCH_MAX_RESULTS', 4),
        'social_search_domains' => array_values(array_filter(array_map(
            static fn (string $domain): string => trim($domain),
            explode(',', env('OPENAI_SOCIAL_SEARCH_DOMAINS', 'facebook.com,instagram.com,tiktok.com,x.com,twitter.com,youtube.com,reddit.com,threads.net'))
        ))),
        'monthly_token_budget' => env('OPENAI_MONTHLY_TOKEN_BUDGET', 0),
        'estimated_input_tokens_per_request' => env('OPENAI_ESTIMATED_INPUT_TOKENS_PER_REQUEST', 1800),
        'estimated_output_tokens_per_request' => env('OPENAI_ESTIMATED_OUTPUT_TOKENS_PER_REQUEST', env('OPENAI_MAX_COMPLETION_TOKENS', 1400)),
        'token_warning_threshold' => env('OPENAI_TOKEN_WARNING_THRESHOLD', 80),
    ],

    'google_fact_check' => [
        'key' => env('GOOGLE_FACT_CHECK_API_KEY'),
        'language_code' => env('GOOGLE_FACT_CHECK_LANGUAGE_CODE', 'en-US'),
        'page_size' => env('GOOGLE_FACT_CHECK_PAGE_SIZE', 3),
        'timeout' => env('GOOGLE_FACT_CHECK_TIMEOUT', 3),
        'feed_queries' => array_values(array_filter(array_map('trim', explode(',', env('GOOGLE_FACT_CHECK_FEED_QUERIES', 'Reuters fact check,Philippines,viral misinformation,fake news'))))),
        'feed_publisher_sites' => array_values(array_filter(array_map('trim', explode(',', env('GOOGLE_FACT_CHECK_FEED_PUBLISHER_SITES', 'verafiles.org,rappler.com,factcheck.afp.com,abs-cbn.com,pressone.ph,tsek.ph,reuters.com'))))),
        'feed_page_size' => env('GOOGLE_FACT_CHECK_FEED_PAGE_SIZE', 8),
        'feed_cache_minutes' => env('GOOGLE_FACT_CHECK_FEED_CACHE_MINUTES', 30),
        'feed_cache_seconds' => env('GOOGLE_FACT_CHECK_FEED_CACHE_SECONDS', 10),
        'feed_refresh_seconds' => env('GOOGLE_FACT_CHECK_FEED_REFRESH_SECONDS', 10),
        'feed_max_queries' => env('GOOGLE_FACT_CHECK_FEED_MAX_QUERIES', 4),
        'feed_max_publishers' => env('GOOGLE_FACT_CHECK_FEED_MAX_PUBLISHERS', 7),
        'feed_max_age_days' => env('GOOGLE_FACT_CHECK_FEED_MAX_AGE_DAYS', 120),
        'feed_direct_sources_enabled' => env('GOOGLE_FACT_CHECK_FEED_DIRECT_SOURCES_ENABLED', true),
        'feed_direct_sources' => [
            [
                'publisher' => 'Rappler',
                'domain' => 'rappler.com',
                'url' => 'https://www.rappler.com/wp-json/wp/v2/posts?categories=712&per_page=100',
            ],
            [
                'publisher' => 'VERA Files',
                'domain' => 'verafiles.org',
                'url' => 'https://verafiles.org/wp-json/wp/v2/posts?categories=2035&per_page=100',
            ],
        ],
        'feed_images_enabled' => env('GOOGLE_FACT_CHECK_FEED_IMAGES_ENABLED', true),
        'feed_image_timeout' => env('GOOGLE_FACT_CHECK_FEED_IMAGE_TIMEOUT', 1),
        'feed_image_limit' => env('GOOGLE_FACT_CHECK_FEED_IMAGE_LIMIT', 15),
        'feed_image_miss_cache_minutes' => env('GOOGLE_FACT_CHECK_FEED_IMAGE_MISS_CACHE_MINUTES', 10),
        'feed_news_notifications_enabled' => env('GOOGLE_FACT_CHECK_FEED_NEWS_NOTIFICATIONS_ENABLED', true),
        'feed_news_notifications_seed_baseline' => env('GOOGLE_FACT_CHECK_FEED_NEWS_NOTIFICATIONS_SEED_BASELINE', false),
        'feed_news_notifications_notify_existing' => env('GOOGLE_FACT_CHECK_FEED_NEWS_NOTIFICATIONS_NOTIFY_EXISTING', false),
        'feed_news_notification_limit' => env('GOOGLE_FACT_CHECK_FEED_NEWS_NOTIFICATION_LIMIT', 5),
        'feed_news_notification_chunk_size' => env('GOOGLE_FACT_CHECK_FEED_NEWS_NOTIFICATION_CHUNK_SIZE', 100),
    ],

    'gnews' => [
        'key' => env('GNEWS_API_KEY'),
        'lang' => env('GNEWS_LANG', 'en'),
        'country' => env('GNEWS_COUNTRY'),
        'max' => env('GNEWS_MAX', 3),
        'timeout' => env('GNEWS_TIMEOUT', 3),
        'lookback_hours' => env('GNEWS_LOOKBACK_HOURS', 168),
    ],

    'newsapi' => [
        'key' => env('NEWSAPI_API_KEY'),
        'language' => env('NEWSAPI_LANGUAGE', 'en'),
        'page_size' => env('NEWSAPI_PAGE_SIZE', 3),
        'timeout' => env('NEWSAPI_TIMEOUT', 3),
        'lookback_days' => env('NEWSAPI_LOOKBACK_DAYS', 7),
    ],

    'openweather' => [
        'key' => env('OPENWEATHER_API_KEY'),
        'units' => env('OPENWEATHER_UNITS', 'metric'),
        'lang' => env('OPENWEATHER_LANG', 'en'),
        'timeout' => env('OPENWEATHER_TIMEOUT', 3),
    ],

];
