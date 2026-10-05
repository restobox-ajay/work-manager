<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Backs `payer`.`type` -- direct port of models/Payer.php's TYPE_*
 * constants.
 */
enum PayerType: string
{
    case Company = 'Company';
    case User = 'User';
}
