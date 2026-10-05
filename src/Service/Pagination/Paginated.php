<?php

declare(strict_types=1);

namespace App\Service\Pagination;

/**
 * One page of a list plus what the pager needs to draw itself.
 *
 * @template T
 */
final class Paginated
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->pageSize));
    }

    /** The requested page clamped to at least 1 — a ?page= of 0, "-3" or "abc" means the first page. */
    public static function pageFrom(mixed $value): int
    {
        return max(1, (int) $value);
    }
}
