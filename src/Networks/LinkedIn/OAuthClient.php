<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Exceptions\NotConfigured;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Throwable;

/** Everything that talks to LinkedIn's OAuth, identity and organization endpoints. */
final class OAuthClient
{
    private const AUTHORIZE = 'https://www.linkedin.com/oauth/v2/authorization';

    private const TOKEN = 'https://www.linkedin.com/oauth/v2/accessToken';

    private const INTROSPECT = 'https://www.linkedin.com/oauth/v2/introspectToken';

    private const REVOKE = 'https://www.linkedin.com/oauth/v2/revoke';

    private const USERINFO = 'https://api.linkedin.com/v2/userinfo';

    private const ORGANIZATION_ACLS = 'https://api.linkedin.com/rest/organizationAcls';

    private const ORGANIZATIONS = 'https://api.linkedin.com/rest/organizations/';

    private const ORGANIZATION_PREFIX = 'urn:li:organization:';

    /** Roles that may post as the organization. */
    private const POSTING_ROLES = ['ADMINISTRATOR', 'CONTENT_ADMINISTRATOR'];

    /** Sent on both the authorization request and the token exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = config('social-autopost.networks.linkedin.redirect_uri');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route(config('social-autopost.routes.name', 'social-autopost.').'callback', ['network' => 'linkedin']);
    }

    public function authorizationUrl(string $state): string
    {
        [$clientId] = $this->credentials();

        return self::AUTHORIZE.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', $this->requestedScopes()),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return list<string> The configured scopes, plus the organization scopes when organization pages are on. */
    public function requestedScopes(): array
    {
        $scopes = array_map('strval', (array) config('social-autopost.networks.linkedin.scopes', []));

        if (self::organizationsEnabled()) {
            $extra = (array) config('social-autopost.networks.linkedin.organization_scopes', ['r_organization_admin', 'w_organization_social']);
            $scopes = [...$scopes, ...array_map('strval', $extra)];
        }

        return array_values(array_unique($scopes));
    }

    /** Whether organization pages are asked for (networks.linkedin.organizations). */
    public static function organizationsEnabled(): bool
    {
        return (bool) config('social-autopost.networks.linkedin.organizations', false);
    }

    /**
     * Exchange the code and read the member's profile, and their organization pages when switched on.
     *
     * @return array<string, mixed> The stored connection: access_token, author_urn, name, email, picture, scopes, expires_at, connected_at, pages, selected_pages.
     *
     * @throws RequestFailed|NotConfigured
     */
    public function exchange(string $code): array
    {
        [$clientId, $clientSecret] = $this->credentials();

        $token = $this->http()->asForm()->post(self::TOKEN, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($token->failed()) {
            throw Failure::fromResponse($token);
        }

        $accessToken = $token->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RequestFailed('LinkedIn returned no access token.', $token->status());
        }

        $profile = $this->http()->withToken($accessToken)->acceptJson()->get(self::USERINFO);

        if ($profile->failed()) {
            throw Failure::fromResponse($profile);
        }

        $sub = $profile->json('sub');

        if (! is_string($sub) || $sub === '') {
            throw new RequestFailed('LinkedIn returned no member id (sub); is "Sign In with LinkedIn using OpenID Connect" added to the app?');
        }

        $now = CarbonImmutable::now();
        $expiresIn = $token->json('expires_in');
        $member = new Page('urn:li:person:'.$sub, Page::TYPE_MEMBER, self::stringOrNull($profile->json('name')), self::stringOrNull($profile->json('picture')));
        $organizations = self::organizationsEnabled() ? $this->organizations($accessToken) : [];

        return [
            'access_token' => $accessToken,
            'author_urn' => $member->id,
            'name' => $member->name,
            'email' => self::stringOrNull($profile->json('email')),
            'picture' => $member->picture,
            'scopes' => self::scopes($token->json('scope')),
            'expires_at' => is_numeric($expiresIn) ? $now->addSeconds((int) $expiresIn)->toIso8601String() : null,
            'connected_at' => $now->toIso8601String(),
            'pages' => array_map(fn (Page $page) => $page->toArray(), [$member, ...$organizations]),
            // Until people choose, shares go to the member's own profile, as before pages existed.
            'selected_pages' => [$member->id],
        ];
    }

    /**
     * The organization pages the member may post to: those where they are an
     * administrator or content administrator. Needs r_organization_admin
     * (Community Management API). Never throws: a failure logs a warning and
     * returns [], so connecting never fails because of pages.
     *
     * @return list<Page>
     */
    public function organizations(string $token): array
    {
        try {
            $response = $this->rest($token)->get(self::ORGANIZATION_ACLS, [
                'q' => 'roleAssignee',
                'state' => 'APPROVED',
                'count' => 100,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not list the LinkedIn organization pages.', ['error' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Could not list the LinkedIn organization pages.', ['status' => $response->status(), 'error' => $response->body()]);

            return [];
        }

        $elements = $response->json('elements');
        $urns = [];

        foreach (is_array($elements) ? $elements : [] as $element) {
            if (! is_array($element) || ! in_array($element['role'] ?? null, self::POSTING_ROLES, true)) {
                continue;
            }

            $urn = self::stringOrNull($element['organization'] ?? null) ?? self::stringOrNull($element['organizationalTarget'] ?? null);

            if ($urn !== null && str_starts_with($urn, self::ORGANIZATION_PREFIX)) {
                $urns[$urn] = true;
            }
        }

        return array_map(
            fn (string $urn) => new Page($urn, Page::TYPE_ORGANIZATION, $this->organizationName($token, $urn)),
            array_keys($urns),
        );
    }

    /** @return array<string, mixed>|null Null when LinkedIn cannot answer. */
    public function introspect(string $token): ?array
    {
        [$clientId, $clientSecret] = $this->credentials();

        return $this->jsonOrNull(fn () => $this->http()->asForm()->post(self::INTROSPECT, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'token' => $token,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function userinfo(string $token): ?array
    {
        return $this->jsonOrNull(fn () => $this->http()->withToken($token)->acceptJson()->get(self::USERINFO));
    }

    /** Best effort: false when LinkedIn refuses or cannot be reached. */
    public function revoke(string $token): bool
    {
        [$clientId, $clientSecret] = $this->credentials();

        try {
            return $this->http()->asForm()->post(self::REVOKE, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'token' => $token,
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public static function scopes(mixed $scope): array
    {
        if (! is_string($scope) || $scope === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: []));
    }

    /** The organization's name, or null when LinkedIn cannot answer. */
    private function organizationName(string $token, string $urn): ?string
    {
        $id = substr($urn, strlen(self::ORGANIZATION_PREFIX));
        $json = $this->jsonOrNull(fn () => $this->rest($token)->get(self::ORGANIZATIONS.rawurlencode($id)));

        return self::stringOrNull($json['localizedName'] ?? null);
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $id = config('social-autopost.networks.linkedin.client_id');
        $secret = config('social-autopost.networks.linkedin.client_secret');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            throw new NotConfigured;
        }

        return [$id, $secret];
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social-autopost.http.timeout', 20));
    }

    /** A request to LinkedIn's versioned REST API. */
    private function rest(string $token): PendingRequest
    {
        return $this->http()->withToken($token)->acceptJson()->withHeaders([
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version' => (string) config('social-autopost.networks.linkedin.api_version', '202609'),
        ]);
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

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
