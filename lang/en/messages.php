<?php

declare(strict_types=1);

/*
| Every text the package shows people: connection cards, share buttons,
| flash messages and notifications. Publish and translate:
|
|   php artisan vendor:publish --tag=social-autopost-lang
|
| Console output stays English, like artisan itself.
*/
return [

    // After the OAuth callback (?social=…&state=…).
    'connected' => ':label connected.',
    'connection_cancelled' => ':label connection cancelled.',
    'connection_refused' => ':label refused the connection: :reason',
    'unknown_error' => 'unknown error',
    'exchange_failed' => ':label refused the token exchange. Check that the redirect URL is registered on the app exactly.',
    'login_expired' => 'The :label login expired. Connect again.',
    'missing_code' => ':label did not return an authorization code.',
    'not_configured' => 'Set the :label client id and secret first.',

    // The badge on the connection card.
    'badge_connected' => 'Connected',
    'badge_expired' => 'Expired',
    'badge_revoked' => 'Revoked on :label',
    'badge_unknown' => 'Status unknown',
    'badge_expires_in' => 'Expires in :days days',

    // The connection card.
    'account_fallback' => ':label account',
    'card_not_connected' => 'Not connected',
    'card_connect_intro' => 'Connect a :label account to share your content.',
    'card_connect' => 'Connect :label',
    'card_connected_on' => 'Connected :date',
    'card_valid_until' => 'Valid until :date',
    'card_reconnect' => 'Reconnect',
    'card_disconnect' => 'Disconnect',
    'card_post_to' => 'Post to',
    'card_save_pages' => 'Save pages',
    'card_status_unknown' => ':label could not be asked about this connection just now; it may still work.',
    'page_type_member' => 'Profile',
    'page_type_organization' => 'Organization page',
    'page_type_page' => 'Page',

    // The share button.
    'share' => 'Share',
    'share_again' => 'Share again',
    'share_on' => 'Share on :label',
    'share_again_on' => 'Share again on :label',
    'shared_on_date' => 'Shared on :date. Share again.',
    'confirm_share' => 'Post this now?',
    'confirm_share_again' => 'This was already posted on :date. Post it again?',
    'not_allowed' => 'You are not allowed to manage social networks.',
    'network_off' => 'That network is switched off.',
    'connect_first' => 'Connect :label first.',
    'connect_any_first' => 'Connect a network first.',
    'only_live' => 'Only live items can be shared.',

    // Disconnecting, and what people should know per network.
    'confirm_disconnect' => 'Disconnect :label? The connection is revoked and nothing posts there until you connect again.',
    'confirm_disconnect_noted' => 'Disconnect :label? It is forgotten here and nothing posts there until you connect again. :note',
    'disconnected' => ':label disconnected.',
    'disconnected_noted' => ':label disconnected here. :note',
    'disconnected_unconfirmed' => ':label disconnected here, but :label did not confirm revoking the connection.',
    'instagram_hint' => 'Posts need an image or a video. Connect an Instagram Business or Creator account.',
    'instagram_disconnect_note' => 'To remove its access completely, remove the app under Instagram → Settings → Apps and websites.',
    'tiktok_hint_private' => 'Posts need a video or an image, and are posted as private (only you can see them).',
    'tiktok_hint_audit' => 'Posts need a video or an image. Until TikTok audits your app, it only accepts private posts: set TIKTOK_PRIVACY_LEVEL=SELF_ONLY meanwhile.',

    // Sharing and choosing pages over HTTP.
    'pages_saved' => ':label will post to the chosen pages.',
    'no_targets' => 'No network is connected to share to.',
    'share_failed_everywhere' => 'The share failed on every network.',
    'shared_on' => 'Shared on :networks.',
    'shared_on_partial' => 'Shared on :networks; the others failed.',

    // Telegram, Slack and email notifications.
    'notify_shared' => 'Shared on :label: :title',
    'notify_post' => 'Post: :url',
    'notify_trigger' => 'Trigger: :trigger',
    'notify_failed' => ':label share failed',
    'notify_model' => 'Model: :type #:id',
    'notify_error' => 'Error: :error',
    'notify_expires' => 'The :label token expires in :days days',
    'notify_expired' => 'The :label token has expired',
    'notify_account' => 'Account: :name',
    'notify_reconnect' => 'Connect again to keep posting.',
    'notify_test' => 'Social Autopost test notification',
    'notify_test_line' => 'Notifications from :app reach this channel.',

];
