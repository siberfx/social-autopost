# Changelog

All notable changes to `siberfx/social-autopost` are documented here. This project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
