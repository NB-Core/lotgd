<?php

declare(strict_types=1);

use Lotgd\Output;
use Lotgd\MySQL\Database;

$display = '';
$sql = "SELECT output FROM " . Database::prefix("accounts_output") . " WHERE acctid=" . (int)$userid;
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
if (empty($row) || !isset($row['output']) || $row['output'] == '') {
        $out = new Output();
    $text = $out->appoencode("`\$This user has had his navs fixed OR has an empty page stored. Nothing can be displayed to you -_-`0");
    $display = "<html><head><link href=\"templates/common/colors.css\" rel=\"stylesheet\" type=\"text/css\"></head><body style='background-color: #000000'>$text</body></html>";
} else {
    $display = gzuncompress($row['output']);
}
echo str_replace('.focus();', '.blur();', str_replace('<iframe src=', '<iframe Xsrc=', $display));
exit(0);
