<?php

declare(strict_types=1);

use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Translator;

// addnews ready
// mail ready
// translator ready

/**
 * Module information for the in-game tutor.
 */
function tutor_getmoduleinfo(): array
{
        $info = array(
                "name" => "In-game tutor",
                "author" => "Booger & Shannon Brown & JT Traub",
                "version" => "1.0",
                "category" => "Administrative",
                "download" => "core_module",
                "prefs" => array(
                        "In-Game tutor User Preferences,title",
                        "user_ignore" => "Turn off the tutor help?,bool|0",
                        "seenforest" => "Has the player seen the forest instructions,bool|0",
                        ),
                );
        return $info;
}

/**
 * Install hooks for the tutor module.
 */
function tutor_install(): bool
{
        module_addhook("header-forest");
        module_addhook("newday");
        module_addhook("village");
        module_addhook("battle");
        return true;
}

/**
 * Uninstall the tutor module.
 */
function tutor_uninstall(): bool
{
        return true;
}

/**
 * Handle system hooks for the tutor module.
 *
 * @param string $hookname The name of the hook.
 * @param array  $args     Hook arguments.
 *
 * @return array Modified hook arguments.
 */
function tutor_dohook(string $hookname, array $args): array
{
        global $session;
    $age = $session['user']['age'];
    $ignore = get_module_pref("user_ignore");

    // If this person is already well out of tutoring range, just return
    if ($session['user']['dragonkills'] || $ignore || $age >= 11) {
        return $args;
    }

    switch ($hookname) {
        case "newday":
            set_module_pref("seenforest", 0);
            break;
        case "village":
            if ($age < 11) {
                Translator::tlschema($args['schemas']['gatenav']);
                addnav($args["gatenav"]);
                Translator::tlschema();
                addnav("*?`\$Help Me, I'm Lost!", "runmodule.php?module=tutor&op=helpfiles");
                unblocknav("runmodule.php?module=tutor&op=helpfiles");
            };
            $adef = $session['user']['armordef'];
            $wdam = $session['user']['weapondmg'];
            $gold = $session['user']['gold'];
            $goldinbank = $session['user']['goldinbank'];
            $goldtotal = $gold + $goldinbank;
            $tutormsg = "";
            if ($wdam == 0 && $gold >= 48) {
                $tutormsg = Translator::translateInline("\"`3You really should get a weapon, to make you stronger. You can buy one at the `^weapon shop`3. I'll meet you there!`0\"`n");
            } elseif ($wdam == 0 && $goldtotal >= 48) {
                $tutormsg = Translator::translateInline("\"`3We need to withdraw some gold from `^the bank`3 to buy a weapon, Come with me!`0\"`n");
            } elseif ($adef == 0 && $gold >= 48) {
                $tutormsg = Translator::translateInline("\"`3You won't be very safe without any armor! The `^armor shop`3 has a nice selection. Let's go!`0\"`n");
            } elseif ($adef == 0 && $goldtotal >= 48) {
                $tutormsg = Translator::translateInline("\"`3We need to withdraw some gold from `^the bank`3, so we can buy some armor!`0\"`n");
            } elseif (!$session['user']['experience']) {
                $tutormsg = Translator::translateInline("\"`3The `^forest`3 is worth visiting, too. That's where you gain experience and gold!`0\"`n");
            } elseif ($session['user']['experience'] > 100 && $session['user']['level'] == 1 && !$session['user']['seenmaster']) {
                $tutormsg = Translator::translateInline("\"`3Holy smokes!  You're advancing so fast!  You have enough experience to reach level 2.  You should find the `^warrior training`3, and challenge your master!  After you've done that, you'll find you're much more powerful.`0\"`n");
            }
            if ($tutormsg) {
                tutor_talk("%s", $tutormsg);
            }
            break;
        case "battle":
            $badguy = $args;
            $tutormsg = "";
            if (!isset($badguy['creaturehealth'])) {
                return $args; // nothing to do here
            }
            if ($badguy['creaturehealth'] > 0 && $badguy['creaturelevel'] > $session['user']['level'] && $badguy['type'] == 'forest') {
                $tutormsg = Translator::translateInline("`#Eibwen`0 looks agitated!  \"`\$Look out!`3 This creature looks like it is a higher level than you!  You might want to `^run away`3! You might not be successful, but keep trying and hope you get away before you're turned into forest fertilizer!`0\"`n");
            }
            if ($tutormsg) {
                tutor_talk("%s", $tutormsg);
            }
                // no break
        case "header-forest":
            $adef = $session['user']['armordef'];
            $wdam = $session['user']['weapondmg'];
            $gold = $session['user']['gold'];
            $goldinbank = $session['user']['goldinbank'];
            $goldtotal = $gold + $goldinbank;
            $tutormsg = "";
            if ($goldtotal >= 48 && $wdam == 0) {
                $tutormsg = Translator::translateInline("\"`3Hey, you have enough gold to buy a weapon. It might be a good idea to visit `^the town`3 now and go shopping!`0\"`n");
            } elseif ($goldtotal >= 48 && $adef == 0) {
                $tutormsg = Translator::translateInline("\"`3Hey, you have enough gold to buy some armor. It might be a good idea to visit `^the town`3 now and go shopping!`0\"`n");
            } elseif (!$session['user']['experience'] && !get_module_pref("seenforest")) {
                $tutormsg = Translator::translateInline("`#Eibwen`& flies in loops around your head. \"`3Not much to say here.  Fight monsters, gain gold, heal when you need to.  Most of all, have fun!`0\"`n`nHe flies off back toward the village.`n`nOver his shoulder, he calls out, \"`3Before I go, please read the FAQs... and the Message of the Day is something you should check each time you log in. Don't be afraid to explore, but don't be afraid to run away either! And just remember, dying is part of life!`0\"`n");
                set_module_pref("seenforest", 1);
            };
            if ($tutormsg) {
                tutor_talk("%s", $tutormsg);
            }
            break;
    }
    return $args;
}

/**
 * Output a formatted tutor message.
 *
 * @param mixed ...$args Arguments for sprintf formatting.
 */
function tutor_talk(): void
{
    $output = Output::getInstance();

    $output->rawOutput("<style type='text/css'>
                .tutor {
                        background-color: #444444;
                        border-color: #0099ff;
                        border-style: double;
                        border-width: medium;
                        color: #CCCCCC;
                }
                .tutor .colDkBlue   { color: #0000B0; }
                .tutor .colDkGreen   { color: #00B000; }
                .tutor .colDkCyan   { color: #00B0B0; }
                .tutor .colDkRed     { color: #B00000; }
                .tutor .colDkMagenta { color: #B000CC; }
                .tutor .colDkYellow  { color: #B0B000; }
                .tutor .colDkWhite   { color: #B0B0B0; }
                .tutor .colLtBlue   { color: #0000FF; }
                .tutor .colLtGreen   { color: #00FF00; }
                .tutor .colLtCyan   { color: #00FFFF; }
                .tutor .colLtRed     { color: #FF0000; }
                .tutor .colLtMagenta { color: #FF00FF; }
                .tutor .colLtYellow  { color: #FFFF00; }
                .tutor .colLtWhite   { color: #FFFFFF; }
                .tutor .colLtBlack   { color: #999999; }
                .tutor .colDkOrange  { color: #994400; }
                .tutor .colLtOrange  { color: #FF9900; }
                </style>");

    $args = func_get_args();
    $args[0] = translate($args[0]);
    $text = call_user_func_array('sprintf', $args);
    $output->rawOutput("<div class='tutor'>");
    $output->rawOutput(Translator::clearButton() . $output->appoencode($text));
    $output->rawOutput("</div>");
}

/**
 * Run a module-specific event.
 */
function tutor_runevent(string $type): void
{
}

/**
 * Display help information for new players.
 */
function tutor_run(): void
{
    global $session;

    $output = Output::getInstance();

    $op = httpget("op");
    $city = Settings::getInstance()->getSetting("villagename", LOCATION_FIELDS); // name of capital city
    $iname = Settings::getInstance()->getSetting("innname", LOCATION_INN); // name of capital's inn
    $age = $session['user']['age'];

    if ($op == "helpfiles") {
        page_header("Help!");
        $output->output("`%`c`bHelp Me, I'm Lost!`b`c`n");
        $output->output("`@Feeling lost?`n`n");
        $output->output("`#Legend of the Green Dragon started out small, but with time it has collected many new things to explore.`n`n");
        $output->output("To a newcomer, it can be a little bit daunting.`n`n");
        $output->output("To help new players, the Central staff created Eibwen, the imp.");
        $output->output("He's the little blue guy who told you to buy weapons when you first joined, and helped you choose a race.");
        $output->output("But what happens next, where should you go, and what are all the doors, alleys, and shops for?`n`n");
        $output->output("First of all: The game is about discovery and adventure.");
        $output->output("For this reason, you won't find all the answers to every little question.");
        $output->output("For most things, you should read the FAQs, or just try them and see.`n`n");
        $output->output("But we recognize that some things aren't at all obvious.");
        $output->output("So while we won't tell you what everything does, we've put together a list of things that you might want to try first, and that new players commonly ask us.`n`n");
        $output->output("Please understand that these hints are spoilers.");
        $output->output("If you'd rather discover on your own, don't read any further.`n`n");
        $output->output("`%What are all those things in my Vital Info, and Personal Info, I'm confused?");
        $output->output("A lot of it you don't need to worry about for the most part.");
        $output->output("The ones you should watch carefully are your hitpoints, and your experience.");
        $output->output("Ideally, you should keep that hitpoint bar green.");
        $output->output("And beware if it begins to turn yellow, or worse still, red.");
        $output->output("That tells you that death is near.");
        $output->output("Sometimes running would be smarter than risking death.");
        $output->output("Perhaps there's someone close by who can help you feel better.`n`n");
        $output->output("Lower down is the experience bar, which starts all red, and will gradually fill up with white.");
        $output->output("Wait until it goes blue before you challenge your master.");
        $output->output("If you can't see a blue bar, you aren't ready yet!`n`n");
        $output->output("Looking for someone you know?");
        $output->output("The List Warriors area will tell you if your friend is online right now or not.");
        $output->output("If they are, Ye Olde Mail is a good way to contact them.`n`n");
        $output->output("What are gems for?");
        $output->output("Hang onto these and be careful how you spend them.");
        $output->output("There are some things that you can only obtain with gems.`n`n");
        $output->output("Have you been into %s, in %s? Perhaps you'd like to try a drink, listen to some entertainment, or chat to people.", $iname, $city);
        $output->output("It's also a good idea to get to know the characters in the %s, because they can be quite helpful to a young warrior.", $iname);
        $output->output("You might even decide that sleeping in %s would be safer than in the fields.`n`n", $iname);
        $output->output("Travelling can be dangerous.");
        $output->output("Make sure you've placed your valuables somewhere safe, and that you're feeling healthy before you leave.`n`n");
        $output->output("Hungry, tired, feeling adventurous, or looking for a pet?");
        $output->output("The Spa, the Kitchen, the Tattoo Parlor, and the Stables are all places you might want to visit.");
        $output->output("These things are just some of the shops in different towns.");
        $output->output("Some of them give turns, charm or energy, and some take it away.`n`n");
        $output->output("Where's the dragon?");
        $output->output("They all ask this.");
        $output->output("You'll see her when you are ready to fight her, and not before, and you will need to be patient and build your strength while you wait.`n`n");
        $output->output("`QIf you have any questions which are not covered in the FAQ, you may wish to Petition for Help - bear in mind that the staff won't give you the answer if it will spoil the game for you.");
        villagenav();
        page_footer();
    }
}
