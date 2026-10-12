# React and Vue examples

Connection cards and a share button for pages built in React or Vue, with or without Inertia.
They use the same routes, gate and messages as the Blade cards. Copy the folder you need into
your app, for example to `resources/js/components/social/`, and restyle it as you like. They use
Tailwind classes, the same as Laravel's React and Vue starter kits.

| File | What it is |
|---|---|
| `react/SocialConnections.tsx`, `vue/SocialConnections.vue` | A page with one card per enabled network: connect, reconnect, disconnect, status and expiry. |
| `react/ShareButton.tsx`, `vue/ShareButton.vue` | A button that shares one model now. |
| `react/social.ts`, `vue/social.ts` | The types and the `fetch` helper both components use. |

## With Inertia

Build the props on the server with `SocialProps`. It never includes tokens, and the share URL
is signed for that model.

```php
use Inertia\Inertia;
use Siberfx\SocialAutopost\Support\SocialProps;

Route::get('/admin/social', fn () => Inertia::render('SocialConnections', [
    'connections' => SocialProps::connections(),
    'flash' => SocialProps::flash(),   // the message after connecting
]))->middleware(['auth', 'can:manage-social-autopost']);

Route::get('/admin/posts/{post}', fn (Post $post) => Inertia::render('Posts/Show', [
    'post' => $post,
    'share' => SocialProps::share($post),            // every enabled, connected network
    'shareOnTikTok' => SocialProps::share($post, 'tiktok'),
]));
```

```tsx
// React: resources/js/pages/SocialConnections.tsx
import SocialConnections from '@/components/social/SocialConnections';
export default SocialConnections;

// in Posts/Show.tsx
<ShareButton share={share} />
```

```vue
<!-- Vue: resources/js/pages/SocialConnections.vue -->
<script setup lang="ts">
import SocialConnections from '@/components/social/SocialConnections.vue';
defineProps<{ connections: any[]; flash: any }>();
</script>
<template><SocialConnections :connections="connections" :flash="flash" /></template>

<!-- in Posts/Show.vue -->
<ShareButton :share="share" />
```

## Without Inertia (a plain SPA on the same domain)

Render `<SocialConnections />` without props. It loads the cards from the package's JSON route:

```
GET /social/connections   →   { "data": [ …one card per enabled network… ], "flash": null }
```

The share button still needs its signed URL from the server. Return `SocialProps::share($post)`
in the JSON your page already loads for that model.

The requests send the session cookie and Laravel's `XSRF-TOKEN`, so the page must be served by
the same Laravel app, or by a front end set up for Sanctum's SPA authentication.

## Things to know

- **Connecting is a full page load.** `urls.connect` leaves for the network's login page, so it
  is a plain link, not a `fetch`. Afterwards the network sends the browser to
  `routes.after_connect` with `?social=…&state=…`. Point that setting at your page, for example
  `'/admin/social'`. The page then shows the message and removes the query from the address bar.
- **Disconnecting** sends `DELETE urls.disconnect` with `Accept: application/json`. The answer's
  `message` explains what happened, including Instagram's "remove the app in Instagram's
  settings" step.
- **Who may act:** each card's `can_manage` and the share button's `disabled_reason` follow the
  `manage-social-autopost` gate, and the routes check that gate again.
- **Logos** are SVG markup from the package or your own `networks.{key}.brand.logo` config, which
  is why the components render them as HTML. Never put user input there.
- **Headless mode** (`SOCIAL_UI=false`) works too: the JSON route and `SocialProps` do not need
  the Blade views.
