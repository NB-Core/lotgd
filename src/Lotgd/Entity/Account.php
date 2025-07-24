<?php

declare(strict_types=1);

namespace Lotgd\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Lotgd\\Repository\\AccountRepository")
 * @ORM\Table(name="accounts")
 */
class Account
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer", name="acctid")
     * @ORM\GeneratedValue
     */
    private ?int $acctid = null;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private string $login = "";

    /**
     * @ORM\Column(type="string", length=128, name="emailaddress")
     */
    private string $emailaddress = "";

    /**
     * @ORM\Column(type="string", length=32)
     */
    private string $password = "";

    /**
     * @ORM\Column(type="string", length=100, name="name")
     */
    private string $name = "";

    /**
     * @ORM\Column(type="smallint", options={"unsigned":true})
     */
    private int $level = 1;

    /**
     * @ORM\Column(type="datetime", name="laston")
     */
    private \DateTimeInterface $laston;

    public function __construct()
    {
        $this->laston = new \DateTime('1970-01-01 00:00:00');
    }

    public function getAcctid(): ?int
    {
        return $this->acctid;
    }

    public function getLogin(): string
    {
        return $this->login;
    }
    public function setLogin(string $login): self
    {
        $this->login = $login;
        return $this;
    }

    public function getEmailaddress(): string
    {
        return $this->emailaddress;
    }

    public function setEmailaddress(string $email): self
    {
        $this->emailaddress = $email;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }
    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function getLaston(): \DateTimeInterface
    {
        return $this->laston;
    }

    public function setLaston(\DateTimeInterface $date): self
    {
        $this->laston = $date;
        return $this;
    }
}
