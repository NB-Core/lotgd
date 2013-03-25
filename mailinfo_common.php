<?php

require("lib/xajax/xajax_core/xajax.inc.php");
$xajax = new xajax("mailinfo_server.php");
//$xajax->setFlag("debug",true);
$xajax->registerFunction("mail_status");
$xajax->registerFunction("timeout_status");

?>
