<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\TableDescriptor;
use Lotgd\MySQL\Database;
use Lotgd\Doctrine\Bootstrap as DoctrineBootstrap;
use PHPUnit\Framework\TestCase;

final class CharsetIndexConversionTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$describe_rows = [];
        Database::$keys_rows = [];
        Database::$full_columns_rows = [];
        Database::$table_status_rows = [];
        Database::$collation_rows = [];
        Database::$mockResults = [];
        Database::$tableExists = true;
        Database::$doctrineConnection = null;
        DoctrineBootstrap::$conn = null;
    }

    public function testIndexPrefixesAdjustedBeforeCharsetConversion(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'uri',
                'Type' => 'varchar(255)',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8_general_ci',
            ],
        ];
        Database::$keys_rows = [
            [
                'Key_name' => 'uri_idx',
                'Column_name' => 'uri',
                'Seq_in_index' => 1,
                'Sub_part' => null,
                'Non_unique' => 1,
            ],
        ];
        Database::$table_status_rows = [
            [
                'Collation' => 'utf8_general_ci',
                'Engine' => 'InnoDB',
            ],
        ];
        Database::$collation_rows = [
            [
                ['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4'],
            ],
        ];
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'uri' => ['name' => 'uri', 'type' => 'varchar(255)'],
            'key-uri_idx' => ['type' => 'key', 'name' => 'uri_idx', 'columns' => 'uri'],
        ];

        $changes = TableDescriptor::synctable('dummy', $descriptor);

        $this->assertSame(4, $changes);
        $sql = Database::$lastSql;
        $this->assertStringContainsString('DROP KEY uri_idx', $sql);
        $this->assertStringContainsString('ADD KEY uri_idx (uri(191))', $sql);
        $this->assertStringContainsString('CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $sql);
        $this->assertLessThan(
            strpos($sql, 'CONVERT TO CHARACTER SET'),
            strpos($sql, 'DROP KEY uri_idx')
        );
    }
}
