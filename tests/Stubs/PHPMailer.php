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

    public function __construct($exc = false)
    {
    }

    public function IsSendmail()
    {
    }

    public function isSMTP()
    {
    }

    public function AddReplyTo($addr, $name = '')
    {
        $this->reply[$addr] = $name;
    }

    public function AddAddress($addr, $name = '')
    {
        $this->to[$addr] = $name;
    }

    public function AddCC($addr, $name = '')
    {
        $this->cc[$addr] = $name;
    }

    public function SetLanguage($lang)
    {
    }

    public function IsHTML($v = true)
    {
    }

    public function Send()
    {
        $GLOBALS['mail_sent_count'] = ($GLOBALS['mail_sent_count'] ?? 0) + 1;
    }
}

class_alias(PHPMailer::class, 'PHPMailer\\PHPMailer\\PHPMailer');
