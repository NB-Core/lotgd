<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Http;
use Doctrine\DBAL\ParameterType;

//save module settings.
$userid = (int) Http::get('userid');
$module = (string) Http::get('module');
$post = Http::allPost();
$post = modulehook("validateprefs", $post, true, $module);
if (isset($post['validation_error']) && $post['validation_error']) {
    Translator::getInstance()->setSchema("module-$module");
    $post['validation_error'] =
        Translator::translateInline($post['validation_error']);
    Translator::getInstance()->setSchema();
    $output->output("Unable to change settings: `\$%s`0", $post['validation_error']);
} else {
    $conn = Database::getDoctrineConnection();
    $output->outputNotl("`n");
    foreach ($post as $key => $val) {
        $output->output("`\$Setting '`2%s`\$' to '`2%s`\$'`n", $key, htmlspecialchars($val, ENT_QUOTES, 'UTF-8'));
        $conn->executeStatement(
            'REPLACE INTO ' . Database::prefix('module_userprefs') . ' (modulename,userid,setting,value) VALUES (:module,:userid,:setting,:value)',
            [
                'module' => $module,
                'userid' => $userid,
                'setting' => $key,
                'value' => (string) $val,
            ],
            [
                'module' => ParameterType::STRING,
                'userid' => ParameterType::INTEGER,
                'setting' => ParameterType::STRING,
                'value' => ParameterType::STRING,
            ]
        );
    }
    $output->output("`^Preferences for module %s saved.`n", $module);
}
$op = "edit";
httpset("op", "edit");
httpset("subop", "module", true);
