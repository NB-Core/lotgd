<?php
output("`@`c`bAll Done!`b`c");
output("Your install of Legend of the Green Dragon has been completed!`n");
output("`nRemember us when you have hundreds of users on your server, enjoying the game.");
output("Eric, JT, and a lot of others put a lot of work into this world, so please don't disrespect that by violating the license.");
if ($session['user']['loggedin']){
	addnav("Continue",$session['user']['restorepage']);
}else{
	addnav("Login Screen","./");
}
savesetting("installer_version",$logd_version);
$file = __DIR__ . '/../index.php';
	if (file_exists($file)) {
		try {
			if (unlink($file)) {
				output("`2Installer file install/index.php removed.`n");
			} else {
				output("`\$Unable to delete install/index.php. Please remove it manually.`n");
			}
		} catch (Throwable $e) {
			output("`\$Error deleting install/index.php: " . $e->getMessage() . "`n");
		}
	}
$noinstallnavs=true;
?>
