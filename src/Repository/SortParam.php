<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Turns a list page's ?sort= value ("name", "-createdAt") into an ORDER BY column and direction, against an
 * allow-list, so a sort key is never interpolated into DQL unchecked. One rule for every sortable list.
 */
final class SortParam
{
    /**
     * @param array<string, string> $allowed     sort key => DQL expression
     * @param string                $defaultSort used when $sort is empty or not allowed; must be a key of $allowed
     *
     * @return array{0: string, 1: 'ASC'|'DESC'}
     */
    public static function resolve(?string $sort, array $allowed, string $defaultSort): array
    {
        $sort = (string) $sort;
        if (!isset($allowed[ltrim($sort, '-')])) {
            $sort = $defaultSort;
        }

        return [$allowed[ltrim($sort, '-')], str_starts_with($sort, '-') ? 'DESC' : 'ASC'];
    }
}
