<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Random;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready

function example_forestreward_getmoduleinfo(): array
{
    return [
        'name' => 'Example Forest Reward',
        'version' => '1.0.0',
        'author' => 'LoGD Example',
        'category' => 'Forest Specials',
        'download' => 'core_module',
        'settings' => [
            'Example Forest Reward Settings,title',
            'reward_max' => 'Maximum gold that can be awarded each day,int|75',
            'flavor_text' => 'Flavor text shown when the stash is claimed|`2With %s at your side, you scoop up `%d gold`2 from the mossy cache.`0',
        ],
        'prefs' => [
            'Example Forest Reward User Preferences,title',
            'last_claimed' => 'Timestamp of the most recent reward claim,int|0',
        ],
    ];
}

function example_forestreward_install(): bool
{
    module_addeventhook('forest', 'return 100;');
    module_addhook('newday');

    return true;
}

function example_forestreward_uninstall(): bool
{
    return true;
}

function example_forestreward_dohook(string $hookname, array $args): array
{
    if ('newday' === $hookname) {
        set_module_pref('last_claimed', 0);
    }

    return $args;
}

function example_forestreward_runevent(string $type): void
{
    global $session;

    $output = Output::getInstance();

    Translator::getInstance()->setSchema('module-example_forestreward');

    Nav::addHeader('Navigation');
    Nav::add('Return to the Forest', 'forest.php');
    Nav::addColoredSubHeader('`@Actions`0');

    $alreadyClaimed = (int) get_module_pref('last_claimed') > 0;

    if ($alreadyClaimed) {
        $session['user']['specialinc'] = '';
        $session['user']['specialmisc'] = '';
        $output->output('`7Someone has already claimed the stash today, leaving only footprints behind.`0');
    } else {
        $session['user']['specialinc'] = 'module:example_forestreward';
        $output->output('`2A carpet of emerald moss parts to reveal a satchel of coins glinting in the dappled light.`0');
        Nav::add('Investigate the satchel', 'runmodule.php?module=example_forestreward&op=claim');
    }

    Nav::add('Leave quietly', 'forest.php');

    Translator::getInstance()->setSchema();
}

function example_forestreward_run(): void
{
    global $session;

    $output = Output::getInstance();

    $op = httpget('op');

    $session['user']['specialinc'] = '';
    $session['user']['specialmisc'] = '';

    Nav::addHeader('Navigation');
    Nav::add('Return to the Forest', 'forest.php');

    Header::pageHeader('Forest Cache');
    Nav::addColoredSubHeader('`@Actions`0');

    if ('claim' === $op) {
        $claimedAt = (int) get_module_pref('last_claimed');
        $maxReward = max(1, (int) get_module_setting('reward_max'));

        if ($claimedAt > 0) {
            $output->output('`7The cache lies empty—you already gathered its coins today.`0');
        } else {
            $goldFound = Random::eRand(1, $maxReward);
            $mountName = Translator::translate('no mount', 'module-example_forestreward');

            if ((int) $session['user']['hashorse'] > 0) {
                $connection = Database::getDoctrineConnection();
                $table = Database::prefix('mounts');
                $sql = "SELECT mountname FROM {$table} WHERE mountid = :mountId";
                $statement = $connection->prepare($sql);
                $statement->bindValue('mountId', (int) $session['user']['hashorse'], \Doctrine\DBAL\ParameterType::INTEGER);
                $result = $statement->executeQuery();
                $foundName = $result->fetchOne();

                if ($foundName !== false && $foundName !== null && $foundName !== '') {
                    $mountName = $foundName;
                }
            }

            $session['user']['gold'] += $goldFound;
            set_module_pref('last_claimed', time());
            debuglog("Collected {$goldFound} gold from example_forestreward (mount: {$mountName})");

            $flavor = get_module_setting('flavor_text');
            $output->outputNotl($flavor, $mountName, $goldFound);
        }
    } else {
        $output->output('`7The forest is still and silent.`0');
    }

    Nav::add('Leave quietly', 'forest.php');

    Footer::pageFooter();
}
