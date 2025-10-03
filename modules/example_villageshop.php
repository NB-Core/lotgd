<?php

declare(strict_types=1);

use Doctrine\DBAL\Types\Types;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Translator;

/**
 * Example module showing how to build a simple gem shop with modern APIs.
 */
function example_villageshop_getmoduleinfo(): array
{
    return [
        'name' => 'Example Village Gem Shop',
        'version' => '1.0.0',
        'author' => 'OpenAI Assistant',
        'category' => 'Village',
        'download' => 'core_module',
        'settings' => [
            'Example Village Gem Shop Settings,title',
            'gem_cost' => 'How much gold does the gem cost?,int|500',
            'link_label' => 'Navigation link label|Example Gem Shop',
        ],
        'prefs' => [
            'Example Village Gem Shop Preferences,title',
            'purchased_today' => 'Has the player already bought their gem today?,bool|0',
        ],
    ];
}

function example_villageshop_install(): bool
{
    module_addhook('village');
    module_addhook('newday');

    return true;
}

function example_villageshop_uninstall(): bool
{
    return true;
}

function example_villageshop_dohook(string $hookname, array $args): array
{
    switch ($hookname) {
        case 'newday':
            set_module_pref('purchased_today', 0);
            break;

        case 'village':
            // Lotgd\Nav::add exposes the modern navigation helper instead of legacy addnav().
            Translator::tlschema('module_example_villageshop'); // Translator::tlschema() scopes the navigation strings.
            Nav::add('Village Shops');

            $label = get_module_setting('link_label');
            Nav::add(['Visit the %s', $label], 'runmodule.php?module=example_villageshop');
            Translator::tlschema();
            break;
    }

    return $args;
}

function example_villageshop_run(): void
{
    global $session;

    $output = Output::getInstance(); // Output::getInstance() is the modern renderer replacing legacy output() helpers.

    $op = Http::get('op');
    $op = is_string($op) ? $op : '';

    $cost = (int) get_module_setting('gem_cost');
    $label = get_module_setting('link_label');
    $purchased = (bool) get_module_pref('purchased_today');

    Translator::tlschema('module_example_villageshop'); // Translator::tlschema() scopes subsequent strings to the module schema.

    // Nav::add() translates link text automatically when the module schema is active.
    Nav::add('Navigation');
    Nav::add('Return to the village', 'village.php');

    Nav::add('Actions');
    if (! $purchased) {
        Nav::add(['Buy the daily gem for %s gold', $cost], 'runmodule.php?module=example_villageshop&op=buy');
    }

    $output->outputNotl('`c`b%s`b`c`n', $label);

    if ($op === 'buy') {
        if ($purchased) {
            $output->outputNotl('You have already purchased today\'s gem.');
            Translator::tlschema();
            return;
        }

        if ($session['user']['gold'] < $cost) {
            $output->outputNotl('You do not have enough gold to afford the gem.');
            Translator::tlschema();
            return;
        }

        $session['user']['gold'] -= $cost;
        $session['user']['gems']++;
        set_module_pref('purchased_today', 1);

        $output->outputNotl('You pay %s gold and receive a gleaming gem.', $cost);

        $message = sprintf(
            Translator::translate('Purchased the example gem for %s gold.', 'module_example_villageshop'),
            $cost
        );

        $conn = Database::getDoctrineConnection();
        $table = Database::prefix('debuglog');

        // Using Doctrine DBAL\Connection::executeStatement() to showcase modern database access for modules.
        $conn->executeStatement(
            "INSERT INTO {$table} (date, actor, target, message, field, value) VALUES (CURRENT_TIMESTAMP, :actor, :target, :message, :field, :value)",
            [
                'actor' => $session['user']['acctid'],
                'target' => 0,
                'message' => $message,
                'field' => 'example_villageshop',
                'value' => $cost,
            ],
            [
                'actor' => Types::INTEGER,
                'target' => Types::INTEGER,
                'message' => Types::STRING,
                'field' => Types::STRING,
                'value' => Types::INTEGER,
            ]
        );

        Translator::tlschema();
        return;
    }

    if ($purchased) {
        $output->outputNotl('The shopkeeper smiles, reminding you to return tomorrow for another gem.');
    } else {
        $output->outputNotl('The shopkeeper offers a single gem for %s gold.', $cost);
    }

    Translator::tlschema();
}
