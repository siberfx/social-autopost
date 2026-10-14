<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Whether a column exists, asked once per process. Lets a release add a column
 * without breaking apps that have not run its migration yet.
 */
final class Columns
{
    /** @var array<string, bool> */
    private static array $known = [];

    public static function has(string $table, string $column): bool
    {
        return self::$known[$table.'.'.$column] ??= Schema::hasColumn($table, $column);
    }

    /** Forget what was asked, e.g. after running migrations in tests. */
    public static function flush(): void
    {
        self::$known = [];
    }
}
