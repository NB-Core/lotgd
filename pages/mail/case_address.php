<?php
declare(strict_types=1);

use Lotgd\Translator;
use Lotgd\Http;

output_notl("<form action='mail.php?op=write' method='post'>", true);
output("`b`2Address:`b`n");
$to = Translator::translateInline("To: ");
$forwardto = Translator::translateInline("Forward To: ");
$search = htmlentities(Translator::translateInline("Search"), ENT_COMPAT, getsetting("charset", "ISO-8859-1"));
$id = (int) httpget('id');
$forwardlink = '';
if ($id > 0) {
    $to = $forwardto;
    $forwardlink = "<input type='hidden' name='forwardto' value='$id'>";
}
$preop = (string) Http::get('preop');
output_notl("`2$to <input name='to' id='to' value=\"" . htmlentities($preop, ENT_COMPAT, getsetting("charset", "ISO-8859-1")) . "\">", true);
output_notl("<input type='submit' class='button' value=\"$search\">", true);
rawoutput($forwardlink);
rawoutput("</form>");
rawoutput("<script type='text/javascript'>document.getElementById(\"to\").focus();</script>");
