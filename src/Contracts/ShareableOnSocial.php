<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Contracts;

use Siberfx\SocialAutopost\Posts\LinkPost;

/** Implemented by Eloquent models that can be shared to the social networks. */
interface ShareableOnSocial
{
    /** Whether the model is public right now (e.g. published and not expired). */
    public function isLiveForSocial(): bool;

    /** What to post. Each network formats it for itself.
     *
     * @throws \InvalidArgumentException When LinkPost::make() is given an invalid URL.
     */
    public function toSocialPost(): LinkPost;
}
