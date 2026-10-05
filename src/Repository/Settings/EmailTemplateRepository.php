<?php

declare(strict_types=1);

namespace App\Repository\Settings;

use App\Entity\Settings\EmailTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailTemplate>
 */
class EmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailTemplate::class);
    }

    public function findByModule(string $module): ?EmailTemplate
    {
        return $this->findOneBy(['module' => $module]);
    }
}
