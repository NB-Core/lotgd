<?php
output("`\$Requested installer step not found.`n");
output("`2Restarting at stage 1...`n");
redirect("install/index.php?stage=1");
?>
