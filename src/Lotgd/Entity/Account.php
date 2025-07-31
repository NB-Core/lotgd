<?php

declare(strict_types=1);

namespace Lotgd\Entity;

use Doctrine\ORM\Mapping as ORM;
use BadMethodCallException;

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

    /** @ORM\Column(type="string", length=100) */
    private string $name = '';

    /** @ORM\Column(type="string", length=40) */
    private string $playername = '';

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $sex = 0;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $strength = 10;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $dexterity = 10;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $intelligence = 10;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $constitution = 10;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $wisdom = 10;

    /** @ORM\Column(type="string", length=20) */
    private string $specialty = '';

    /** @ORM\Column(type="bigint", options={"unsigned":true}) */
    private int $experience = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $gold = 0;

    /** @ORM\Column(type="string", length=50) */
    private string $weapon = 'Fists';

    /** @ORM\Column(type="string", length=50) */
    private string $armor = 'T-Shirt';

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $seenmaster = 0;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $level = 1;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $defense = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $attack = 0;

    /** @ORM\Column(type="boolean") */
    private bool $alive = true;

    /** @ORM\Column(type="bigint") */
    private int $goldinbank = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $marriedto = 0;

    /** @ORM\Column(type="integer") */
    private int $spirits = 0;

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $laston;

    /** @ORM\Column(type="integer") */
    private int $hitpoints = 10;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $maxhitpoints = 10;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $gems = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $weaponvalue = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $armorvalue = 0;

    /** @ORM\Column(type="string", length=50) */
    private string $location = 'Degolburg';

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $turns = 10;

    /** @ORM\Column(type="string", length=50) */
    private string $title = '';

    /** @ORM\Column(type="string", length=32) */
    private string $password = '';

    /** @ORM\Column(type="text") */
    private string $badguy = '';

    /** @ORM\Column(type="text") */
    private string $companions = '';

    /** @ORM\Column(type="text") */
    private string $allowednavs = '';

    /** @ORM\Column(type="boolean") */
    private bool $loggedin = false;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $resurrections = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $superuser = 1;

    /** @ORM\Column(type="integer") */
    private int $weapondmg = 0;

    /** @ORM\Column(type="integer") */
    private int $armordef = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $age = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $charm = 0;

    /** @ORM\Column(type="string", length=50) */
    private string $specialinc = '';

    /** @ORM\Column(type="string", length=1000) */
    private string $specialmisc = '';

    /** @ORM\Column(type="string", length=50) */
    private string $login = '';

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $lastmotd;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $playerfights = 3;

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $lasthit;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $seendragon = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $dragonkills = 0;

    /** @ORM\Column(type="boolean") */
    private bool $locked = false;

    /** @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $restorepage = null;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $hashorse = 0;

    /** @ORM\Column(type="text") */
    private string $bufflist = '';

    /** @ORM\Column(type="float") */
    private float $gentime = 0.0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $gentimecount = 0;

    /** @ORM\Column(type="string", length=40) */
    private string $lastip = '';

    /** @ORM\Column(type="string", length=32, nullable=true) */
    private ?string $uniqueid = null;

    /** @ORM\Column(type="text") */
    private string $dragonpoints = '';

    /** @ORM\Column(type="smallint") */
    private int $boughtroomtoday = 0;

    /** @ORM\Column(type="string", length=128) */
    private string $emailaddress = '';

    /** @ORM\Column(type="string", length=128) */
    private string $replaceemail = '';

    /** @ORM\Column(type="string", length=32) */
    private string $emailvalidation = '';

    /** @ORM\Column(type="string", length=32) */
    private string $forgottenpassword = '';

    /** @ORM\Column(type="smallint") */
    private int $sentnotice = 0;

    /** @ORM\Column(type="text") */
    private string $prefs = '';

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $pvpflag;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $transferredtoday = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $soulpoints = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $gravefights = 0;

    /** @ORM\Column(type="string", length=50) */
    private string $hauntedby = '';

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $deathpower = 0;

    /** @ORM\Column(type="bigint", options={"unsigned":true}) */
    private int $gensize = 0;

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $recentcomments;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $donation = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $donationspent = 0;

    /** @ORM\Column(type="text") */
    private string $donationconfig = '';

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $referer = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $refererawarded = 0;

    /** @ORM\Column(type="string", length=255) */
    private string $bio = '';

    /** @ORM\Column(type="string", length=50) */
    private string $race = '0';

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $biotime;

    /** @ORM\Column(type="smallint", nullable=true) */
    private ?int $banoverride = 0;

    /** @ORM\Column(type="string", length=128) */
    private string $translatorlanguages = 'en';

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $amountouttoday = 0;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $pk = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $dragonage = 0;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $bestdragonage = 0;

    /** @ORM\Column(type="string", length=25) */
    private string $ctitle = '';

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $beta = 0;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $slaydragon = 0;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $fedmount = 0;

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $regdate;

    /** @ORM\Column(type="integer", options={"unsigned":true}) */
    private int $clanid = 0;

    /** @ORM\Column(type="smallint", options={"unsigned":true}) */
    private int $clanrank = 0;

    /** @ORM\Column(type="datetime") */
    private \DateTimeInterface $clanjoindate;

    public function __construct()
    {
        $this->laston = new \DateTime('1970-01-01 00:00:00');
        $this->lastmotd = new \DateTime('1970-01-01 00:00:00');
        $this->lasthit = new \DateTime('1970-01-01 00:00:00');
        $this->pvpflag = new \DateTime('1970-01-01 00:00:00');
        $this->recentcomments = new \DateTime('1970-01-01 00:00:00');
        $this->biotime = new \DateTime('1970-01-01 00:00:00');
        $this->regdate = new \DateTime('1970-01-01 00:00:00');
        $this->clanjoindate = new \DateTime('1970-01-01 00:00:00');
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

    public function setLevel(int|string $level): self
    {
        $this->level = (int) $level;
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

    /**
     * Magic method to handle get* and set* calls for all properties.
     */
    public function __call(string $name, array $arguments)
    {
        if (str_starts_with($name, 'get')) {
            $prop = lcfirst(substr($name, 3));
            if (property_exists($this, $prop)) {
                return $this->$prop;
            }
        }
        if (str_starts_with($name, 'set')) {
            $prop = lcfirst(substr($name, 3));
            if (property_exists($this, $prop)) {
                $value = $arguments[0] ?? null;

                $ref  = new \ReflectionProperty($this, $prop);
                $type = $ref->hasType() ? $ref->getType()->getName() : null;

        // This below is necessary as some old modules set i.e. isalive to 1 or 0.. not true or false
        // LIkewise, some calculations will be done to round(), but resulting in i.e. 124.0 and not 124
        // Means the datatype is wrong.
        // I pondered a long time how to fix this, but with array-assignment in $session['user'] this
        // is not solvable. The conversion approach below is practical and not worse than the original
        // source code.
        // I deem old modules (which may need still some modernization for php8) still worth of
        // being able to run by design of LotGD

                if ($type === 'bool') {
                    $value = (bool) $value;
                } elseif ($type === 'int') {
                    $value = intval($value);
                } elseif ($type === 'float') {
                    $value = (float) $value;
                } elseif ($type === 'string' && $value === null) {
                    $value = '';
                }

                $this->$prop = $value;

                return $this;
            }
        }

        throw new BadMethodCallException(sprintf('Undefined method %s', $name));
    }
}
