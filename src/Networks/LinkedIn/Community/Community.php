<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Networks\LinkedIn\Community;

use Carbon\CarbonInterface;
use Closure;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Models\SharedPost;

/**
 * LinkedIn's Community Management API for the connected LinkedIn account:
 * read and delete posts, moderate comments, react, and read engagement and
 * organization page statistics. Get it from Social::linkedin()->community().
 *
 * A post is a post URN (urn:li:share:…, urn:li:ugcPost:…) or a LinkedIn
 * SharedPost from the share history. The actor (who comments or reacts) must
 * be one of the connection's pages; it defaults to the page the share went to
 * (its target), else networks.linkedin.community_page, else the first
 * selected page.
 *
 * The access token stays inside this object; it is never exposed.
 */
final class Community
{
    private const NETWORK = 'linkedin';

    /**
     * @param  list<Page>  $pages  The connection's pages.
     * @param  non-empty-list<string>  $selectedPages  The ids shares go to.
     * @param  Closure(): void  $onUnauthorized  Marks the connection expired.
     */
    public function __construct(
        private readonly CommunityClient $client,
        private readonly string $token,
        private readonly array $pages,
        private readonly array $selectedPages,
        private readonly Closure $onUnauthorized,
    ) {}

    /**
     * The organization page used when a call names none: networks.linkedin.community_page,
     * else the first selected organization page, else the first one. Null when
     * the connection has no organization page.
     *
     * @throws InvalidArgumentException When community_page is not one of the connection's pages.
     */
    public function defaultPage(): ?Page
    {
        $configured = self::configuredPage();

        if ($configured !== null) {
            return $this->page($configured);
        }

        foreach ($this->selectedPages as $id) {
            $page = $this->findPage($id);

            if ($page !== null && $page->type === Page::TYPE_ORGANIZATION) {
                return $page;
            }
        }

        foreach ($this->pages as $page) {
            if ($page->type === Page::TYPE_ORGANIZATION) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Recent posts of a page, as LinkedIn returns them (id, author, commentary,
     * content, lifecycleState, publishedAt, …). Without a page: defaultPage(),
     * else the first selected page.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestFailed
     * @throws InvalidArgumentException When the page is not one of the connection's pages.
     */
    public function posts(?string $page = null, int $count = 10, int $start = 0): array
    {
        $author = $page === null ? ($this->defaultPage()->id ?? $this->defaultActor()) : $this->page($page)->id;

        return $this->call(fn () => $this->client->posts($this->token, $author, self::count($count), max(0, $start)));
    }

    /**
     * @return array<string, mixed>|null Null when LinkedIn no longer has the post.
     *
     * @throws RequestFailed
     */
    public function post(string|SharedPost $post): ?array
    {
        $urn = $this->urn($post);

        return $this->call(fn () => $this->client->post($this->token, $urn));
    }

    /**
     * Delete the post from LinkedIn. Its LinkedIn share records are marked
     * "deleted", so they no longer count as posted. A post that is already
     * gone is fine.
     *
     * @throws RequestFailed
     */
    public function deletePost(string|SharedPost $post): void
    {
        $urn = $this->urn($post);

        $this->call(fn () => $this->client->deletePost($this->token, $urn));

        SharedPost::query()
            ->forNetwork(self::NETWORK)
            ->where('post_id', $urn)
            ->update(['status' => SharedPost::STATUS_DELETED]);

        if ($post instanceof SharedPost && $post->exists) {
            $post->status = SharedPost::STATUS_DELETED;
            $post->syncOriginalAttribute('status');
        }
    }

    /**
     * @return list<Comment>
     *
     * @throws RequestFailed
     */
    public function comments(string|SharedPost $post, int $count = 10, int $start = 0): array
    {
        $urn = $this->urn($post);
        $comments = $this->call(fn () => $this->client->comments($this->token, $urn, self::count($count), max(0, $start)));

        return array_map(fn (array $comment) => Comment::fromApi($comment), $comments);
    }

    /**
     * Comment on a post as the actor, or reply to a comment with $replyTo.
     *
     * @param  Comment|string|null  $replyTo  A Comment, or its URN.
     *
     * @throws RequestFailed
     * @throws InvalidArgumentException When the text is empty or the actor is not one of the connection's pages.
     */
    public function comment(string|SharedPost $post, string $text, ?string $actor = null, Comment|string|null $replyTo = null): Comment
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('A LinkedIn comment needs some text.');
        }

        $urn = $this->urn($post);
        $actor = $this->actor($post, $actor);
        $parent = $replyTo instanceof Comment ? ($replyTo->urn ?? throw new InvalidArgumentException('This comment has no URN to reply to.')) : $replyTo;

        return Comment::fromApi($this->call(fn () => $this->client->comment($this->token, $actor, $urn, $text, $parent)));
    }

    /**
     * Delete a comment made by the actor or, as a page admin, by anyone on the page's post.
     *
     * @param  Comment|string  $comment  A Comment, or its id.
     *
     * @throws RequestFailed
     * @throws InvalidArgumentException When the comment id is missing or the actor is not one of the connection's pages.
     */
    public function deleteComment(string|SharedPost $post, Comment|string $comment, ?string $actor = null): void
    {
        $id = $comment instanceof Comment ? $comment->id : $comment;

        if ($id === '') {
            throw new InvalidArgumentException('The comment id is missing.');
        }

        $urn = $this->urn($post);
        $actor = $this->actor($post, $actor);

        $this->call(fn () => $this->client->deleteComment($this->token, $actor, $urn, $id));
    }

    /**
     * @return list<Reaction>
     *
     * @throws RequestFailed
     */
    public function reactions(string|SharedPost $post, int $count = 10, int $start = 0): array
    {
        $urn = $this->urn($post);
        $reactions = $this->call(fn () => $this->client->reactions($this->token, $urn, self::count($count), max(0, $start)));

        return array_map(fn (array $reaction) => Reaction::fromApi($reaction), $reactions);
    }

    /**
     * React to a post or a comment (pass the comment's URN as the post).
     *
     * @throws RequestFailed
     * @throws InvalidArgumentException When the type is not one of Reaction::TYPES.
     */
    public function react(string|SharedPost $post, string $type = Reaction::LIKE, ?string $actor = null): void
    {
        $type = strtoupper($type);

        if (! in_array($type, Reaction::TYPES, true)) {
            throw new InvalidArgumentException("[{$type}] is not a LinkedIn reaction; use one of ".implode(', ', Reaction::TYPES).'.');
        }

        $urn = $this->urn($post);
        $actor = $this->actor($post, $actor);

        $this->call(fn () => $this->client->react($this->token, $actor, $urn, $type));
    }

    /** @throws RequestFailed */
    public function unreact(string|SharedPost $post, ?string $actor = null): void
    {
        $urn = $this->urn($post);
        $actor = $this->actor($post, $actor);

        $this->call(fn () => $this->client->unreact($this->token, $actor, $urn));
    }

    /**
     * Reaction and comment counts of a post.
     *
     * @throws RequestFailed
     */
    public function summary(string|SharedPost $post): SocialSummary
    {
        $urn = $this->urn($post);

        return SocialSummary::fromApi($this->call(fn () => $this->client->socialMetadata($this->token, $urn)));
    }

    /**
     * Followers of an organization page; defaultPage() when none is given.
     *
     * @throws RequestFailed
     * @throws InvalidArgumentException When it is not one of the connection's organization pages.
     */
    public function followers(?string $organization = null): int
    {
        $urn = $this->organization($organization);

        return $this->call(fn () => $this->client->followerCount($this->token, $urn));
    }

    /**
     * Followers and share statistics of an organization page: lifetime totals,
     * or the sum of each day from $from to $to (default now). defaultPage()
     * when no page is given. Needs rw_organization_admin.
     *
     * @throws RequestFailed
     * @throws InvalidArgumentException When it is not one of the connection's organization pages.
     */
    public function statistics(?string $organization = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): PageStatistics
    {
        $urn = $this->organization($organization);

        return $this->call(fn () => PageStatistics::fromApi(
            $urn,
            $this->client->followerCount($this->token, $urn),
            $this->client->shareStatistics($this->token, $urn, $from, $to),
        ));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws RequestFailed
     */
    private function call(Closure $call): mixed
    {
        try {
            return $call();
        } catch (RequestFailed $e) {
            // LinkedIn refused the token: show it as expired until the admin connects again.
            if ($e->isUnauthorized()) {
                ($this->onUnauthorized)();
            }

            throw $e;
        }
    }

    /** @throws InvalidArgumentException */
    private function urn(string|SharedPost $post): string
    {
        if ($post instanceof SharedPost && $post->network !== self::NETWORK) {
            throw new InvalidArgumentException("This share went to [{$post->network}], not LinkedIn.");
        }

        $urn = $post instanceof SharedPost ? $post->post_id : $post;

        if (! is_string($urn) || ! str_starts_with($urn, 'urn:li:')) {
            throw new InvalidArgumentException($post instanceof SharedPost
                ? 'This share has no LinkedIn post id, so it cannot be looked up on LinkedIn.'
                : "[{$urn}] is not a LinkedIn URN.");
        }

        return $urn;
    }

    /** @throws InvalidArgumentException */
    private function actor(string|SharedPost $post, ?string $actor): string
    {
        if ($actor !== null) {
            return $this->page($actor)->id;
        }

        // The page the share went to; the target column exists since 1.2.
        $postedAs = $post instanceof SharedPost ? ($post->getAttributes()['target'] ?? null) : null;

        return is_string($postedAs) && $this->findPage($postedAs) !== null ? $postedAs : $this->defaultActor();
    }

    /** community_page, else the first selected page. */
    private function defaultActor(): string
    {
        $configured = self::configuredPage();

        return $configured !== null ? $this->page($configured)->id : $this->selectedPages[0];
    }

    /** @throws InvalidArgumentException */
    private function organization(?string $organization): string
    {
        $page = $organization === null
            ? ($this->defaultPage() ?? throw new InvalidArgumentException('This LinkedIn connection has no organization page; set LINKEDIN_ORGANIZATIONS=true and connect again.'))
            : $this->page($organization);

        if ($page->type !== Page::TYPE_ORGANIZATION) {
            throw new InvalidArgumentException("[{$page->id}] is not a LinkedIn organization page.");
        }

        return $page->id;
    }

    /** @throws InvalidArgumentException */
    private function page(string $id): Page
    {
        return $this->findPage($id) ?? throw new InvalidArgumentException("[{$id}] is not a page of this LinkedIn account.");
    }

    private function findPage(string $id): ?Page
    {
        foreach ($this->pages as $page) {
            if ($page->id === $id) {
                return $page;
            }
        }

        return null;
    }

    /** networks.linkedin.community_page as a URN: "2414183" means urn:li:organization:2414183. */
    private static function configuredPage(): ?string
    {
        $page = config('social-autopost.networks.linkedin.community_page');
        $page = is_scalar($page) ? trim((string) $page) : '';

        if ($page === '') {
            return null;
        }

        return str_starts_with($page, 'urn:li:') ? $page : 'urn:li:organization:'.$page;
    }

    /** LinkedIn caps page sizes at 100. */
    private static function count(int $count): int
    {
        return max(1, min(100, $count));
    }
}
