<?php
// Legacy wrapper for Mail::systemMail
use Lotgd\Mail;

function systemmail($to, $subject, $body, $from = 0, $noemail = false)
{
    Mail::systemMail($to, $subject, $body, $from, $noemail);
}
