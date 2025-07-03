<?php
use Lotgd\SendMail;

function send_email($to, $body, $subject, $from, $cc = false, $contenttype = 'text/plain')
{
    return SendMail::send($to, $body, $subject, $from, $cc, $contenttype);
}
