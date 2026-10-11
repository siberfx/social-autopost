<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * One social network: how to connect an account and how to publish to it.
 *
 * A driver keeps its own connection data (tokens, page ids…) through the
 * ConnectionStore, reading its settings from config('social-autopost.networks.{key}').
 * It is the only class that talks to its network's API.
 */
interface SocialNetwork
{
    /** The config key, e.g. "linkedin". */
    public function key(): string;

    /** The name people see, e.g. "LinkedIn". */
    public function label(): string;

    /** Whether a connection is stored. Never calls the network. */
    public function isConnected(): bool;

    /** The stored connection as the network sees it. May call the network; drivers cache the answer. */
    public function connection(): Connection;

    /**
     * Start the OAuth flow. Stores the state (and any PKCE verifier) in the
     * session under social-autopost.oauth.{key}, and returns the URL to send the browser to.
     *
     * @throws ConnectionFailed When the network is not configured.
     */
    public function beginConnection(): string;

    /**
     * Finish the OAuth flow: check the state, exchange the code, store the connection.
     *
     * @throws ConnectionFailed with a user-safe reason in its message and a $state code the callback shows.
     */
    public function completeConnection(Request $request): Connection;

    /** Revoke the connection at the network (best effort) and forget what is stored. Returns whether the network confirmed the revocation. */
    public function disconnect(): bool;

    /**
     * Publish the post. Returns the network's post id, or null when the network
     * accepted the post without one. Drivers format the LinkPost for their own text limits.
     *
     * @throws RequestFailed
     */
    public function publish(Model&ShareableOnSocial $model, LinkPost $post): ?string;
}
