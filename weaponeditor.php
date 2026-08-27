<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\Forms;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\SuAccess;
use Lotgd\Translator;

require_once __DIR__ . '/common.php';

/**
 * Accepts a scalar decimal integer within the inclusive bounds, or returns null.
 */
function weaponEditorInteger(mixed $value, int $minimum, int $maximum): ?int
{
    if (!is_int($value) && !is_string($value)) {
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $minimum, 'max_range' => $maximum],
    ]);

    return $validated === false ? null : $validated;
}

$output = Output::getInstance();
SuAccess::check(SU_EDIT_EQUIPMENT);
Translator::getInstance()->setSchema('weapon');
Header::pageHeader('Weapon Editor');

$connection = Database::getDoctrineConnection();
$weaponlevel = weaponEditorInteger(Http::get('level'), 0, PHP_INT_MAX) ?? 0;
$getOp = Http::get('op');
$op = is_string($getOp) && in_array($getOp, ['edit', 'add'], true) ? $getOp : '';
$postedOp = Http::post('op');
if (is_string($postedOp) && in_array($postedOp, ['save', 'del'], true)) {
    $op = $postedOp;
}

if (!isset($session['weapon_editor_csrf']) || !is_string($session['weapon_editor_csrf']) || $session['weapon_editor_csrf'] === '') {
    $session['weapon_editor_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $session['weapon_editor_csrf'];
$csrfField = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

SuperuserNav::render();
Nav::add('Weapon Editor');
Nav::add('Weapon Editor Home', "weaponeditor.php?level=$weaponlevel");
Nav::add('Add a weapon', "weaponeditor.php?op=add&level=$weaponlevel");

$values = [1 => 48, 225, 585, 990, 1575, 2250, 2790, 3420, 4230, 5040, 5850, 6840, 8010, 9000, 10350];
$output->output('`&<h3>Weapons for %s Dragon Kills</h3>`0', $weaponlevel, true);
$weaponarray = [
    'Weapon,title',
    'weaponid' => 'Weapon ID,hidden',
    'weaponlevel' => 'DK Level',
    'weaponname' => 'Weapon Name',
    'damage' => 'Damage,range,1,15,1',
];

if ($op === 'edit' || $op === 'add') {
    if ($op === 'edit') {
        $id = weaponEditorInteger(Http::get('id'), 1, PHP_INT_MAX);
        $row = $id === null ? false : $connection->executeQuery(
            'SELECT * FROM ' . Database::prefix('weapons') . ' WHERE weaponid = :weaponId',
            ['weaponId' => $id],
            ['weaponId' => ParameterType::INTEGER]
        )->fetchAssociative();
        if ($row === false) {
            debuglog('Rejected weapon editor lookup with an invalid or unknown weapon ID.');
            http_response_code(400);
            $op = '';
        }
    } else {
        $row = $connection->executeQuery(
            'SELECT max(damage+1) AS damage FROM ' . Database::prefix('weapons') . ' WHERE level = :level',
            ['level' => $weaponlevel],
            ['level' => ParameterType::INTEGER]
        )->fetchAssociative();
    }
    if ($op !== '') {
        $output->rawOutput("<form action='weaponeditor.php?level=$weaponlevel' method='POST'>");
        $output->rawOutput("<input type='hidden' name='op' value='save'><input type='hidden' name='csrf_token' value='$csrfField'>");
        Nav::add('', "weaponeditor.php?level=$weaponlevel");
        Forms::showForm($weaponarray, $row ?: []);
        $output->rawOutput('</form>');
    }
} elseif ($op === 'del' || $op === 'save') {
    $postedCsrf = Http::post('csrf_token');
    if (!is_string($postedCsrf) || !hash_equals($csrfToken, $postedCsrf)) {
        debuglog('Rejected weapon editor state change with an invalid CSRF token.');
        http_response_code(400);
    } elseif ($op === 'del') {
        $id = weaponEditorInteger(Http::post('id'), 1, PHP_INT_MAX);
        if ($id === null) {
            debuglog('Rejected weapon deletion with an invalid weapon ID.');
            http_response_code(400);
        } else {
            $connection->executeStatement(
                'DELETE FROM ' . Database::prefix('weapons') . ' WHERE weaponid = :weaponId',
                ['weaponId' => $id],
                ['weaponId' => ParameterType::INTEGER]
            );
        }
    } else {
        $weaponid = weaponEditorInteger(Http::post('weaponid'), 0, PHP_INT_MAX);
        $damage = weaponEditorInteger(Http::post('damage'), 1, count($values));
        $weaponname = Http::post('weaponname');
        if ($weaponid === null || $damage === null || !is_string($weaponname) || $weaponname === '') {
            debuglog('Rejected weapon save with malformed editor fields.');
            http_response_code(400);
        } else {
            $params = ['level' => $weaponlevel, 'damage' => $damage, 'name' => $weaponname, 'value' => $values[$damage]];
            $types = ['level' => ParameterType::INTEGER, 'damage' => ParameterType::INTEGER, 'name' => ParameterType::STRING, 'value' => ParameterType::INTEGER];
            if ($weaponid > 0) {
                $params['weaponId'] = $weaponid;
                $types['weaponId'] = ParameterType::INTEGER;
                unset($params['level'], $types['level']);
                $connection->executeStatement(
                    'UPDATE ' . Database::prefix('weapons') . ' SET weaponname = :name, damage = :damage, value = :value WHERE weaponid = :weaponId',
                    $params,
                    $types
                );
            } else {
                $connection->executeStatement(
                    'INSERT INTO ' . Database::prefix('weapons') . ' (level, damage, weaponname, value) VALUES (:level, :damage, :name, :value)',
                    $params,
                    $types
                );
            }
        }
    }
    $op = '';
}

if ($op === '') {
    $row = $connection->executeQuery('SELECT max(level+1) AS level FROM ' . Database::prefix('weapons'))->fetchAssociative();
    $max = weaponEditorInteger($row['level'] ?? null, 0, PHP_INT_MAX) ?? 0;
    for ($i = 0; $i <= $max; $i++) {
        Nav::add($i === 1 ? ['Weapons for %s DK', $i] : ['Weapons for %s DKs', $i], "weaponeditor.php?level=$i");
    }
    $result = $connection->executeQuery(
        'SELECT * FROM ' . Database::prefix('weapons') . ' WHERE level = :level ORDER BY damage',
        ['level' => $weaponlevel],
        ['level' => ParameterType::INTEGER]
    );
    $ops = Translator::translateInline('Ops');
    $name = Translator::translateInline('Name');
    $cost = Translator::translateInline('Cost');
    $damage = Translator::translateInline('Damage');
    $level = Translator::translateInline('Level');
    $edit = Translator::translateInline('Edit');
    $delete = Translator::translateInline('Del');
    $deleteConfirmation = Translator::translateInline('Are you sure you wish to delete this weapon?');
    $deleteConfirmationJs = json_encode($deleteConfirmation, JSON_HEX_APOS | JSON_HEX_QUOT);
    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$name</td><td>$cost</td><td>$damage</td><td>$level</td></tr>");
    $i = 0;
    while (($row = $result->fetchAssociative()) !== false) {
        $rowId = weaponEditorInteger($row['weaponid'] ?? null, 1, PHP_INT_MAX);
        if ($rowId === null) {
            continue;
        }
        $output->rawOutput("<tr class='" . ($i++ % 2 ? 'trdark' : 'trlight') . "'><td>[<a href='weaponeditor.php?op=edit&amp;id=$rowId&amp;level=$weaponlevel'>$edit</a>|");
        $output->rawOutput("<form method='POST' action='weaponeditor.php?level=$weaponlevel' style='display:inline' onsubmit='return confirm($deleteConfirmationJs);'><input type='hidden' name='op' value='del'><input type='hidden' name='id' value='$rowId'><input type='hidden' name='csrf_token' value='$csrfField'><button type='submit'>$delete</button></form>]</td>");
        Nav::add('', "weaponeditor.php?op=edit&id=$rowId&level=$weaponlevel");
        $output->rawOutput('<td>');
        $output->outputNotl((string) $row['weaponname']);
        foreach (['value', 'damage', 'level'] as $column) {
            $output->rawOutput('</td><td>');
            $output->outputNotl((string) $row[$column]);
        }
        $output->rawOutput('</td></tr>');
    }
    $output->rawOutput('</table>');
}
Footer::pageFooter();
