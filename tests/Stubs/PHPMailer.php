<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

class PHPMailer
{
    public array $to = [];
    public array $cc = [];
    public array $reply = [];
    public $Body = '';
    public $AltBody = '';
    public $Subject = '';

    public function __construct(bool $exc = false)
    {
    }

    public function IsSendmail()
    {
    }

    public function isSMTP()
    {
    }

    public function AddReplyTo(string $addr, string $name = '')
    {
        $this->reply[$addr] = $name;
    }

    public function AddAddress(string $addr, string $name = '')
    {
        $this->to[$addr] = $name;
    }

    public function AddCC(string $addr, string $name = '')
    {
        $this->cc[$addr] = $name;
    }

    public function SetLanguage(string $lang)
    {
    }

    public function IsHTML(bool $v = true)
    {
    }

    public function Send()
    {
        $GLOBALS['mail_sent_count'] = ($GLOBALS['mail_sent_count'] ?? 0) + 1;
    }
}

class_alias(PHPMailer::class, 'PHPMailer\\PHPMailer\\PHPMailer');
