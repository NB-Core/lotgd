<?php

declare(strict_types=1);

use Lotgd\Diagnostics;
use Lotgd\GameLog;
use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Sanitize;
use Lotgd\SuAccess;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready

if (!defined('DIAGNOSTICS_TEST')) {
    require_once __DIR__ . "/common.php";
}

if (!isset($output)) {
    $output = Output::getInstance();
}

Translator::getInstance()->setSchema("diagnostics");

// Stricter than the other operational pages, which use SU_EDIT_CONFIG: this one
// puts their contents plus IP addresses plus environment detail on one screen.
SuAccess::check(SU_MEGAUSER);

$hours = Diagnostics::normalizeHours(Http::get('hours'));
$severity = Diagnostics::normalizeSeverity(Http::get('severity'));
$severityParam = $severity === null ? '' : '&severity=' . urlencode($severity);

Header::pageHeader("Diagnostics");
SuperuserNav::render();

/**
 * Register every link this page offers.
 *
 * ForcedNavigation compares the incoming URI against the allowed list built by
 * the previous render, so a window or filter that is not registered here sends
 * the operator to badnav.php instead. The query strings must be built exactly
 * as the links will request them.
 */
Nav::add("Operations");
Nav::add("Refresh", "diagnostics.php?hours=$hours$severityParam");

Nav::add("Time window");
foreach (Diagnostics::WINDOWS as $windowOption) {
    Nav::add(
        Diagnostics::windowLabel($windowOption),
        "diagnostics.php?hours=$windowOption$severityParam"
    );
}

Nav::add("Severity");
Nav::add("All severities", "diagnostics.php?hours=$hours");
foreach ([GameLog::SEVERITY_INFO, GameLog::SEVERITY_WARNING, GameLog::SEVERITY_ERROR, GameLog::SEVERITY_DEBUG] as $severityOption) {
    Nav::add(
        "Severity: " . ucfirst($severityOption),
        "diagnostics.php?hours=$hours&severity=" . urlencode($severityOption)
    );
}

Nav::add("Related");
Nav::add("Gamelog Viewer", "gamelog.php");
Nav::add("Debug Analysis", "debug.php");
Nav::add("View Log Files", "logviewer.php");

Output::addHeadMarkup(
    "<style>"
    . ".diagnostics details{margin:0 0 6px 0;}"
    . ".diagnostics summary{cursor:pointer;font-weight:bold;}"
    . ".diagnostics table{margin-top:4px;}"
    . "</style>"
);

/**
 * Render a table of already-formatted rows.
 *
 * Headers are source strings and are translated here in the `diagnostics`
 * namespace; the cells are log data and go through outputNotl() without the
 * privileged flag, so appoencode() escapes HTML while leaving the game's colour
 * codes intact -- the same treatment gamelog.php gives its messages.
 *
 * The function_exists guard is there because this page is pulled in with
 * require by its tests.
 */
if (!function_exists('diagnosticsTable')) {
    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    function diagnosticsTable(array $headers, array $rows): void
    {
        $output = Output::getInstance();

        if ($rows === []) {
            $output->output("`7Nothing in this window.`0`n");

            return;
        }

        $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1'><tr class='trhead'>");
        foreach ($headers as $header) {
            $output->rawOutput("<td>");
            $output->outputNotl((string) Translator::translate($header, 'diagnostics'));
            $output->rawOutput("</td>");
        }
        $output->rawOutput("</tr>");

        $alternate = true;
        foreach ($rows as $row) {
            $alternate = !$alternate;
            $output->rawOutput("<tr class='" . ($alternate ? 'trdark' : 'trlight') . "'>");
            foreach ($row as $cell) {
                $output->rawOutput("<td valign='top'>");
                $output->outputNotl($cell);
                $output->rawOutput("</td>");
            }
            $output->rawOutput("</tr>");
        }
        $output->rawOutput("</table>");
    }
}

if (!function_exists('diagnosticsStatusColour')) {
    function diagnosticsStatusColour(string $status): string
    {
        return match ($status) {
            'error' => '`4',
            'warn' => '`$',
            'unknown' => '`7',
            default => '`@',
        };
    }
}

if (!function_exists('diagnosticsSeverityColour')) {
    function diagnosticsSeverityColour(string $severity): string
    {
        return match ($severity) {
            GameLog::SEVERITY_ERROR => '`4',
            GameLog::SEVERITY_WARNING => '`$',
            GameLog::SEVERITY_DEBUG => '`#',
            default => '`@',
        };
    }
}

$diagnostics = new Diagnostics();
$counts = $diagnostics->counts($hours, $severity);

$output->rawOutput("<div class='diagnostics'>");
$output->outputNotl(
    "`n`b`&%s`0`b `7(%s)`0`n`n",
    Diagnostics::windowLabel($hours),
    $severity === null ? 'all severities' : 'severity: ' . $severity
);

// ---------------------------------------------------------------- snapshot --
$output->outputNotl("`b`^%s`0`b`n", (string) Translator::translate('Runtime', 'diagnostics'));
foreach ($diagnostics->runtime() as $group => $entries) {
    $rows = [];
    foreach ($entries as $entry) {
        // The class hands out source strings and their arguments rather than
        // finished sentences, so the whole sentence stays one translatable unit
        // instead of being concatenated around the data. A row marked as data
        // (a version, a path, a number) is passed through untouched.
        if ($entry['args'] !== []) {
            // The page's schema is already `diagnostics` (set at the top), and
            // sprintfTranslate() reads the active namespace -- its schema form
            // takes a `true, '<schema>'` prefix, not a bare namespace argument.
            $value = (string) Translator::sprintfTranslate(
                $entry['value'],
                ...array_map(static fn ($arg): string => Sanitize::sanitize((string) $arg), $entry['args'])
            );
        } elseif ($entry['translate']) {
            $value = (string) Translator::translate($entry['value'], 'diagnostics');
        } else {
            $value = Sanitize::sanitize($entry['value']);
        }

        $rows[] = [
            "`7" . Sanitize::sanitize((string) Translator::translate($entry['label'], 'diagnostics')) . "`0",
            diagnosticsStatusColour($entry['status']) . Sanitize::sanitize($value) . "`0",
        ];
    }
    $output->outputNotl("`n`b%s`b`n", Sanitize::sanitize((string) Translator::translate((string) $group, 'diagnostics')));
    diagnosticsTable(['Item', 'Value'], $rows);
}

// ---------------------------------------------------------------- timeline --
$timeline = $diagnostics->timeline($hours, Diagnostics::ROW_LIMIT);
$output->outputNotl(
    "`n`n`b`^%s`0`b `7(%s)`0`n",
    (string) Translator::translate('Timeline', 'diagnostics'),
    (string) Translator::translate('security events, warnings, errors and failed logins', 'diagnostics')
);
$timelineRows = [];
foreach ($timeline as $event) {
    $timelineRows[] = [
        "`v" . Sanitize::sanitize($event['at']) . "`0",
        diagnosticsSeverityColour($event['severity']) . strtoupper(Sanitize::sanitize($event['severity'])) . "`0",
        "`7" . Sanitize::sanitize($event['source']) . '/' . Sanitize::sanitize($event['category']) . "`0",
        Sanitize::sanitize($event['actor']),
        Sanitize::sanitize($event['text']),
    ];
}
diagnosticsTable(['When', 'Severity', 'Source', 'Who', 'What'], $timelineRows);

// ----------------------------------------------------------------- gamelog --
$gameLogRows = [];
foreach ($diagnostics->gameLog($hours, $severity, Diagnostics::ROW_LIMIT) as $row) {
    $rowSeverity = strtolower((string) ($row['severity'] ?? GameLog::SEVERITY_INFO));
    $gameLogRows[] = [
        "`v" . Sanitize::sanitize((string) $row['date']) . "`0",
        diagnosticsSeverityColour($rowSeverity) . strtoupper(Sanitize::sanitize($rowSeverity)) . "`0",
        "`7" . Sanitize::sanitize((string) $row['category']) . "`0",
        ((int) ($row['who'] ?? 0) === 0) ? "`7System`0" : Sanitize::sanitize((string) ($row['name'] ?? '')),
        Sanitize::sanitize((string) $row['message']),
    ];
}
$output->rawOutput("<details open><summary>");
$output->outputNotl(
    (string) Translator::sprintfTranslate(
        'Game log `7(showing %s of %s)`0',
        (string) count($gameLogRows),
        (string) ($counts['gamelog'] ?? 0)
    )
);
$output->rawOutput("</summary>");
diagnosticsTable(['When', 'Severity', 'Category', 'Who', 'Message'], $gameLogRows);
$output->rawOutput("</details>");

// ----------------------------------------------------------------- faillog --
$failLogRows = [];
foreach ($diagnostics->failLog($hours, Diagnostics::ROW_LIMIT) as $row) {
    $privileged = (int) ($row['superuser'] ?? 0) > 0;
    $failLogRows[] = [
        "`v" . Sanitize::sanitize((string) $row['date']) . "`0",
        Sanitize::sanitize((string) ($row['ip'] ?? '')),
        Sanitize::sanitize((string) ($row['login'] ?? '')),
        Sanitize::sanitize((string) ($row['name'] ?? '')),
        $privileged ? "`4yes`0" : "`7no`0",
    ];
}
$output->rawOutput("<details><summary>");
$output->outputNotl(
    (string) Translator::sprintfTranslate(
        'Failed logins `7(showing %s of %s)`0',
        (string) count($failLogRows),
        (string) ($counts['faillog'] ?? 0)
    )
);
$output->rawOutput("</summary>");
diagnosticsTable(['When', 'IP', 'Login attempted', 'Account', 'Privileged'], $failLogRows);
$output->output("`7The submitted form data is deliberately not read or shown: it contains the password that was tried.`0`n");
$output->rawOutput("</details>");

// ---------------------------------------------------------------- debuglog --
$debugLogRows = [];
foreach ($diagnostics->debugLog($hours, Diagnostics::ROW_LIMIT) as $row) {
    $debugLogRows[] = [
        "`v" . Sanitize::sanitize((string) $row['date']) . "`0",
        Sanitize::sanitize((string) ($row['actorname'] ?? '')),
        Sanitize::sanitize((string) ($row['targetname'] ?? '')),
        "`7" . Sanitize::sanitize((string) ($row['field'] ?? '')) . "`0",
        Sanitize::sanitize((string) ($row['value'] ?? '')),
        Sanitize::sanitize((string) $row['message']),
    ];
}
$output->rawOutput("<details><summary>");
$output->outputNotl(
    (string) Translator::sprintfTranslate(
        'Character audit trail `7(showing %s of %s)`0',
        (string) count($debugLogRows),
        (string) ($counts['debuglog'] ?? 0)
    )
);
$output->rawOutput("</summary>");
diagnosticsTable(['When', 'Actor', 'Target', 'Field', 'Value', 'Message'], $debugLogRows);
$output->output("`7Entries with a field are consolidated per day: a later event updates the existing row and moves its timestamp forward, so a timestamp here is the most recent activity rather than the first.`0`n");
$output->rawOutput("</details>");

// --------------------------------------------------------------- profiling --
$output->rawOutput("<details><summary>");
$output->outputNotl(
    (string) Translator::sprintfTranslate(
        'Runtimes `7(%s samples in window)`0',
        (string) ($counts['debug'] ?? 0)
    )
);
$output->rawOutput("</summary>");

if (($counts['debug'] ?? 0) === 0) {
    $output->output("`7No runtime samples. These are only collected while DEBUG mode is on.`0`n");
} else {
    foreach (['pagegentime' => 'Pages', 'hooktime' => 'Module hooks'] as $type => $label) {
        $profileRows = [];
        foreach ($diagnostics->profiling($hours, $type, 30) as $row) {
            $profileRows[] = [
                "`7" . Sanitize::sanitize((string) $row['category']) . "`0",
                Sanitize::sanitize((string) $row['subcategory']),
                (string) round((float) $row['total'], 3),
                (string) round((float) $row['mean'], 4),
                (string) (int) $row['hits'],
            ];
        }
        $output->outputNotl("`n`b%s`b`n", (string) Translator::translate($label, 'diagnostics'));
        diagnosticsTable(['Category', 'Name', 'Total s', 'Average s', 'Hits'], $profileRows);
    }
}
$output->rawOutput("</details>");

$output->rawOutput("</div>");

Footer::pageFooter();
