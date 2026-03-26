<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\DataCache;
use Lotgd\Sanitize;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Http;
use Lotgd\Settings;

// addnews ready
// translator ready
// mail ready
use Lotgd\Output;

define("OVERRIDE_FORCED_NAV", true);

require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();
$output = Output::getInstance();
Translator::getInstance()->setSchema("translatortool");

/**
 * Return the active translator language used by translation loading.
 *
 * The loader prefers the LANGUAGE constant once translator setup has run, so
 * the save handler must invalidate caches with that same language value.
 */
function translatortoolGetActiveLanguage(): string
{
    if (defined('LANGUAGE')) {
        return (string) constant('LANGUAGE');
    }

    Translator::translatorSetup();

    return Translator::getInstance()->getLanguage();
}

/**
 * Build the exact translation cache key format used by Translator::translateLoadNamespace().
 *
 * The namespace segment uses the original namespace argument, with the same
 * overlong-value sha1 fallback that the loader applies before caching.
 */
function translatortoolBuildTranslationCacheKey(string $namespace, string $language): string
{
    $cacheNamespace = $namespace;
    if (strlen($cacheNamespace) > Sanitize::URI_MAX_LENGTH) {
        $cacheNamespace = sha1($cacheNamespace);
    }

    return 'translations-' . $cacheNamespace . '-' . $language;
}

SuAccess::check(SU_IS_TRANSLATOR);
$opRequest = Http::get('op');
$op = is_string($opRequest) ? $opRequest : '';
if ($op == "") {
    popup_header("Translator Tool");
    $uriRequest = Http::get('u');
    $uri = is_string($uriRequest) ? rawurldecode($uriRequest) : '';
    $textRequest = Http::get('t');
    $text = is_string($textRequest) ? stripslashes(rawurldecode($textRequest)) : '';
    $translation = translate_loadnamespace($uri);
    if (isset($translation[$text])) {
        $trans = $translation[$text];
    } else {
        $trans = "";
    }
    $namespace = Translator::translate("Namespace:");
    $texta = Translator::translate("Text:");
    $translation = Translator::translate("Translation:");
    $saveclose = htmlentities(Translator::translate("Save & Close"), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
    $savenotclose = htmlentities(Translator::translate("Save No Close"), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
    $output->rawOutput("<form action='translatortool.php?op=save' method='POST'>");
    $output->rawOutput("$namespace <input name='uri' value=\"" . htmlentities(stripslashes($uri), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "\" readonly><br/>");
    $output->rawOutput("$texta<br>");
    $output->rawOutput("<textarea name='text' cols='60' rows='5' readonly>" . htmlentities($text, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "</textarea><br/>");
    $output->rawOutput("$translation<br>");
    $output->rawOutput("<textarea name='trans' cols='60' rows='5'>" . htmlentities(stripslashes($trans), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . "</textarea><br/>");
    $output->rawOutput("<input type='submit' value=\"$saveclose\" class='button'>");
    $output->rawOutput("<input type='submit' value=\"$savenotclose\" class='button' name='savenotclose'>");
    $output->rawOutput("</form>");
    popup_footer();
} elseif ($op == 'save') {
    /**
     * Persist a translator edit using the same namespace normalization as the loader.
     *
     * Matching Translator::translateLoadNamespace() matters because the loader
     * queries by both page and normalized URI, while its cache key keeps the
     * original namespace argument unless it must fall back to sha1 for length.
     * Writes therefore use bound parameters instead of raw
     * interpolation so translator-entered text such as apostrophes is stored
     * safely and so save-time SQL matches lookup semantics exactly.
     */
    global $session, $logd_version;

    $uriPost = Http::post('uri');
    $rawUri = is_string($uriPost) ? $uriPost : '';
    $textPost = Http::post('text');
    $text = is_string($textPost) ? $textPost : '';
    $transPost = Http::post('trans');
    $trans = is_string($transPost) ? $transPost : '';
    $language = translatortoolGetActiveLanguage();
    $namespace = Sanitize::translatorUri($rawUri);
    $page = Sanitize::translatorPage($rawUri);
    $connection = Database::getDoctrineConnection();
    $translationsTable = Database::prefix('translations');
    $untranslatedTable = Database::prefix('untranslated');

    $rows = $connection->fetchAllAssociative(
        'SELECT tid, intext FROM ' . $translationsTable . '
            WHERE language = :language
              AND intext = :text
              AND (uri = :page OR uri = :uri)',
        [
            'language' => $language,
            'text'     => $text,
            'page'     => $page,
            'uri'      => $namespace,
        ]
    );

    $saveSucceeded = false;

    if ($trans === '') {
        $connection->executeStatement(
            'DELETE FROM ' . $translationsTable . '
                WHERE language = :language
                  AND intext = :text
                  AND (uri = :page OR uri = :uri)',
            [
                'language' => $language,
                'text'     => $text,
                'page'     => $page,
                'uri'      => $namespace,
            ]
        );
        $saveSucceeded = true;
    } else {
        if (count($rows) === 0) {
            $connection->executeStatement(
                'INSERT INTO ' . $translationsTable . '
                    (language, uri, intext, outtext, author, version)
                 VALUES (:language, :uri, :text, :translation, :author, :version)',
                [
                    'language'    => $language,
                    'uri'         => $namespace,
                    'text'        => $text,
                    'translation' => $trans,
                    'author'      => $session['user']['login'],
                    'version'     => $logd_version,
                ]
            );
        } elseif (count($rows) === 1 && $rows[0]['intext'] === $text) {
            $connection->executeStatement(
                'UPDATE ' . $translationsTable . '
                    SET author = :author, version = :version, uri = :uri, outtext = :translation
                  WHERE tid = :tid',
                [
                    'author'      => $session['user']['login'],
                    'version'     => $logd_version,
                    'uri'         => $namespace,
                    'translation' => $trans,
                    'tid'         => (int) $rows[0]['tid'],
                ]
            );
        } else {
            $matchingIds = [];
            foreach ($rows as $row) {
                // MySQL comparisons can be case-insensitive, so keep the legacy exact-text guard.
                if ($row['intext'] === $text) {
                    $matchingIds[] = (int) $row['tid'];
                }
            }

            if ($matchingIds === []) {
                $connection->executeStatement(
                    'INSERT INTO ' . $translationsTable . '
                        (language, uri, intext, outtext, author, version)
                     VALUES (:language, :uri, :text, :translation, :author, :version)',
                    [
                        'language'    => $language,
                        'uri'         => $namespace,
                        'text'        => $text,
                        'translation' => $trans,
                        'author'      => $session['user']['login'],
                        'version'     => $logd_version,
                    ]
                );
            } else {
                $connection->executeStatement(
                    'UPDATE ' . $translationsTable . '
                        SET author = :author, version = :version, uri = :uri, outtext = :translation
                      WHERE tid IN (:tids)',
                    [
                        'author'      => $session['user']['login'],
                        'version'     => $logd_version,
                        'uri'         => $namespace,
                        'translation' => $trans,
                        'tids'        => $matchingIds,
                    ],
                    [
                        'tids' => ArrayParameterType::INTEGER,
                    ]
                );
            }
        }

        $connection->executeStatement(
            'DELETE FROM ' . $untranslatedTable . '
                WHERE intext = :text
                  AND language = :language
                  AND namespace IN (:namespaces)',
            [
                'text'       => $text,
                'language'   => $language,
                'namespaces' => [$rawUri, $namespace],
            ],
            [
                'namespaces' => ArrayParameterType::STRING,
            ]
        );

        $saveSucceeded = true;
    }

    if ($saveSucceeded) {
        // The cache key must exactly mirror Translator::translateLoadNamespace() so immediate re-open shows fresh data.
        foreach (array_unique([$rawUri, $namespace, $page]) as $cacheNamespace) {
            if ($cacheNamespace === '') {
                continue;
            }

            DataCache::getInstance()->invalidatedatacache(
                translatortoolBuildTranslationCacheKey($cacheNamespace, $language)
            );
        }
    }

    $saveNotClosePost = Http::post('savenotclose');
    if ($saveSucceeded && is_string($saveNotClosePost) && $saveNotClosePost > "") {
        header("Location: translatortool.php?op=list&u=" . rawurlencode($page));
        exit();
    } elseif ($saveSucceeded) {
        popup_header("Updated");
        $output->rawOutput("<script language='javascript'>window.close();</script>");
        popup_footer();
    }
} elseif ($op == "list") {
    popup_header("Translation List");
    // Keep LANGUAGE explicit while ensuring the DBAL layer receives a typed bound parameter.
    $language = (string) LANGUAGE;
    $rows = Database::getDoctrineConnection()->fetchAllAssociative(
        'SELECT uri,count(*) AS c FROM ' . Database::prefix('translations') . ' WHERE language = :language GROUP BY uri ORDER BY uri ASC',
        ['language' => $language],
        ['language' => ParameterType::STRING]
    );
    $output->outputNotl("<form action='translatortool.php' method='GET'>", true);
    $output->outputNotl("<input type='hidden' name='op' value='list'>", true);
        $output->outputNotl("<label for='u'>", true);
        $output->output("Known Namespaces:");
        $output->outputNotl("</label>", true);
        $output->outputNotl("<select name='u' id='u'>", true);
    foreach ($rows as $row) {
        $output->outputNotl("<option value=\"" . htmlentities($row['uri']) . "\">" . htmlentities($row['uri'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')) . " ({$row['c']})</option>", true);
    }
    $output->outputNotl("</select>", true);
    $show = Translator::translateInline("Show");
    $output->outputNotl("<input type='submit' class='button' value=\"$show\">", true);
    $output->outputNotl("</form>", true);
    $ops = Translator::translateInline("Ops");
    $from = Translator::translateInline("From");
    $to = Translator::translateInline("To");
    $version = Translator::translateInline("Version");
    $author = Translator::translateInline("Author");
    $norows = Translator::translateInline("No rows found");
    $output->outputNotl("<table border='0' cellpadding='2' cellspacing='0'>", true);
    $output->outputNotl("<tr class='trhead'><td>$ops</td><td>$from</td><td>$to</td><td>$version</td><td>$author</td></tr>", true);
    $uriRequest = Http::get('u');
    $uri = is_string($uriRequest) ? $uriRequest : (string) $uriRequest;
    $translations = Database::getDoctrineConnection()->fetchAllAssociative(
        'SELECT * FROM ' . Database::prefix('translations') . ' WHERE language = :language AND uri = :uri',
        [
            'language' => $language,
            'uri'      => $uri,
        ],
        [
            'language' => ParameterType::STRING,
            'uri'      => ParameterType::STRING,
        ]
    );
    if (count($translations) > 0) {
        $i = 0;
        foreach ($translations as $row) {
            $i++;
            $output->outputNotl("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td>", true);
            $edit = Translator::translateInline("Edit");
            $output->outputNotl("<a href='translatortool.php?u=" . rawurlencode($row['uri']) . "&t=" . rawurlencode($row['intext']) . "'>$edit</a>", true);
            $output->outputNotl("</td><td>", true);
            $output->rawOutput(htmlentities($row['intext'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')));
            $output->outputNotl("</td><td>", true);
            $output->rawOutput(htmlentities($row['outtext'], ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')));
            $output->outputNotl("</td><td>", true);
            $output->outputNotl($row['version']);
            $output->outputNotl("</td><td>", true);
            $output->outputNotl($row['author']);
            $output->outputNotl("</td></tr>", true);
        }
    } else {
        $output->outputNotl("<tr><td colspan='5'>$norows</td></tr>", true);
    }
    $output->outputNotl("</table>", true);
    popup_footer();
}
