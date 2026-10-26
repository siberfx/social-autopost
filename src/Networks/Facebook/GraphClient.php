<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\Facebook;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;
use Throwable;

/**
 * Everything that talks to Facebook: the Login dialog, the token exchange,
 * the Page lookup, debug_token, posting to a Page feed and revoking the app.
 * FacebookNetwork is the only caller.
 */
final class GraphClient
{
    /** Facebook Login scopes needed to list a Page and post to it. */
    public const LOGIN_SCOPES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement'];

    /** Graph API error code for an invalid, expired or revoked access token. */
    private const INVALID_TOKEN = 190;

    /** Sent on both the dialog request and the code exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = $this->setting('redirect_uri');

        return $configured ?? route(config('social-autopost.routes.name', 'social-autopost.').'callback', [
            'network' => FacebookNetwork::KEY,
        ]);
    }

    /** @throws NotConfigured */
    public function authorizationUrl(string $state): string
    {
        [$appId] = $this->credentials();

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', self::LOGIN_SCOPES),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the login code for a long-lived user token, then keep every Page
     * the account manages with its Page token. The configured page_id (when set,
     * and it must be managed) or else the first Page is selected. Page tokens
     * derived from a long-lived user token do not expire.
     *
     * @throws RequestFailed
     * @throws NotConfigured
     */
    public function exchange(string $code): FacebookConnection
    {
        [$appId, $appSecret] = $this->credentials();

        $short = $this->tokenFrom($this->send(fn () => $this->graph()->get('oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ])), 'short-lived user token');

        $long = $this->tokenFrom($this->send(fn () => $this->graph()->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $short,
        ])), 'long-lived user token');

        $listed = $this->send(fn () => $this->graph()->withToken($long)->get('me/accounts', [
            'fields' => 'id,name,access_token,picture{url}',
            'limit' => 100,
        ]))->json('data');

        $pages = [];

        foreach (is_array($listed) ? $listed : [] as $candidate) {
            $page = is_array($candidate) ? FacebookConnection::pageFrom($candidate) : null;

            if ($page !== null) {
                $pages[] = $page;
            }
        }

        $configured = $this->setting('page_id');

        if ($pages === [] || ($configured !== null && ! in_array($configured, array_column($pages, 'id'), true))) {
            throw new RequestFailed($configured !== null
                ? "This Facebook account does not manage the Page {$configured}."
                : 'This Facebook account does not manage a Page.');
        }

        return new FacebookConnection(
            pages: $pages,
            // Until people choose: the configured Page, otherwise the first one.
            selectedPages: [$configured ?? $pages[0]['id']],
            userAccessToken: $long,
            connectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Facebook's view of a token: validity, expiry (0 = never) and scopes.
     *
     * @return array<string, mixed>|null Null when Facebook cannot be asked.
     */
    public function debugToken(string $token): ?array
    {
        $data = $this->jsonOrNull(fn () => $this->graph()->get('debug_token', [
            'input_token' => $token,
            'access_token' => $this->appToken(),
        ]));

        $inner = $data['data'] ?? null;

        return is_array($inner) ? $inner : null;
    }

    /** @return array<string, mixed>|null The Page's name and picture, or null when Facebook cannot answer. */
    public function pageProfile(string $pageToken, string $pageId): ?array
    {
        return $this->jsonOrNull(fn () => $this->graph()->withToken($pageToken)->get($pageId, [
            'fields' => 'name,picture.width(128).height(128)',
        ]));
    }

    /**
     * Post the commentary and the link to the Page feed. The link preview comes
     * from the page's own Open Graph tags, so the LinkPost's description is not sent.
     *
     * @return string|null The post id; null when Facebook accepted the post without returning its id.
     *
     * @throws RequestFailed
     */
    public function postLink(string $pageToken, string $pageId, LinkPost $post): ?string
    {
        // The link goes in its own field, so it is removed from the text: the default
        // commentary ends with {url}, and the URL would otherwise show twice.
        $message = trim(str_replace($post->url(), '', $post->commentaryText()));

        $response = $this->send(fn () => $this->graph()->withToken($pageToken)->post("{$pageId}/feed", [
            'message' => $message !== '' ? $message : $post->title(),
            'link' => $post->url(),
        ]));

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        $id = $response->json('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Post the photo to the Page with the commentary as its message. Facebook
     * downloads the image from its URL itself. A photo post has no link field,
     * so the link rides in the text.
     *
     * @return string|null The feed post id (preferred for links), or the photo id.
     *
     * @throws RequestFailed
     */
    public function postPhoto(string $pageToken, string $pageId, LinkPost $post): ?string
    {
        $response = $this->send(fn () => $this->graph()->withToken($pageToken)->post("{$pageId}/photos", [
            'url' => $post->imageUrl(),
            'message' => $post->commentaryText(),
        ]));

        $id = $response->json('post_id') ?? $response->json('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Post the video to the Page with the commentary as its description.
     * Facebook downloads the video from its URL itself and processes it in the
     * background, so the post appears once that is done.
     *
     * @return string|null The video id.
     *
     * @throws RequestFailed
     */
    public function postVideo(string $pageToken, string $pageId, LinkPost $post): ?string
    {
        $response = $this->send(fn () => $this->graph()->withToken($pageToken)->post("{$pageId}/videos", [
            'file_url' => $post->videoUrl(),
            'description' => $post->commentaryText(),
        ]));

        $id = $response->json('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Remove the app's permissions for the user, which revokes the Page token
     * too. Best effort: false when Facebook refuses or cannot be reached.
     */
    public function revoke(string $userToken): bool
    {
        try {
            return $this->graph()->withToken($userToken)->delete('me/permissions')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws RequestFailed
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new RequestFailed('Could not reach Facebook: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return $response;
    }

    /**
     * Facebook's error message, with an invalid token reported as 401 so the
     * driver marks the connection expired.
     */
    private function failure(Response $response): RequestFailed
    {
        $error = $response->json('error');
        $reason = is_array($error) ? ($error['message'] ?? null) : $error;
        $code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : null;

        $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();
        $status = $code === self::INVALID_TOKEN ? 401 : $response->status();

        return new RequestFailed('Facebook rejected the request: '.$reason, $status);
    }

    /** @throws RequestFailed */
    private function tokenFrom(Response $response, string $what): string
    {
        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RequestFailed("Facebook returned no {$what}.", $response->status());
        }

        return $token;
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $id = $this->setting('app_id');
        $secret = $this->setting('app_secret');

        if ($id === null || $secret === null) {
            throw new NotConfigured;
        }

        return [$id, $secret];
    }

    private function appToken(): string
    {
        [$id, $secret] = $this->credentials();

        return $id.'|'.$secret;
    }

    private function setting(string $key): ?string
    {
        $value = config('social-autopost.networks.'.FacebookNetwork::KEY.'.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function graph(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.$this->version())
            ->timeout((int) config('social-autopost.http.timeout', 20))
            ->acceptJson();
    }

    private function version(): string
    {
        return $this->setting('api_version') ?? 'v25.0';
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>|null
     */
    private function jsonOrNull(callable $request): ?array
    {
        try {
            $response = $request();
        } catch (Throwable) {
            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json) ? $json : null;
    }
}
