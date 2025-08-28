<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;
use Lotgd\MySQL\TableDescriptor;

use function dirname;

final class Version20250724000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema based on install/data/tables.php';
    }

    public function up(Schema $schema): void
    {
        require_once dirname(__DIR__) . '/src/Lotgd/Config/constants.php';

        Database::setDoctrineConnection($this->connection);
        require_once dirname(__DIR__) . '/install/data/tables.php';
        require_once dirname(__DIR__) . '/lib/tabledescriptor.php';

        $tables = get_all_tables();
        foreach ($tables as $name => $descriptor) {
            $full = Database::prefix($name);
            if (Database::tableExists($full)) {
                TableDescriptor::synctable($full, $descriptor);
            } else {
                $sql = TableDescriptor::tableCreateFromDescriptor($full, $descriptor);
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        require_once dirname(__DIR__) . '/src/Lotgd/Config/constants.php';

        Database::setDoctrineConnection($this->connection);
        require_once dirname(__DIR__) . '/install/data/tables.php';
        $tables = array_keys(get_all_tables());
        foreach ($tables as $name) {
            $this->addSql('DROP TABLE IF EXISTS ' . Database::prefix($name));
        }
    }
}
