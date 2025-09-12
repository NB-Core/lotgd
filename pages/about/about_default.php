<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Modules\HookHandler;
use Lotgd\Nltoappon;

$order = array("2","3","1"); //arbitrary, this order the following hooks and whatnot

foreach ($order as $current_rank) {
    switch ($current_rank) {
        case "3":
            //notes for the +nb Editions
            $output->output("`\$For the original 'Legend of the Green Dragon' #About information check below.`n`n");
            $output->output("`l+nb Edition");
            $output->output("`nBy Oliver Brendel (<a href='http://nb-core.org'>NB Core</a>) `n`n", true);
            $output->output("This version is a forked version of the pre-1.1.1 DP Edition core shortly after I stopped developing for this specific version.`n`nThe reasons were mainly that the path the leading people there wanted to go was not my path at all. Hence this version reflects mostly what *I* wanted to see different *without* having to rewrite the core using modules... and still be stuck with the old structures. Also I don't have to ask for changes with the high probability of getting no answer or a negative one.`n`n");
            $output->output("If you use this core, you need to be aware of the goals of my optimizations:`n`n<ul>", true);
               $output->output("<li>PHP 8.4 or newer is required</li>", true);
               $output->output("<li>MySQL 5.0 or later (MariaDB compatible) is required</li>", true);
            $output->output("<li>Features should be done focussed on avoiding high-load and focussing on many (MMORG) users</li>", true);
            $output->output("<li>Roleplay like in D&D should be possible</li>", true);
            $output->output("</ul>`n`n", true);
            $output->output("For the download of this version please go to the github repository at <a href='https://github.com/NB-Core/lotgd/releases'>https://github.com/NB-Core/lotgd/releases</a> where the latest development version (snapshots) and stable versions are hosted.`n`n", true);
            $output->output("`n`nI do not ship modules with it, most modules from 1.x.x DP Editions and previous will work. However there is no guarantee... test them. And be aware that many unbalance gameplay as they give out too much EXP/Buffs/Atk+Def stats.");
            $output->outputNotl("`n`n");

            break;
        case "2":
            /* NOTICE
             * NOTICE Server admins may put their own information here,
             * NOTICE please leave the main about body untouched.
             * NOTICE
             */
            $output->rawOutput("<hr>");
            $imprint = $settings->getSetting("impressum", ""); //yes, it's named impressum after the German word. We have to thank somebody for that - w00t
            if ($imprint > "") {
                $output->outputNotl("%s", Nltoappon::convert($imprint), true); //yes, HTML possible
            }
            $output->rawOutput("<br/><br/>");
            break;
        case "1":
            /* NOTICE
             * NOTICE This section may not be modified, please modify the
             * NOTICE Server Specific section above.
             * NOTICE
             */
            $output->output("`@Legend of the Green Dragon Engine`nBy Eric Stevens & JT Traub`n`n");
            $output->output("`cLoGD version ");
            $output->outputNotl("$logd_version`c");
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            $output->output("MightyE tells you, \"`2Legend of the Green Dragon is a remake of and homage to the classic BBS Door game, Legend of the Red Dragon (aka LoRD) by <a href='http://www.rtsoft.com' target='_blank'>Seth Able Robinson</a>.`@\"", true);
            $output->output("`n`n`@\"`2LoRD is now owned by Gameport (<a href='http://www.gameport.com/bbs/lord.html' target='_blank'>http://www.gameport.com/bbs/lord.html</a>), and they retain exclusive rights to the LoRD name and game. ", true);
            $output->output("That's why all content in Legend of the Green Dragon is new, with only a very few nods to the original game, such as the buxom barmaid, Violet, and the handsome bard, Seth.`@\"`n`n");
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            $output->output("`@\"`2Although serious effort was made to preserve the original feel of the game, numerous departures were taken from the original game to enhance playability, and to adapt it to the web.`@\"`n`n");
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            $output->output("`@\"`2LoGD (after version 0.9.7) is released under a <a href='http://creativecommons.org/licenses/by-nc-sa/2.0/' target='_blank'>Creative Commons License</a>, which essentially means that the source code to the game, and all derivatives of the game must remain open and available upon request.", true);
            $output->output("Version 0.9.7 and before are still available under the <a href='http://www.gnu.org/licenses/gpl.html' target='_blank'>GNU General Public License</a> though 0.9.7 will be the last release under that license.", true);
            $output->output("To use any of the new features requires using the 1.0.0 code.  You may explicitly not place code from versions after 0.9.7 into 0.9.7 and release the combined derivative work under the GPL.`@\"`n`n", true);
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            $output->output("`@\"`2You may download the latest official version of LoGD at <a href='http://dragonprime.net/' target='_blank'>DragonPrime</a>  and you can play the Classic version at <a href='http://lotgd.net/'>http://lotgd.net</a>.`@\"`n`n", true);
            //$output->output("`@\"`2The most recent *UNSTABLE* pre-release snapshot is available from <a href='http://dragonprime.net/users/Kendaer/' target='_blank'>http://dragonprime.net/users/Kendaer/</a>.", true);
            $output->output("You should attempt to use this code only if you are comfortable with PHP and MySQL and willing to manually keep your code up to date.`@\"`n`n");
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            $output->output("`@\"`2Additionally, there is an active modder community located at <a href='http://dragonprime.net' target='_blank'>DragonPrime</a> which may help you find additional features which you may wish to add to your game.", true);
            $output->output("For these additional features you will find active support within the DragonPrime community.`@\"`n`n");
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            $output->output("`@\"`2LoGD is programmed in PHP with a MySQL backend.");
            $output->output("It is known to run on Windows and Linux with appropriate setups.");
            $output->output("The core code has been actively written by Eric Stevens and JT Traub, with some pieces by other authors (denoted in the source at these locations), and the code has been released under a <a href='http://creativecommons.org/licenses/by-nc-sa/2.0/' target='_blank'>Creative Commons License</a>.", true);
            $output->output("Users of the source are bound to the terms therein.`n", true);
            $output->output("The DragonPrime Development Team took over responsibility for code development on January 1<sup>st</sup>, 2006 and continues to maintain and add to features of the core code.`@\"`n`n", true);
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            $output->output("`@\"`2Users of the source are free to view and modify the source, but original copyright information, and original text from the about page must be preserved, though they may be added to.`@\"`n`n");
            $output->output("`@\"`2We hope you enjoy the game!`@\"");
            /*
             * This section may not be modified, please modify the Server
             * Specific section above.
             */
            break;
    }
    $output->rawOutput("<hr>");
}
Nav::add("About LoGD");
Nav::add("Game Setup Info", "about.php?op=setup");
Nav::add("Module Info", "about.php?op=listmodules");
Nav::add("License Info", "about.php?op=license");
HookHandler::hook("about");
