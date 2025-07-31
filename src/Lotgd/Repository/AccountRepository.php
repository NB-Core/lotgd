<?php

declare(strict_types=1);

namespace Lotgd\Repository;

use Doctrine\ORM\EntityRepository;
use Lotgd\Entity\Account;

class AccountRepository extends EntityRepository
{
    public function findByLogin(string $login): ?Account
    {
        return $this->findOneBy(['login' => $login]);
    }
}
