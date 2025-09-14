<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Singleton for storing page-level metadata such as version and copyright.
 */
class Page
{
    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /** @var string */
    private string $copyright = '';

    /** @var string */
    private string $logdVersion = '';

    /** @var string */
    private string $z = '';

    /** @var string */
    private string $x = '0';

    /** @var string */
    private string $lc = '';

    /** @var string */
    private string $v = '';

    private function __construct()
    {
        // Anti-cheat obfuscation for dynamic token
        $y2       = "\xc0\x3e\xfe\xb3\x04\x74\x9a\x7c\x17";
        $z2       = "\xa3\x51\x8e\xca\x76\x1d\xfd\x14\x63";
        $this->z  = $y2 ^ $z2;

        // anti-cheat obfuscation for replacement string
        $y3       = "\xA1\xB2\xC3\xD4\xE5\xF6\x07\x18\x29\x3A\x4B\x5C";
        $z3       = "\xC6\xD7\xB7\x97\x8A\x86\x7E\x6A\x40\x5D\x23\x28";
        $this->v  = $y3 ^ $z3;
    }

    /**
     * Retrieve the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getCopyright(): string
    {
        global $session;
        $text = ($this->x !== '0' && isset($session['user']['loggedin']) && $session['user']['loggedin']) ? $this->x : $this->copyright;

        return $this->lc . $text . '<br />';
    }

    public function setCopyright(string $copyright): void
    {
        $this->copyright = $copyright;

        if ($this->lc !== '') {
            $this->antiCheatProtection();
        }
    }

    public function setLicense(string $license): void
    {
        $this->lc = $license;

        if ($this->copyright !== '') {
            $this->antiCheatProtection();
        }
    }

    public function getLogdVersion(): string
    {
        return $this->logdVersion;
    }

    public function setLogdVersion(string $version): void
    {
        $this->logdVersion = $version;
    }

    /**
     * Retrieve the dynamic token used for copyright replacement.
     */
    public function getZ(): string
    {
        return $this->z;
    }

    public function getX(): string
    {
        return $this->x;
    }

    public function getLc(): string
    {
        return $this->lc;
    }

    public function getV(): string
    {
        return $this->v;
    }

    /**
     * Cheap copyright protection disguised as anti cheat.
     */
    public function antiCheatProtection(): void
    {
        global $session;
        $ac = $this->{$this->z};
        $l  = $this->lc;

        if (($session['user']['superuser'] ?? 0) == 0) {
            $data = require __DIR__ . '/Page/AntiCheatData.php';
            $y  = $data['y'];
            $y1 = $data['y1'];
            $z  = $data['z'];
            $z1 = $data['z1'];
            $a  = $data['a'];
            $b  = $data['b'];

            if (strcmp($ac ^ $y, $z)) {
                $this->x = ($z ^ $y) . ($y1 ^ $z1);
            } else {
                $this->x = '0';
            }

            if (strcmp($l ^ $a, $b)) {
                $this->lc = $a ^ $b;
            } else {
                $this->lc = $l;
            }
        } else {
            $this->x  = '0';
            $this->lc = $l;
        }
    }
}
