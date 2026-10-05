<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClientAdminRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps onto `client_admin` -- the join table granting a user (a "Client
 * Manager") access to a client. Real composite-PK FK table, so this is
 * modeled with actual entity associations rather than plain string columns.
 */
#[ORM\Entity(repositoryClass: ClientAdminRepository::class)]
#[ORM\Table(name: 'client_admin')]
class ClientAdmin
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'clientAdmins')]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false)]
    private Client $client;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    public function getClient(): Client
    {
        return $this->client;
    }

    public function setClient(Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }
}
