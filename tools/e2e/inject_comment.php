<?php
// Write a comment the way the core does (Commentary::injectRawComment), from outside a page.
//   php inject_comment.php <game directory> <section> <author acctid> <text>
declare(strict_types=1);
if ($argc !== 5) {
    fwrite(STDERR, "usage: php inject_comment.php <game directory> <section> <author acctid> <text>\n");
    exit(2);
}
[$root, $section, $author, $text] = array_slice($argv, 1);
if (!is_file($root . '/autoload.php')) {
    fwrite(STDERR, "not a game directory: $root\n");
    exit(2);
}
if (!ctype_digit($author) || $section === '' || $text === '') {
    fwrite(STDERR, "the author is an acctid, section and text are not empty\n");
    exit(2);
}
chdir($root);
require $root . '/autoload.php';
\Lotgd\MySQL\Database::getDoctrineConnection();
\Lotgd\Settings::setInstance(new \Lotgd\Settings('settings'));
\Lotgd\Commentary::injectRawComment($section, (int) $author, $text);
