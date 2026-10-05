<?php

declare(strict_types=1);

namespace App\Service\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Symfony Validator, answered as a flat list of messages — the shape every client/project/task write returns
 * to its form (ADR-070).
 */
final class WriteValidator
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /** @return list<string> */
    public function checkObject(object $input, array $groups = []): array
    {
        return $this->messages($this->validator->validate($input, groups: $groups === [] ? null : $groups));
    }

    /**
     * Validates the keys of $values that have rules; a key that is absent is "not supplied", not "blank".
     *
     * @param array<string, mixed>                  $values
     * @param array<string, list<Constraint>>       $rules
     *
     * @return list<string>
     */
    public function checkFields(array $values, array $rules): array
    {
        $fields = [];
        foreach ($rules as $field => $constraints) {
            $fields[$field] = new Assert\Optional($constraints);
        }

        return $this->messages($this->validator->validate(
            array_intersect_key($values, $rules),
            new Assert\Collection(fields: $fields, allowExtraFields: true, allowMissingFields: true),
        ));
    }

    /** @return list<string> */
    private function messages(ConstraintViolationListInterface $violations): array
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
