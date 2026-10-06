<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\GeneralConfigPage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Self sign-up is invitation-only by default (ADR-095). Tests that exercise the open sign-up form switch it on for
 * themselves and put the default back afterwards, so no other test inherits an open registration page.
 */
trait OpenRegistrationTrait
{
    private function openRegistration(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement(
            'REPLACE INTO config (config_key, config_value) VALUES (?, ?)',
            [GeneralConfigPage::REGISTRATION_MODE_KEY, GeneralConfigPage::REGISTRATION_OPEN]
        );
    }

    private function restoreRegistrationMode(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement('DELETE FROM config WHERE config_key = ?', [GeneralConfigPage::REGISTRATION_MODE_KEY]);
    }
}
