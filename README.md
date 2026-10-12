# Social Autopost for Laravel

Share your Eloquent models to LinkedIn, X, Facebook, Instagram and TikTok, each one switched on or off on its own,
and add more networks later with one class and one config entry.

- Each network has two switches: **enabled** (on or off) and **autopost** (post automatically when a model goes live).
- A switched-off network is invisible: no UI, no routes (404), no auto-post, no sharing, and its commands say so.
- One connection per network, stored encrypted; one share history for all networks.
- Auto-post runs on a queue, once per model and network, with retries only when a network asks for it.
- Optional Telegram, Slack and email messages, each naming the network.
- Ready-made connection cards and share buttons (Tailwind or Bootstrap 5, Blade or Livewire), or headless.

Requires PHP 8.4+ and Laravel 12 or 13.

## Quick start

```bash
composer require siberfx/social-autopost
php artisan social:install --theme=tailwind
php artisan migrate
```

```dotenv
LINKEDIN_ENABLED=true
LINKEDIN_AUTOPOST=true     # post automatically when a model goes live
X_ENABLED=false
FACEBOOK_ENABLED=false
INSTAGRAM_ENABLED=false    # Instagram and TikTok need an image or video, see below
TIKTOK_ENABLED=false
```

```php
// app/Providers/AppServiceProvider.php → boot()
Gate::define('manage-social-autopost', fn (User $user) => $user->is_admin);
```

```php
use Siberfx\SocialAutopost\Concerns\PostsToSocial;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Posts\LinkPost;

class Article extends Model implements ShareableOnSocial
{
    use PostsToSocial;

    public function isLiveForSocial(): bool
    {
        return $this->status === 'published';
    }

    public function toSocialPost(): LinkPost
    {
        return LinkPost::make(route('blog.show', $this), $this->title)
            ->description(Str::limit(strip_tags($this->body), 250))
            ->commentary("New on the blog: {title}\n\n{url}");
    }
}
```

```blade
<x-social-autopost::connection network="linkedin" />
<x-social-autopost::connection network="x" />

<x-social-autopost::share-button :model="$article" network="facebook" />
<x-social-autopost::share-button :model="$article" />  {{-- every enabled, connected network --}}
```

## Switching networks on and off

Every network is listed in `config/social-autopost.php` under `networks`:

| Switch | Env (LinkedIn example) | Default | Meaning |
|---|---|---|---|
| `enabled` | `LINKEDIN_ENABLED` | `false` | Off: hidden everywhere, routes answer 404, sharing and commands refuse it. |
| `autopost` | `LINKEDIN_AUTOPOST` | `false` | On: queued automatically when a live model is saved. Manual sharing needs no switch. |

The env names are `LINKEDIN_`, `X_`, `FACEBOOK_`, `INSTAGRAM_` and `TIKTOK_` followed by `ENABLED` or `AUTOPOST`.
A network is only used when it is both switched on and connected.

```php
use Siberfx\SocialAutopost\Facades\Social;

Social::enabled();                  // collect(['linkedin', 'facebook'])
Social::isEnabled('x');             // false
Social::isConnected('linkedin');    // true when a connection is stored (no network call)

Social::share($article);            // every enabled, connected network; one record per network
Social::share($article, 'x');       // only X; rethrows if X refuses
Social::queue($article, 'facebook');
Social::wasPosted($article, 'linkedin');
```

`share()` returns one `SharedPost` per network it tried, posted or failed. When sharing to all,
one network's failure is recorded and the others still run.

## Setting up each network

Each network needs an app in its developer portal. Register the callback URL of each one you
switch on. The default is `{your app}/social/{network}/callback`; set `{NET}_REDIRECT_URI` when
your URL differs.

| Network | Developer portal | Register this callback | Credentials | Scopes or permissions |
|---|---|---|---|---|
| LinkedIn | developers.linkedin.com → your app | `https://example.com/social/linkedin/callback` | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | Products "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect" |
| X | developer.x.com → your app → User authentication settings (OAuth 2.0) | `https://example.com/social/x/callback` | `X_CLIENT_ID`, `X_CLIENT_SECRET` (optional for a PKCE-only public app) | `tweet.read tweet.write users.read offline.access` (set by the package) |
| Facebook | developers.facebook.com → your app → Facebook Login | `https://example.com/social/facebook/callback` | `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET` | `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`; the account must manage the Page |
| Instagram | developers.facebook.com → your app → Instagram → API setup with Instagram login → Business login settings | `https://example.com/social/instagram/callback` | `INSTAGRAM_CLIENT_ID`, `INSTAGRAM_CLIENT_SECRET` (the **Instagram** app ID and secret, not the Facebook ones) | `instagram_business_basic`, `instagram_business_content_publish`; a Business or Creator account |
| TikTok | developers.tiktok.com → your app → Login Kit and Content Posting API (Direct Post on) | `https://example.com/social/tiktok/callback` | `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` | `user.info.basic`, `video.publish`; verify the domain your media is served from |

Optional settings per network: `LINKEDIN_VISIBILITY` (`PUBLIC` or `CONNECTIONS`),
`LINKEDIN_API_VERSION`, `FACEBOOK_PAGE_ID` (the Page to post to; the first managed Page when
empty), `FACEBOOK_API_VERSION`.

X text is composed to 280 characters, counting every URL as 23. LinkedIn escapes characters it
reserves for mentions and hashtags. Facebook sends the link in its own field and takes the preview
from the page's Open Graph tags.

### Instagram and TikTok: posts need media

Instagram and TikTok post images and videos, not links. Give the post a public URL the network can
download; the commentary becomes the caption. LinkedIn, X and Facebook ignore the media.

```php
public function toSocialPost(): LinkPost
{
    return LinkPost::make(route('blog.show', $this), $this->title)
        ->commentary("{title}\n\nRead more: {url}")
        ->image($this->cover_url)        // Instagram: JPEG. TikTok: a photo post.
        ->video($this->clip_url);        // Instead of the image when set: a Reel / a TikTok video.
}
```

A post without media fails on these two networks with a clear message and is not retried; the
others still post. Links in captions are not clickable on either network.

- **Instagram** posts through a media container. It waits for Instagram to process the media
  (`processing_checks` × `processing_interval` seconds, default 10 × 5); a video that takes longer
  is retried by the job, and nothing posts twice. The token lasts 60 days and refreshes itself
  once fewer than 30 remain, whenever the status is read or a post goes out. Instagram has no API
  to revoke access: disconnecting forgets it here, and the account owner removes the app under
  Instagram → Settings → Apps and websites. `INSTAGRAM_API_VERSION` defaults to `v25.0`.
- **TikTok** pulls the media from its URL, so verify that domain or URL prefix in the TikTok
  developer portal. The post follows the creator's own settings: comments, duets and stitches stay
  off when they turned them off. `TIKTOK_PRIVACY_LEVEL` is `PUBLIC_TO_EVERYONE` (default),
  `MUTUAL_FOLLOW_FRIENDS`, `FOLLOWER_OF_CREATOR` or `SELF_ONLY`. **Until TikTok audits your app,
  only `SELF_ONLY` (private) posts are allowed**; the error says so and names the allowed levels.
  TikTok returns a publish id, not the video's id, so its notifications carry no link. The access
  token lasts a day and refreshes itself; the connection lasts a year.

## Connecting

Send an admin to `route('social-autopost.redirect', 'linkedin')`. The callback returns them to
`routes.after_connect` with `?social=linkedin&state=connected` (or `cancelled`, `error`,
`exchange_failed`, `invalid_state`, `missing_code`, `not_configured`).

```php
Social::isConnected('x');
Social::connection('x')->name;      // the account name, from the network
Social::disconnect('x');            // revokes at the network, then forgets it
```

## A ready-made connections page

```bash
php artisan social:page                        # standalone page, configured theme
php artisan social:page --layout=layouts.app   # inside your layout's @section('content')
php artisan social:page --livewire --theme=bootstrap --view=admin.social
```

The command writes `resources/views/social/connections.blade.php`. The page has one card for every
enabled network and a "Developer setup" table with each network's callback URL and auto-post
switch. Add a route for it and send people back to it after connecting:

```php
Route::view('/admin/social', 'social.connections')
    ->middleware(['auth', 'can:manage-social-autopost'])->name('social.connections');
```

```php
// config/social-autopost.php
'routes' => ['after_connect' => '/admin/social', /* … */],
```

Without `--layout`, the page is standalone and loads Tailwind or Bootstrap from a CDN. After that,
the page is your file to change.

Cards show a short note where a network needs one. Instagram and TikTok say a post needs media, and
TikTok mentions private-only posting until it audits your app. Set `networks.{key}.hint` to
replace a note, or `''` to hide it. Instagram cannot be revoked through its API, so its disconnect
question and message tell people where to remove the app. Use `networks.{key}.disconnect_note`
for your own network.

## React and Vue

The cards and share buttons are also available as data, for pages built in React or Vue. Ready-to-copy
components, in TypeScript with Tailwind, are in [`examples/`](examples/README.md): `SocialConnections`
(one card per enabled network) and `ShareButton` (shares one model now), for both React and Vue.

**1. Copy the components into your app:**

```bash
# React
cp -r vendor/siberfx/social-autopost/examples/react resources/js/components/social
# Vue
cp -r vendor/siberfx/social-autopost/examples/vue resources/js/components/social
```

**2. Build the props on the server** with `SocialProps`. It never includes tokens, and share URLs are
signed for their model:

```php
use Inertia\Inertia;
use Siberfx\SocialAutopost\Support\SocialProps;

Route::middleware(['auth', 'can:manage-social-autopost'])->group(function () {
    Route::get('/admin/social', fn () => Inertia::render('SocialConnections', [
        'connections' => SocialProps::connections(),   // one card per enabled network
        'flash' => SocialProps::flash(),               // the message after connecting
    ]));

    Route::get('/admin/posts/{post}', fn (Post $post) => Inertia::render('Posts/Show', [
        'post' => $post,
        'share' => SocialProps::share($post),               // every enabled, connected network
        'shareOnTikTok' => SocialProps::share($post, 'tiktok'),
    ]));
});
```

Set `routes.after_connect` to `'/admin/social'`, so the network sends people back to the page after
they connect.

**3a. Use them in React:**

```tsx
// resources/js/pages/SocialConnections.tsx
import SocialConnections from '@/components/social/SocialConnections';
import type { Flash, SocialConnection } from '@/components/social/social';

export default function Page({ connections, flash }: { connections: SocialConnection[]; flash: Flash | null }) {
    return <SocialConnections connections={connections} flash={flash} />;
}
```

```tsx
// resources/js/pages/Posts/Show.tsx
import ShareButton from '@/components/social/ShareButton';
import type { SocialShare } from '@/components/social/social';

export default function Show({ post, share, shareOnTikTok }: { post: { title: string }; share: SocialShare; shareOnTikTok: SocialShare }) {
    return (
        <article>
            <h1>{post.title}</h1>
            <ShareButton share={share} />
            <ShareButton share={shareOnTikTok} />
        </article>
    );
}
```

**3b. Use them in Vue:**

```vue
<!-- resources/js/pages/SocialConnections.vue -->
<script setup lang="ts">
import SocialConnections from '@/components/social/SocialConnections.vue';
import type { Flash, SocialConnection } from '@/components/social/social';

defineProps<{ connections: SocialConnection[]; flash: Flash | null }>();
</script>

<template>
    <SocialConnections :connections="connections" :flash="flash" />
</template>
```

```vue
<!-- resources/js/pages/Posts/Show.vue -->
<script setup lang="ts">
import ShareButton from '@/components/social/ShareButton.vue';
import type { SocialShare } from '@/components/social/social';

defineProps<{ post: { title: string }; share: SocialShare; shareOnTikTok: SocialShare }>();
</script>

<template>
    <article>
        <h1>{{ post.title }}</h1>
        <ShareButton :share="share" />
        <ShareButton :share="shareOnTikTok" />
    </article>
</template>
```

**Without Inertia** (a React or Vue app served by the same Laravel app), render the page component
without props: `<SocialConnections />`. It loads the cards from `GET /social/connections` itself.
The share button still needs its signed URL from the server, so include `SocialProps::share($post)`
in the JSON your page already loads for that model. Each card looks like this:

```json
{
    "network": "instagram",
    "label": "Instagram",
    "brand": { "color": "#E4405F", "logo": "<svg …>", "dark": false },
    "hint": "Posts need an image or a video. Connect an Instagram Business or Creator account.",
    "autopost": true,
    "connection": { "connected": true, "status": "active", "name": "siberfx", "expires_at": "2026-12-11T12:00:00+00:00", "days_left": 59, "…": "…" },
    "badge": { "label": "Connected", "tone": "success" },
    "needs_reconnect": false,
    "confirm_disconnect": "Disconnect Instagram? …",
    "can_manage": true,
    "urls": { "connect": "https://example.com/social/instagram/redirect", "disconnect": "https://example.com/social/instagram/connection" }
}
```

Connecting is a full page load (`urls.connect` is a plain link). Disconnecting and sharing are
JSON requests that send the session cookie and `XSRF-TOKEN`. See [`examples/README.md`](examples/README.md)
for the details.

## Auto-post

When a model is saved and `isLiveForSocial()` turns true, the observer queues one job per network
that is **enabled**, has **autopost** on, is **connected**, and has not already posted the model.
Jobs run after the database commit, are unique per model and network while queued, and retry only
on `429`, `5xx` or no answer. Seeders and Artisan commands do not auto-post unless
`autopost.in_console` is true.

```bash
php artisan queue:work --queue=default
```

## Commands

| Command | Does |
|---|---|
| `social:install [--theme=] [--headless]` | Publishes config and migrations, sets the UI theme, prints the redirect URI of each enabled network. |
| `social:page [--theme=] [--layout=] [--section=] [--livewire] [--view=] [--force]` | Writes a connections page with a card for every enabled network. |
| `social:status` | Every network: enabled, auto-post, connected, status. |
| `social:disconnect {network} [--force]` | Revokes and forgets one connection. Refuses a switched-off network. |
| `social:share {network} {model} {id} [--force]` | Shares one model to one network now. |
| `social:check-token [--days=]` | Fires `TokenExpiringSoon` for enabled networks expiring within `expiry_warning_days`. |
| `social:test-notification [--channel=]` | Sends a test to the enabled notification channels. |

## Notifications

Set `SOCIAL_NOTIFY=true` and one or more channels:

- Telegram: `SOCIAL_NOTIFY_TELEGRAM`, `SOCIAL_TELEGRAM_BOT_TOKEN`, `SOCIAL_TELEGRAM_CHAT_ID`
- Slack: `SOCIAL_NOTIFY_SLACK`, `SOCIAL_SLACK_WEBHOOK_URL`
- Email: `SOCIAL_NOTIFY_MAIL`, `SOCIAL_NOTIFY_MAIL_TO`, `SOCIAL_NOTIFY_MAILER`

Every message names the network, for example "Shared on X: …" or "The Facebook token expires in 5 days".

## Adding a network

1. Write a class implementing `Siberfx\SocialAutopost\Contracts\SocialNetwork`:
   `key()`, `label()`, `isConnected()`, `connection()`, `beginConnection()`, `completeConnection()`,
   `disconnect()` and `publish()`. Keep its tokens in the `ConnectionStore`, and read its settings
   from `config('social-autopost.networks.{key}')`.
2. Add an entry under `networks` with `enabled`, `autopost`, `class` and its own keys.
3. Optionally add `post_url` (with `{id}`) for links in notifications, and `brand` for its colour and logo.

```php
'mastodon' => [
    'enabled' => (bool) env('MASTODON_ENABLED', false),
    'autopost' => (bool) env('MASTODON_AUTOPOST', false),
    'class' => App\Social\MastodonNetwork::class,
    'post_url' => 'https://example.social/@me/{id}',
    'brand' => ['color' => '#6364FF'],
],
```

## Upgrading from the standalone packages

This package replaces `siberfx/linkedin-autopost`, `siberfx/x-autopost` and `siberfx/facebook-autopost`.
The data and settings differ in these ways:

- **Callback URLs change** to `/social/{network}/callback`. Update the redirect URL in each developer
  portal, or keep the old URL by setting `{NET}_REDIRECT_URI`.
- **Connections are not carried over.** Each network stores its own connection in `social_connections`,
  so reconnect each one after upgrading.
- **Shared history** moves to `social_posts`. The old `linkedin_posts`, `x_posts` and `facebook_posts`
  tables are not read; keep them until you no longer need the history.
- **Environment names:** `FACEBOOK_AUTO_POST` is now `FACEBOOK_AUTOPOST`. `LINKEDIN_AUTOPOST` and
  `X_AUTOPOST` are unchanged, and each network now also has `{NET}_ENABLED`, which defaults to off:
  set it to `true` for every network you use.
- **Gate:** one gate, `manage-social-autopost`, replaces the three per-network gates.

## Headless

Set `SOCIAL_UI=false` to ship without views or components. Routes then answer JSON only, and you
use the facade, events and commands yourself.

## Events

Each event carries the network key: `Connected`, `Disconnected`, `Shared`, `ShareFailed` and
`TokenExpiringSoon`.

## Configuration reference

| Key | Purpose |
|---|---|
| `networks.{key}.enabled`, `.autopost`, `.class` | The switches and the driver. |
| `networks.{key}.*` | The network's own keys (credentials, API version, page id…). |
| `networks.{key}.post_url`, `.brand` | Notification links and the UI colour and logo. |
| `networks.{key}.hint`, `.disconnect_note` | The card note, and what disconnecting means when the network cannot revoke (`''` hides). |
| `connection_store` | Where connections are kept (encrypted, `DatabaseConnectionStore` by default). |
| `routes` | `enabled`, `prefix` (`social`), `name`, `middleware`, `after_connect`. |
| `autopost` | `in_console`, `queue_connection`, `queue`. |
| `ui` | `enabled` (headless when false), `theme`. |
| `notifications` | `enabled`, `events`, `queued`, `tries`, `backoff`, `channels`. |
| `job` | `tries` and `backoff` of the auto-post job. |
| `expiry_warning_days`, `status_cache_ttl`, `http.timeout` | Tuning. |

## Tests

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

## License

MIT. See [LICENSE](LICENSE).
