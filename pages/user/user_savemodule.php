<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Translator;

// save module settings.
$userid = (int) Http::get('userid');
$module = (string) Http::get('module');

/**
 * Build a strict allowlist of module preference keys so transport metadata
 * injected by the UI (for example tab state or validation markers) is never
 * written into module_userprefs.
 *
 * @var array<string,bool> $allowedPrefKeys
 */
$moduleInfo = get_module_info($module);
$allowedPrefKeys = [];
if (isset($moduleInfo['prefs']) && is_array($moduleInfo['prefs'])) {
    foreach (array_keys($moduleInfo['prefs']) as $prefKey) {
        if (is_string($prefKey) && $prefKey !== '') {
            $allowedPrefKeys[$prefKey] = true;
        }
    }
}

$post = Http::allPost();
unset($post['showFormTabIndex'], $post['validation_error']);
$post = modulehook("validateprefs", $post, true, $module);
if (isset($post['validation_error']) && $post['validation_error']) {
    Translator::getInstance()->setSchema("module-$module");
    $post['validation_error'] =
        Translator::translateInline((string) $post['validation_error']);
    Translator::getInstance()->setSchema();
    $output->output("Unable to change settings: `\$%s`0", $post['validation_error']);
} else {
    $conn = Database::getDoctrineConnection();
    $output->outputNotl("`n");
    foreach ($post as $key => $val) {
        if (!is_string($key) || !isset($allowedPrefKeys[$key])) {
            continue;
        }

        // Strict typing + htmlspecialchars require scalar/stringable-like input.
        // Skip arrays/objects from malformed payloads before formatting/output.
        if (!is_scalar($val)) {
            continue;
        }

        $value = (string) $val;
        $output->output("`\$Setting '`2%s`\$' to '`2%s`\$'`n", $key, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        $conn->executeStatement(
            'REPLACE INTO ' . Database::prefix('module_userprefs') . ' (modulename,userid,setting,value) VALUES (:module,:userid,:setting,:value)',
            [
                'module' => $module,
                'userid' => $userid,
                'setting' => $key,
                'value' => $value,
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
