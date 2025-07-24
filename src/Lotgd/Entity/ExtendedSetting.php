<?php

declare(strict_types=1);

namespace Lotgd\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Lotgd\\Repository\\ExtendedSettingRepository")
 * @ORM\Table(name="settings_extended")
 */
class ExtendedSetting
{
    /**
     * @ORM\Id
     * @ORM\Column(name="setting", type="string", length=50)
     */
    private string $setting = '';

    /**
     * @ORM\Column(type="text")
     */
    private string $value = '';

    public function getSetting(): string
    {
        return $this->setting;
    }

    public function setSetting(string $setting): self
    {
        $this->setting = $setting;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }
}
