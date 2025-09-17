<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

class PHPMailerException extends \Exception
{
}

#[\AllowDynamicProperties]
class PHPMailer
{
    public array $to = [];
    public array $cc = [];
    public array $reply = [];
    public $Body = '';
    public $AltBody = '';
    public $Subject = '';
    public $Host = '';
    public $Username = '';
    public $Password = '';
    public $SMTPAuth = false;
    public $SMTPSecure = '';
    public $Port = 0;
    public $From = '';
    public $FromName = '';
    public $WordWrap = 0;
    public $CharSet = '';
    public $ErrorInfo = '';

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
        if (!empty($GLOBALS['mail_force_error'])) {
            $message = $GLOBALS['mail_force_error_message'] ?? 'Forced mail failure.';
            $this->ErrorInfo = $message;
            $GLOBALS['mail_force_error'] = false;

            throw new PHPMailerException($message);
        }

        $GLOBALS['mail_sent_count'] = ($GLOBALS['mail_sent_count'] ?? 0) + 1;
        $GLOBALS['last_subject'] = $this->Subject;
    }
}

class_alias(PHPMailer::class, 'PHPMailer\\PHPMailer\\PHPMailer');
class_alias(PHPMailerException::class, 'PHPMailer\\PHPMailer\\Exception');
