<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Translator;

Translator::getInstance()->setSchema('faq');
Header::popupHeader("Specific and Technical Questions");
$c = Translator::translateInline("Return to Contents");
$output->rawOutput("<a href='petition.php?op=faq'>$c</a><hr>");
$output->output("`n`n`c`bSpecific and technical questions`b`c`n");
$output->output("`^1.a. How can I have been killed by another player while I was currently playing?`n");
$output->output("`@The biggest cause of this is someone who began attacking you while you were offline, and completed the fight while you were online.");
$output->output("This can even happen if you have been playing nonstop for the last hour.");
$output->output("When someone starts a fight, they are forced by the game to finish it at some point.");
$output->output("If they start a fight with you, and close their browser, the next time they log on, they will have to finish the fight.");
$output->output("You will lose the lesser of the gold you had on hand when they attacked you, or the gold on hand when they finished the fight.");
$output->output("So if you logged out with 1 gold on hand, they attack you, you log on, accumulate 2000 gold on hand, and they complete the fight, they will only come away from it with 1 gold.");
$output->output("The same is true if you logged out with 2000 gold, and when they completed killing you, you only had 1 gold.`n`n");
$output->output("`^1.b. Why did it say I was killed in the fields when I slept in the inn?`n");
$output->output("`@The same thing can happen where someone started attacking you when you were in the fields, and finished after you had retired to the inn for the day.");
$output->output("Keep in mind that if you are idle on the game for too long, you become a valid target for others to attack you in the fields.");
$output->output("If you're going to go away from your computer for a few minutes, it's a good idea to head to the inn for your room first so that you don't risk someone attacking you while you're idle.`n`n");
$output->output("`^2. The game tells me that I'm not accepting cookies, what are they and what do I do?`n");
$output->output("`@Cookies are little bits of data that websites store on your computer so they can distinguish you from other players.");
$output->output("Sometimes if you have a firewall it will block cookies, and some web browsers will let you block cookies.");
$output->output("Check the documentation for your browser or firewall, or look around in its preferences for settings to modify whether or not you accept cookies.");
$output->output("You need to at least accept session cookies to play the game, though all cookies are better.`n`n");
$output->output("`^3. What do`n&nbsp;&nbsp;`iWarning: mysql_pconnect(): Lost connection to MySQL server during query in /home/lotgd/public_html/dbwrapper.php on line 82`i`nand`n&nbsp;&nbsp;`iWarning: mysql_error(): supplied argument is not a valid MySQL-Link resource in /home/lotgd/public_html/dbwrapper.php on line 54`i`nmean?`n", true);
$output->output("`@It's a secret message from your computer telling you to stop staring at a screen and to go play outside.`n");
$output->output("Actually, it's a common temporary error, usually having to do with server load.");
$output->output("Don't worry about it, just reload the page (it may take a few tries).`n`n");
$output->output("`^4. Nothing is responding for hours now - what should I do ?`n");
$output->output("`@Go outside play a bit in Real Life (tm). When you get back it will work again - if not it's a serious problem.");
$output->output("Any server problems are caught less then 5 minutes after occurring, so if there is a problem, it's known - and we are working on it.");
$output->output("Every mail and ye olde mail reporting the same problem is just making it harder for us to work.`n`n");
$output->output("`^5. Why is the site giving me so many popups?`n");
$output->output("`@Please turn off your popup blocker. These aren't ads.`n");
$output->output("We use popup windows in the game for the following purposes:`n");
$output->output("a) To file a petition.`n");
$output->output("b) To write and receive Ye Olde Mail.`n");
$output->output("c) To make sure you see our newest Message of the Day (MoTD).`n");
$output->output("That last one is very important, since until you've viewed it the window will continue to try to open on every page load. These messages are for server announcements such as outages, current known bugs (which you really don't have to petition about, since we already know of them), and other things that the staff think you need to know about right away.`n`n");
$output->rawOutput("<hr><a href='petition.php?op=faq'>$c</a>");
