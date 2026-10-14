<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Data\Connection;
use Siberfx\SocialAutopost\Data\Page;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Posts\LinkPost;

/**
 * A network whose connected account can post to more than one place: a
 * LinkedIn member and the organization pages they run, or several Facebook
 * Pages. People pick one or more pages; shares go to every selected page.
 *
 * Optional: a SocialNetwork without it posts to its one account. publish()
 * keeps working on a network with pages and posts to the first selected page.
 */
interface HasPages
{
    /** @return list<Page> Every page the connected account can post to; empty when not connected. */
    public function pages(): array;

    /** @return list<string> The ids of the pages shares go to; empty when not connected. */
    public function selectedPages(): array;

    /**
     * Choose the pages shares go to, and store the choice.
     *
     * @param  list<string>  $ids
     *
     * @throws NotConnected
     * @throws InvalidArgumentException When $ids is empty or names a page the account cannot post to.
     */
    public function selectPages(array $ids): Connection;

    /**
     * Publish to one page. Returns the network's post id, or null when it accepted the post without one.
     *
     * @throws NotConnected|RequestFailed
     * @throws InvalidArgumentException When $page is not one of pages().
     */
    public function publishToPage(Model&ShareableOnSocial $model, LinkPost $post, string $page): ?string;
}
