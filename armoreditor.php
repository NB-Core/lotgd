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
function armorEditorInteger(mixed $value, int $minimum, int $maximum): ?int
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
Translator::getInstance()->setSchema('armor');
Header::pageHeader('Armor Editor');

$connection = Database::getDoctrineConnection();
$armorlevel = armorEditorInteger(Http::get('level'), 0, PHP_INT_MAX) ?? 0;
$getOp = Http::get('op');
$op = is_string($getOp) && in_array($getOp, ['edit', 'add'], true) ? $getOp : '';
$postedOp = Http::post('op');
if (is_string($postedOp) && in_array($postedOp, ['save', 'del'], true)) {
    $op = $postedOp;
}

if (!isset($session['armor_editor_csrf']) || !is_string($session['armor_editor_csrf']) || $session['armor_editor_csrf'] === '') {
    $session['armor_editor_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $session['armor_editor_csrf'];
$csrfField = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

SuperuserNav::render();
Nav::add('Armor Editor');
Nav::add('Armor Editor Home', "armoreditor.php?level=$armorlevel");
Nav::add('Add armor', "armoreditor.php?op=add&level=$armorlevel");

$values = [1 => 48, 225, 585, 990, 1575, 2250, 2790, 3420, 4230, 5040, 5850, 6840, 8010, 9000, 10350];
$output->output('`&<h3>Armor for %s Dragon Kills</h3>`0', $armorlevel, true);
$armorarray = [
    'Armor,title',
    'armorid' => 'Armor ID,hidden',
    'armorname' => 'Armor Name',
    'defense' => 'Defense,range,1,15,1',
];

if ($op === 'edit' || $op === 'add') {
    if ($op === 'edit') {
        $id = armorEditorInteger(Http::get('id'), 1, PHP_INT_MAX);
        $row = $id === null ? false : $connection->executeQuery(
            'SELECT * FROM ' . Database::prefix('armor') . ' WHERE armorid = :armorId',
            ['armorId' => $id],
            ['armorId' => ParameterType::INTEGER]
        )->fetchAssociative();
        if ($row === false) {
            debuglog('Rejected armor editor lookup with an invalid or unknown armor ID.');
            http_response_code(400);
            $op = '';
        }
    } else {
        $row = $connection->executeQuery(
            'SELECT max(defense+1) AS defense FROM ' . Database::prefix('armor') . ' WHERE level = :level',
            ['level' => $armorlevel],
            ['level' => ParameterType::INTEGER]
        )->fetchAssociative();
    }
    if ($op !== '') {
        $output->rawOutput("<form action='armoreditor.php?level=$armorlevel' method='POST'>");
        $output->rawOutput("<input type='hidden' name='op' value='save'><input type='hidden' name='csrf_token' value='$csrfField'>");
        Nav::add('', "armoreditor.php?level=$armorlevel");
        Forms::showForm($armorarray, $row ?: []);
        $output->rawOutput('</form>');
    }
} elseif ($op === 'del' || $op === 'save') {
    $postedCsrf = Http::post('csrf_token');
    if (!is_string($postedCsrf) || !hash_equals($csrfToken, $postedCsrf)) {
        debuglog('Rejected armor editor state change with an invalid CSRF token.');
        http_response_code(400);
    } elseif ($op === 'del') {
        $id = armorEditorInteger(Http::post('id'), 1, PHP_INT_MAX);
        if ($id === null) {
            debuglog('Rejected armor deletion with an invalid armor ID.');
            http_response_code(400);
        } else {
            $connection->executeStatement(
                'DELETE FROM ' . Database::prefix('armor') . ' WHERE armorid = :armorId',
                ['armorId' => $id],
                ['armorId' => ParameterType::INTEGER]
            );
        }
    } else {
        $armorid = armorEditorInteger(Http::post('armorid'), 0, PHP_INT_MAX);
        $defense = armorEditorInteger(Http::post('defense'), 1, count($values));
        $armorname = Http::post('armorname');
        if ($armorid === null || $defense === null || !is_string($armorname) || $armorname === '') {
            debuglog('Rejected armor save with malformed editor fields.');
            http_response_code(400);
        } else {
            $params = ['level' => $armorlevel, 'defense' => $defense, 'name' => $armorname, 'value' => $values[$defense]];
            $types = ['level' => ParameterType::INTEGER, 'defense' => ParameterType::INTEGER, 'name' => ParameterType::STRING, 'value' => ParameterType::INTEGER];
            if ($armorid > 0) {
                $params['armorId'] = $armorid;
                $types['armorId'] = ParameterType::INTEGER;
                unset($params['level'], $types['level']);
                $connection->executeStatement(
                    'UPDATE ' . Database::prefix('armor') . ' SET armorname = :name, defense = :defense, value = :value WHERE armorid = :armorId',
                    $params,
                    $types
                );
            } else {
                $connection->executeStatement(
                    'INSERT INTO ' . Database::prefix('armor') . ' (level, defense, armorname, value) VALUES (:level, :defense, :name, :value)',
                    $params,
                    $types
                );
            }
        }
    }
    $op = '';
}

if ($op === '') {
    $row = $connection->executeQuery('SELECT max(level+1) AS level FROM ' . Database::prefix('armor'))->fetchAssociative();
    $max = armorEditorInteger($row['level'] ?? null, 0, PHP_INT_MAX) ?? 0;
    for ($i = 0; $i <= $max; $i++) {
        Nav::add($i === 1 ? ['Armor for %s DK', $i] : ['Armor for %s DKs', $i], "armoreditor.php?level=$i");
    }
    $result = $connection->executeQuery(
        'SELECT * FROM ' . Database::prefix('armor') . ' WHERE level = :level ORDER BY defense',
        ['level' => $armorlevel],
        ['level' => ParameterType::INTEGER]
    );
    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $output->rawOutput('<tr class="trhead"><td>Ops</td><td>Name</td><td>Cost</td><td>Defense</td><td>Level</td></tr>');
    $i = 0;
    while (($row = $result->fetchAssociative()) !== false) {
        $rowId = armorEditorInteger($row['armorid'] ?? null, 1, PHP_INT_MAX);
        if ($rowId === null) {
            continue;
        }
        $edit = Translator::translateInline('Edit');
        $delete = Translator::translateInline('Del');
        $output->rawOutput("<tr class='" . ($i++ % 2 ? 'trdark' : 'trlight') . "'><td>[<a href='armoreditor.php?op=edit&amp;id=$rowId&amp;level=$armorlevel'>$edit</a>|");
        $output->rawOutput("<form method='POST' action='armoreditor.php?level=$armorlevel' style='display:inline'><input type='hidden' name='op' value='del'><input type='hidden' name='id' value='$rowId'><input type='hidden' name='csrf_token' value='$csrfField'><button type='submit'>$delete</button></form>]</td>");
        Nav::add('', "armoreditor.php?op=edit&id=$rowId&level=$armorlevel");
        $output->rawOutput('<td>');
        $output->outputNotl((string) $row['armorname']);
        foreach (['value', 'defense', 'level'] as $column) {
            $output->rawOutput('</td><td>');
            $output->outputNotl((string) $row[$column]);
        }
        $output->rawOutput('</td></tr>');
    }
    $output->rawOutput('</table>');
}
Footer::pageFooter();
