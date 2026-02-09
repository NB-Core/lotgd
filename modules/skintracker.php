<?php

declare(strict_types=1);

// addnews ready
// mail ready
// translator ready

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\MySQL\Database;
use Lotgd\Template;
use Lotgd\Translator;
use Lotgd\Output;
use Lotgd\AssetManifest;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;

function skintracker_getmoduleinfo(): array
{
    $info = array(
        "name" => "Skin Tracker",
        "version" => "1.0",
        "author" => "NB-Core",
        "category" => "Administrative",
        "download" => "core_module",
        "settings" => array(
            "Skin Tracker Settings,title",
        ),
        "prefs" => array(
            "Skin Tracker,title",
            "last_skin" => "Last used skin/template,viewonly",
        ),
    );
    return $info;
}

function skintracker_install(): bool
{
    module_addhook("player-login");
    module_addhook("superuser");
    return true;
}

function skintracker_uninstall(): bool
{
    return true;
}

function skintracker_dohook(string $hookname, array $args): array
{
    global $session;

    switch ($hookname) {
        case "player-login":
            $cookieTemplate = Template::getTemplateCookie();
            if ($cookieTemplate !== '') {
                $skin = Template::addTypePrefix($cookieTemplate);
            } else {
                $skin = getsetting('defaultskin', DEFAULT_TEMPLATE);
                if (strpos($skin, ':') === false) {
                    $skin = Template::addTypePrefix($skin);
                }
            }
            // Explicitly pass acctid so setModulePref takes the DB
            // write path even though $session['user']['loggedin'] is
            // not yet set at the point the player-login hook fires.
            $uid = (int) ($session['user']['acctid'] ?? 0);
            if ($uid > 0) {
                set_module_pref("last_skin", $skin, "skintracker", $uid);
            }
            break;

        case "superuser":
            if ($session['user']['superuser'] & SU_EDIT_CONFIG) {
                addnav("Skin Tracker");
                addnav(
                    "Skin Usage Statistics",
                    "runmodule.php?module=skintracker&admin=true"
                );
            }
            break;
    }

    return $args;
}

function skintracker_run(): void
{
    global $session;

    SuAccess::check(SU_EDIT_CONFIG);

    $output = Output::getInstance();

    page_header("Skin Usage Statistics");
    SuperuserNav::render();

    $jqueryPath = AssetManifest::url('jquery', 'js');
    $dataTablesCssPath = AssetManifest::url('datatables', 'css');
    $dataTablesJsPath = AssetManifest::url('datatables', 'js');

    $output->rawOutput("<link rel='stylesheet' href='{$dataTablesCssPath}'>");
    $output->rawOutput("<style>
.dataTables_length select,
.dataTables_filter input {
    color: CanvasText;
    background-color: Canvas;
    border-color: ButtonBorder;
    color-scheme: light dark;
}
</style>");
    $output->rawOutput("<script src='{$jqueryPath}'></script>");
    $output->rawOutput("<script src='{$dataTablesJsPath}'></script>");

    addnav("Navigation");
    addnav("Return to the Grotto", "superuser.php");

    output("`c`b`@Skin Usage Statistics`b`c`n");
    output("`7This table shows how many logged-in users last used each skin/template.`n`n");

    $table = Database::prefix('module_userprefs');
    $accounts = Database::prefix('accounts');
    $sql = "SELECT up.value AS skin, COUNT(*) AS user_count
            FROM {$table} up
            INNER JOIN {$accounts} a ON a.acctid = up.userid
            WHERE up.modulename = 'skintracker'
              AND up.setting = 'last_skin'
              AND up.value != ''
              AND a.locked = 0
            GROUP BY up.value
            ORDER BY user_count DESC";
    $result = Database::query($sql);

    $totalUsers = 0;
    $rows = [];
    while ($row = Database::fetchAssoc($result)) {
        $rows[] = $row;
        $totalUsers += (int) $row['user_count'];
    }

    rawoutput("<table id='skintracker-table' class='js-skintracker-table' cellpadding='3' cellspacing='0' width='100%'>");
    rawoutput("<thead><tr class='trhead'>");
    rawoutput("<th>" . translate_inline("Skin / Template") . "</th>");
    rawoutput("<th>" . translate_inline("Users") . "</th>");
    rawoutput("<th>" . translate_inline("Percentage") . "</th>");
    rawoutput("</tr></thead>");
    rawoutput("<tbody>");

    foreach ($rows as $row) {
        $skin = htmlspecialchars($row['skin'], ENT_QUOTES, 'UTF-8');
        $count = (int) $row['user_count'];
        $pct = $totalUsers > 0 ? round($count / $totalUsers * 100, 1) : 0;

        rawoutput("<tr class='trdark'>");
        rawoutput("<td>{$skin}</td>");
        rawoutput("<td>{$count}</td>");
        rawoutput("<td>{$pct}%</td>");
        rawoutput("</tr>");
    }

    rawoutput("</tbody></table>");

    output("`n`7Total tracked users: `@%s`0", $totalUsers);

    $datatableSearch = Translator::translateInline("Search");
    $datatableSearchPlaceholder = Translator::translateInline("Search skins");
    $datatableLengthMenu = Translator::translateInline("Show _MENU_ entries");
    $datatableInfo = Translator::translateInline("Showing _START_ to _END_ of _TOTAL_ entries");
    $datatableInfoEmpty = Translator::translateInline("Showing 0 to 0 of 0 entries");
    $datatableInfoFiltered = Translator::translateInline("(filtered from _MAX_ total entries)");
    $datatableEmpty = Translator::translateInline("No data available in table");
    $datatableLoading = Translator::translateInline("Loading...");
    $datatableProcessing = Translator::translateInline("Processing...");
    $datatableZeroRecords = Translator::translateInline("No matching records found");
    $datatablePaginateFirst = Translator::translateInline("First");
    $datatablePaginateLast = Translator::translateInline("Last");
    $datatableNext = Translator::translateInline("Next");
    $datatablePrevious = Translator::translateInline("Previous");
    $datatableAriaSortAscending = Translator::translateInline("Activate to sort column ascending");
    $datatableAriaSortDescending = Translator::translateInline("Activate to sort column descending");

    $output->rawOutput(
        "<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.DataTable === 'undefined') {
        return;
    }
    var dataTableConfig = {
        language: {
            search: " . json_encode($datatableSearch) . ",
            searchPlaceholder: " . json_encode($datatableSearchPlaceholder) . ",
            lengthMenu: " . json_encode($datatableLengthMenu) . ",
            info: " . json_encode($datatableInfo) . ",
            infoEmpty: " . json_encode($datatableInfoEmpty) . ",
            infoFiltered: " . json_encode($datatableInfoFiltered) . ",
            emptyTable: " . json_encode($datatableEmpty) . ",
            loadingRecords: " . json_encode($datatableLoading) . ",
            processing: " . json_encode($datatableProcessing) . ",
            zeroRecords: " . json_encode($datatableZeroRecords) . ",
            paginate: {
                first: " . json_encode($datatablePaginateFirst) . ",
                last: " . json_encode($datatablePaginateLast) . ",
                next: " . json_encode($datatableNext) . ",
                previous: " . json_encode($datatablePrevious) . "
            },
            aria: {
                sortAscending: " . json_encode($datatableAriaSortAscending) . ",
                sortDescending: " . json_encode($datatableAriaSortDescending) . "
            }
        },
        dom: 'lfrtip',
        order: [[1, 'desc']],
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        paging: true,
        searching: true,
        serverSide: false,
        processing: false
    };
    jQuery('.js-skintracker-table').DataTable(dataTableConfig);
});
</script>"
    );

    page_footer();
}
