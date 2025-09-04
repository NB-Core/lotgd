<?php

declare(strict_types=1);

/**
 * Helper for validating superuser permissions.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Modules\HookHandler;

class SuAccess
{
    /** @var int Bitmask of superuser levels required on this page */
    public static int $pageLevel = 0;

    /**
     * Ensure the current user has the given superuser level.
     * If access is denied, the request is terminated with a message.
     *
     * @param int $level Required superuser bitmask
     *
     * @return void
     */
    public static function check(int $level): void
    {
        global $session, $output;
        self::$pageLevel |= $level;
        $output->rawOutput("<!--Su_Restricted-->");
        if ($session['user']['superuser'] & $level) {
            $return = HookHandler::hook('check_su_access', ['enabled' => true, 'level' => $level]);
            if ($return['enabled']) {
                $session['user']['laston'] = date('Y-m-d H:i:s');
                return;
            }
            page_header('Oops.');
            $output->output("Looks like you're probably an admin with appropriate permissions to perform this action, but a module is preventing you from doing so.");
            $output->output('Sorry about that!');
            tlschema('nav');
            addnav('M?Return to the Mundane', 'village.php');
            tlschema();
            page_footer();
        }
        clearnav();
        $session['output'] = '';
        page_header('INFIDEL!');
        $output->output('For attempting to defile the gods, you have been smitten down!`n`n');
        $output->output("%s`\$, Overlord of Death`) appears before you in a vision, seizing your mind with his, and wordlessly telling you that he finds no favor with you.`n`n", getsetting('deathoverlord', '`$Ramius'));
        AddNews::add("`&%s was smitten down for attempting to defile the gods (they tried to hack superuser pages).", $session['user']['name']);
        debuglog("Lost {$session['user']['gold']} and " . ($session['user']['experience'] * 0.25) . " experience trying to hack superuser pages.");
        $session['user']['hitpoints'] = 0;
        $session['user']['alive'] = 0;
        $session['user']['soulpoints'] = 0;
        $session['user']['gravefights'] = 0;
        $session['user']['deathpower'] = 0;
        $session['user']['gold'] = 0;
        $session['user']['experience'] *= 0.75;
        addnav('Daily News', 'news.php');
        $sql = 'SELECT acctid FROM ' . Database::prefix('accounts') . ' WHERE (superuser&' . SU_EDIT_USERS . ')';
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            $subj = '`#%s`# tried to hack the superuser pages!';
            $subj = sprintf($subj, $session['user']['name']);
            $body = 'Bad, bad, bad %s, they are a hacker!`n`nTried to access %s from %s.';
            $body = sprintf($body, $session['user']['name'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_REFERER']);
            Mail::systemMail($row['acctid'], $subj, $body);
        }
        page_footer();
    }
}
