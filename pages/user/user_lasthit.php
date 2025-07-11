<?php
use Lotgd\Output;
$output="";
$sql = "SELECT output FROM " . db_prefix("accounts_output") . " WHERE acctid=" . (int)$userid;
$result = db_query($sql);
$row = db_fetch_assoc($result);
if (empty($row) || !isset($row['output']) || $row['output']=='') {
        require_once("lib/output.php");
        $output = new Output();
	$text=$output->appoencode("`\$This user has had his navs fixed OR has an empty page stored. Nothing can be displayed to you -_-`0");
	$output="<html><head><link href=\"templates/common/colors.css\" rel=\"stylesheet\" type=\"text/css\"></head><body style='background-color: #000000'>$text</body></html>";
} else {
	$output=gzuncompress($row['output']);
}
echo str_replace(".focus();",".blur();",str_replace("<iframe src=","<iframe Xsrc=",$output));
exit(0);
?>
