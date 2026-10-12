# Changelog

All notable changes to `siberfx/social-autopost` are documented here. This project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.2] - 2026-10-12

### Changed
- README: the "React and Vue" section now walks through copying the components and building `SocialProps` on the server, with React and Vue usage for a connections page and share buttons, the non-Inertia variant, and the JSON each card returns.

## [1.1.1] - 2026-10-12

### Added
- `php artisan social:page` writes a ready-made "Social connections" page, with a card for every enabled network and the callback URLs to register. It can be standalone or use your layout (`--layout`), and can use Blade or Livewire cards (`--livewire`).
- React and Vue support: `GET /social/connections` returns every enabled network's card as JSON, and `SocialProps::connections()`, `::share($model, $network)` and `::flash()` build the same data as Inertia props. Ready-to-copy TypeScript components (`SocialConnections`, `ShareButton`) for React and Vue are in `examples/`.
- Connection cards show a short setup note where a network needs one: Instagram and TikTok posts need media, and TikTok only allows private posts until it audits your app. Override with `networks.{key}.hint`, or set `''` to hide it.

### Fixed
- Disconnecting Instagram no longer promises a revocation Instagram cannot make. The question and the message now say to remove the app under Instagram → Settings → Apps and websites (`networks.{key}.disconnect_note` for other networks).
- Black logos (X, TikTok) are inverted in dark mode on the Tailwind card, so they no longer disappear.
- The Bootstrap card's logo keeps its size outside a flex container.

## [1.1.0] - 2026-10-12

### Added
- Instagram driver (Instagram API with Instagram Login): posts an image, or a video as a Reel, to a Business or Creator account through a media container, waiting for Instagram to process it. The 60-day token refreshes itself once fewer than 30 days remain. `INSTAGRAM_ENABLED`, `INSTAGRAM_AUTOPOST`, `INSTAGRAM_CLIENT_ID`, `INSTAGRAM_CLIENT_SECRET`.
- TikTok driver (Login Kit and Content Posting API, Direct Post): posts a video, or an image as a photo post, pulled from its URL. Follows the creator's privacy options and comment, duet and stitch settings; the day-long access token refreshes itself. `TIKTOK_ENABLED`, `TIKTOK_AUTOPOST`, `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`, `TIKTOK_PRIVACY_LEVEL`.
- `LinkPost::image()` and `LinkPost::video()`: the media Instagram and TikTok post. LinkedIn, X and Facebook ignore them. A post without media fails on Instagram and TikTok with a clear, non-retryable error.

### Fixed
- The published `ShareableModel` stub had broken class names (`Siberfx\social networkAutopost\…`) and would not compile.

## [1.0.0] - 2026-10-11

### Added
- One package for LinkedIn, X and Facebook, with a shared core: `SocialNetwork` contract, `ShareableOnSocial` contract, `PostsToSocial` trait, `LinkPost`, `SharedPost` history and encrypted `ConnectionStore`.
- Per-network switches: `enabled` (off hides the network everywhere) and `autopost` (queue when a model goes live).
- The `Social` facade: `enabled()`, `isEnabled()`, `isConnected()`, `connection()`, `connect()`, `disconnect()`, `share()`, `shareTo()`, `queue()`, `wasPosted()`, `targets()`.
- Failure isolation: sharing to all networks records each failure and keeps going.
- Routes under `social/{network}/…`, answering 404 for a switched-off or unknown network; the gate guards every route.
- Events `Connected`, `Disconnected`, `Shared`, `ShareFailed`, `TokenExpiringSoon`, each carrying the network key.
- Commands `social:install`, `social:status`, `social:disconnect`, `social:share`, `social:check-token`, `social:test-notification`.
- Telegram, Slack and email notifications; every message names the network.
- Blade and Livewire connection cards and share buttons (Tailwind and Bootstrap 5), with the network's colour and logo; headless mode with `SOCIAL_UI=false`.

- LinkedIn driver (OpenID Connect sign-in, Posts API link posts with LittleText escaping), ported from `siberfx/linkedin-autopost`.
- X driver (OAuth 2.0 with PKCE, refresh token rotation, 280-character composition with URLs counted as 23), ported from `siberfx/x-autopost`.
- Facebook driver (Facebook Login, Page selection, Page feed posts, `debug_token` status), ported from `siberfx/facebook-autopost`.
- Add a network by implementing `SocialNetwork` and adding one config entry.

### Notes
- Replaces `siberfx/linkedin-autopost`, `siberfx/x-autopost` and `siberfx/facebook-autopost`. Callback URLs move to `/social/{network}/callback`, connections are not carried over, and `FACEBOOK_AUTO_POST` is renamed `FACEBOOK_AUTOPOST`. See the README's upgrade section.
