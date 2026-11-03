<?php
// Write a comment the way the core does (Commentary::injectRawComment), from outside a page.
declare(strict_types=1);
[$root, $section, $author, $text] = array_slice($argv, 1);
chdir($root);
require $root . '/autoload.php';
\Lotgd\MySQL\Database::getDoctrineConnection();
\Lotgd\Settings::setInstance(new \Lotgd\Settings('settings'));
\Lotgd\Commentary::injectRawComment($section, (int) $author, $text);
