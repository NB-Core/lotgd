<?php

declare(strict_types=1);

namespace Lotgd\Repository;

use Doctrine\ORM\EntityRepository;
use Lotgd\Entity\Setting;

class SettingRepository extends EntityRepository
{
    public function findValue(string $setting): ?string
    {
        $entity = $this->find($setting);
        return $entity?->getValue();
    }
}
