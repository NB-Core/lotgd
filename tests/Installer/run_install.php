<?php

declare(strict_types=1);

namespace {
    require __DIR__ . '/../Stubs/DoctrineBootstrap.php';
    require __DIR__ . '/../../autoload.php';
    require __DIR__ . '/../../src/Lotgd/Config/constants.php';
    require __DIR__ . '/../Stubs/Functions.php';

    ini_set('mysqli.default_socket', '/tmp/lotgd_mysqld.sock');
    define('IS_INSTALLER', true);
}

namespace Lotgd\Installer {
    function get_module_install_status(bool $withDb = false): array
    {
        return ['uninstalledmodules' => []];
    }
}

namespace {
    use Lotgd\Installer\Installer;
    use Lotgd\MySQL\Database;
    use Lotgd\Tests\Stubs\DoctrineBootstrap;

    [$script, $host, $user, $pass, $db] = $argv;

    global $session, $DB_USEDATACACHE, $DB_PREFIX;
    $session = ['dbinfo' => [
        'DB_HOST' => $host,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
        'DB_NAME' => $db,
        'DB_PREFIX' => '',
        'DB_USEDATACACHE' => false,
        'DB_DATACACHEPATH' => '',
    ], 'user' => ['loggedin' => false, 'superuser' => 0, 'acctid' => 0, 'restorepage' => '']];
    $DB_USEDATACACHE = 0;
    $DB_PREFIX = '';

    $connection = DoctrineBootstrap::getEntityManager()->getConnection();
    Database::setDoctrineConnection($connection);

    $installer = new Installer();

    for ($stage = 0; $stage <= 11; $stage++) {
        if (in_array($stage, [4, 5], true)) {
            continue;
        }
        $_GET = $_POST = [];
        switch ($stage) {
            case 7:
                $_POST['type'] = 'install';
                break;
            case 8:
                $_POST['modulesok'] = '1';
                $_POST['modules'] = [];
                break;
            case 10:
                $_POST['name'] = 'admin';
                $_POST['pass1'] = 'secret123';
                $_POST['pass2'] = 'secret123';
                break;
        }
        $installer->runStage($stage);
    }
}
