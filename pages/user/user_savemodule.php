<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;

//save module settings.
$userid = (int)httpget('userid');
$module = httpget('module');
$post = httpallpost();
$post = modulehook("validateprefs", $post, true, $module);
if (isset($post['validation_error']) && $post['validation_error']) {
    Translator::getInstance()->setSchema("module-$module");
    $post['validation_error'] =
        Translator::translateInline($post['validation_error']);
    Translator::getInstance()->setSchema();
    $output->output("Unable to change settings: `\$%s`0", $post['validation_error']);
} else {
    $output->outputNotl("`n");
    foreach ($post as $key => $val) {
        $output->output("`\$Setting '`2%s`\$' to '`2%s`\$'`n", $key, htmlspecialchars($val, ENT_QUOTES, 'UTF-8'));
               $sql = "REPLACE INTO " . Database::prefix("module_userprefs") .
                       " (modulename,userid,setting,value) VALUES ('" .
                       Database::escape($module) . "',$userid,'" .
                       Database::escape($key) . "','" .
                       Database::escape($val) . "')";
        Database::query($sql);
    }
    $output->output("`^Preferences for module %s saved.`n", $module);
}
$op = "edit";
httpset("op", "edit");
httpset("subop", "module", true);
