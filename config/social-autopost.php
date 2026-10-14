<?php

declare(strict_types=1);

use Siberfx\SocialAutopost\Networks\Facebook\FacebookNetwork;
use Siberfx\SocialAutopost\Networks\Instagram\InstagramNetwork;
use Siberfx\SocialAutopost\Networks\LinkedIn\LinkedInNetwork;
use Siberfx\SocialAutopost\Networks\TikTok\TikTokNetwork;
use Siberfx\SocialAutopost\Networks\X\XNetwork;
use Siberfx\SocialAutopost\Notifications\Channels\MailChannel;
use Siberfx\SocialAutopost\Notifications\Channels\SlackChannel;
use Siberfx\SocialAutopost\Notifications\Channels\TelegramChannel;
use Siberfx\SocialAutopost\Stores\DatabaseConnectionStore;

return [

    /*
    | Every network the package knows. A network is switched on with its
    | "enabled" flag and posts automatically with its "autopost" flag. A network
    | that is off is hidden everywhere: UI, routes (404), commands and sharing.
    |
    | To add a network, write a class implementing Siberfx\SocialAutopost\Contracts\SocialNetwork
    | and add an entry here. The class reads its own keys from its entry.
    */
    'networks' => [

        'linkedin' => [
            'enabled' => (bool) env('LINKEDIN_ENABLED', false),
            'autopost' => (bool) env('LINKEDIN_AUTOPOST', false),
            'class' => LinkedInNetwork::class,
            // Developer portal → My apps → Auth.
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            // Must equal an "Authorized redirect URL" exactly. Null uses the package callback route.
            'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
            'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
            // Also list the organization pages the member runs, so shares can go to them.
            // Needs LinkedIn's Community Management API product on the app; without it
            // LinkedIn refuses the login. Off: the member's own profile is the only page.
            'organizations' => (bool) env('LINKEDIN_ORGANIZATIONS', false),
            'organization_scopes' => ['r_organization_admin', 'w_organization_social'],
            // LinkedIn-Version header (YYYYMM).
            'api_version' => env('LINKEDIN_API_VERSION', '202609'),
            // PUBLIC or CONNECTIONS.
            'visibility' => env('LINKEDIN_VISIBILITY', 'PUBLIC'),
            // Links a post in notifications; {id} is the post id.
            'post_url' => 'https://www.linkedin.com/feed/update/{id}/',
            'brand' => ['color' => '#0A66C2'],
        ],

        'x' => [
            'enabled' => (bool) env('X_ENABLED', false),
            'autopost' => (bool) env('X_AUTOPOST', false),
            'class' => XNetwork::class,
            // Developer portal → your app → User authentication settings (OAuth 2.0).
            'client_id' => env('X_CLIENT_ID'),
            // Optional: a PKCE-only public app has no secret.
            'client_secret' => env('X_CLIENT_SECRET'),
            'redirect_uri' => env('X_REDIRECT_URI'),
            'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
            'post_url' => 'https://x.com/i/web/status/{id}',
            'brand' => ['color' => '#000000'],
        ],

        'facebook' => [
            'enabled' => (bool) env('FACEBOOK_ENABLED', false),
            'autopost' => (bool) env('FACEBOOK_AUTOPOST', false),
            'class' => FacebookNetwork::class,
            'app_id' => env('FACEBOOK_APP_ID'),
            'app_secret' => env('FACEBOOK_APP_SECRET'),
            // The Page selected after connecting. Null selects the first Page the account
            // manages. Every managed Page is listed; choose more under PUT /social/facebook/pages.
            'page_id' => env('FACEBOOK_PAGE_ID'),
            'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
            'api_version' => env('FACEBOOK_API_VERSION', 'v25.0'),
            'post_url' => 'https://www.facebook.com/{id}',
            'brand' => ['color' => '#1877F2'],
        ],

        // Instagram and TikTok post media, not links: give the LinkPost an
        // ->image() or ->video(). The commentary becomes the caption.

        'instagram' => [
            'enabled' => (bool) env('INSTAGRAM_ENABLED', false),
            'autopost' => (bool) env('INSTAGRAM_AUTOPOST', false),
            'class' => InstagramNetwork::class,
            // Meta app → Instagram → API setup with Instagram login: the Instagram app ID and secret.
            'client_id' => env('INSTAGRAM_CLIENT_ID'),
            'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
            'redirect_uri' => env('INSTAGRAM_REDIRECT_URI'),
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            'api_version' => env('INSTAGRAM_API_VERSION', 'v25.0'),
            // Instagram downloads and processes the media before it can be published:
            // check this many times, this many seconds apart, then let the job retry.
            'processing_checks' => 10,
            'processing_interval' => 5,
            'brand' => ['color' => '#E4405F'],
        ],

        'tiktok' => [
            'enabled' => (bool) env('TIKTOK_ENABLED', false),
            'autopost' => (bool) env('TIKTOK_AUTOPOST', false),
            'class' => TikTokNetwork::class,
            // developers.tiktok.com → your app: Login Kit and Content Posting API (Direct Post).
            'client_key' => env('TIKTOK_CLIENT_KEY'),
            'client_secret' => env('TIKTOK_CLIENT_SECRET'),
            'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
            'scopes' => ['user.info.basic', 'video.publish'],
            // PUBLIC_TO_EVERYONE, MUTUAL_FOLLOW_FRIENDS, FOLLOWER_OF_CREATOR or SELF_ONLY.
            // Until TikTok audits your app, only SELF_ONLY (private) posts are allowed.
            'privacy_level' => env('TIKTOK_PRIVACY_LEVEL', 'PUBLIC_TO_EVERYONE'),
            'brand' => ['color' => '#000000'],
        ],

    ],

    /*
    | Where connections are kept, encrypted with APP_KEY. Swap for your own
    | class implementing Siberfx\SocialAutopost\Contracts\ConnectionStore.
    */
    'connection_store' => DatabaseConnectionStore::class,

    'routes' => [
        'enabled' => true,
        'prefix' => 'social',
        'name' => 'social-autopost.',
        // The gate denies everyone until your app defines it, e.g. in AppServiceProvider::boot():
        // Gate::define('manage-social-autopost', fn (User $user) => $user->is_admin);
        'middleware' => ['web', 'auth', 'can:manage-social-autopost'],
        // Where the OAuth callback sends the browser: ?social={network}&state=connected|cancelled|error|…
        'after_connect' => '/',
    ],

    'autopost' => [
        // Seeders and Artisan commands do not auto-post unless this is true.
        'in_console' => false,
        'queue_connection' => null,
        'queue' => null,
    ],

    'ui' => [
        // false = headless: no views, Blade or Livewire components; routes answer JSON only.
        'enabled' => (bool) env('SOCIAL_UI', true),
        // tailwind | bootstrap | the name of a folder of published views
        'theme' => env('SOCIAL_UI_THEME', 'tailwind'),
    ],

    /*
    | Messages sent to Telegram, Slack and/or email when a post goes out, an
    | automatic share gives up, or a token is about to expire
    | (social:check-token). A failing channel never fails the share.
    | Try your settings with: php artisan social:test-notification
    */
    'notifications' => [
        'enabled' => (bool) env('SOCIAL_NOTIFY', false),

        'events' => [
            'shared' => true,
            'failed' => true,
            'token_expiring' => true,
        ],

        // true: one queued job per channel, retried `tries` times with `backoff`.
        // false: sent at once inside the request or job that shared, no retries.
        'queued' => (bool) env('SOCIAL_NOTIFY_QUEUED', true),
        'queue_connection' => null,
        'queue' => null,
        'tries' => 3,
        'backoff' => [30, 120],

        'channels' => [
            'telegram' => [
                'enabled' => (bool) env('SOCIAL_NOTIFY_TELEGRAM', false),
                'class' => TelegramChannel::class,
                'bot_token' => env('SOCIAL_TELEGRAM_BOT_TOKEN'),
                'chat_id' => env('SOCIAL_TELEGRAM_CHAT_ID'),
                'thread_id' => env('SOCIAL_TELEGRAM_THREAD_ID'),
            ],
            'slack' => [
                'enabled' => (bool) env('SOCIAL_NOTIFY_SLACK', false),
                'class' => SlackChannel::class,
                'webhook_url' => env('SOCIAL_SLACK_WEBHOOK_URL'),
            ],
            'mail' => [
                'enabled' => (bool) env('SOCIAL_NOTIFY_MAIL', false),
                'class' => MailChannel::class,
                // One address or several, comma separated.
                'to' => env('SOCIAL_NOTIFY_MAIL_TO'),
                // A mailer from config/mail.php; null uses your default mailer.
                'mailer' => env('SOCIAL_NOTIFY_MAILER'),
            ],
            // Your own: 'teams' => ['enabled' => true, 'class' => App\TeamsChannel::class, …],
        ],
    ],

    /*
    | Queued auto-share jobs: one per model and network at a time. Retried only
    | when the network answers 429, 5xx or not at all.
    */
    'job' => [
        'tries' => 3,
        'backoff' => [60, 300],
    ],

    // social:check-token and the expiry warnings: warn when fewer days remain.
    'expiry_warning_days' => 7,

    // How long a network's connection status is cached (seconds).
    'status_cache_ttl' => 300,

    'http' => [
        'timeout' => 20,
    ],

];
