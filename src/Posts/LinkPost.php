<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Posts;

use InvalidArgumentException;

/** What a model wants posted: a link with its preview, plus the post text. */
final class LinkPost
{
    private const DEFAULT_COMMENTARY = "{title}\n\n{url}";

    private string $description = '';

    private string $commentary = self::DEFAULT_COMMENTARY;

    private function __construct(
        private readonly string $url,
        private readonly string $title,
    ) {}

    /** @throws InvalidArgumentException When the URL is not an absolute http(s) URL. */
    public static function make(string $url, string $title): self
    {
        $normalised = self::normalise($url);
        $scheme = strtolower((string) parse_url($normalised, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($normalised, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Posts need an absolute http(s) URL, got [{$url}].");
        }

        return new self($normalised, $title);
    }

    /**
     * Converts an internationalised host to punycode (when ext-intl is
     * installed) and percent-encodes every byte outside printable ASCII, so
     * https://şirket.com.tr/blog/çalışma becomes a URL the networks accept.
     */
    private static function normalise(string $url): string
    {
        $url = trim($url);

        if (function_exists('idn_to_ascii')
            && preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)$~is', $url, $parts) === 1
            && preg_match('/[^\x00-\x7F]/', $parts[2]) === 1) {
            $at = strrpos($parts[2], '@');
            $userinfo = $at === false ? '' : substr($parts[2], 0, $at + 1);
            $hostAndPort = $at === false ? $parts[2] : substr($parts[2], $at + 1);
            $port = preg_match('/:\d*$/', $hostAndPort, $match) === 1 ? $match[0] : '';
            $host = idn_to_ascii(substr($hostAndPort, 0, strlen($hostAndPort) - strlen($port)), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($host !== false) {
                $url = $parts[1].$userinfo.$host.$port.$parts[3];
            }
        }

        return (string) preg_replace_callback('/[^\x21-\x7E]/', fn (array $byte): string => rawurlencode($byte[0]), $url);
    }

    public function description(string $description): self
    {
        $copy = clone $this;
        $copy->description = $description;

        return $copy;
    }

    /** Post text; {url} and {title} are filled in. Reserved characters are escaped when sending. */
    public function commentary(string $template): self
    {
        $copy = clone $this;
        $copy->commentary = $template;

        return $copy;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 200);
    }

    public function descriptionText(): string
    {
        return mb_substr($this->description, 0, 256);
    }

    public function commentaryText(): string
    {
        return strtr($this->commentary, ['{url}' => $this->url, '{title}' => $this->title()]);
    }
}
