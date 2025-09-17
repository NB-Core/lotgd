<?php

use Lotgd\Mail;

function send_email($to, $body, $subject, $from, $cc = false, $contenttype = 'text/plain', $withErrorInfo = false)
{
    return Mail::send($to, $body, $subject, $from, $cc, $contenttype, (bool) $withErrorInfo);
}
