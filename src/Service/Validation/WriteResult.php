<?php

declare(strict_types=1);

namespace App\Service\Validation;

/**
 * What a create/update returns: the saved record, or the messages saying why nothing was saved.
 *
 * @template T of object
 */
final class WriteResult
{
    /**
     * @param T|null       $record
     * @param list<string> $errors
     */
    private function __construct(
        public readonly ?object $record,
        public readonly array $errors,
    ) {
    }

    /**
     * @param T $record
     *
     * @return self<T>
     */
    public static function saved(object $record): self
    {
        return new self($record, []);
    }

    /**
     * @param list<string> $errors
     *
     * @return self<T>
     */
    public static function failed(array $errors): self
    {
        return new self(null, $errors);
    }

    public function isSaved(): bool
    {
        return $this->errors === [];
    }
}
