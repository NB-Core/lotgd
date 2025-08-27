<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\TableDescriptor;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class TableDescriptorTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$describe_rows = [];
        Database::$keys_rows = [];
        Database::$full_columns_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [[['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']]];
    }

    public function testDefaultZeroIsDetected(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'noaddskillpoints',
                'Type' => 'tinyint unsigned',
                'Null' => 'NO',
                'Default' => '0',
                'Extra' => '',
                'Collation' => null,
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertSame('0', $descriptor['noaddskillpoints']['default']);
    }

    public function testDefaultNullIsDetected(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'somecolumn',
                'Type' => 'int',
                'Null' => 'YES',
                'Default' => 'NULL',
                'Extra' => '',
                'Collation' => null,
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $expected = [
            'name' => 'somecolumn',
            'type' => 'int',
            'null' => true,
            'default' => null,
        ];

        $this->assertSame($expected, $descriptor['somecolumn']);
    }

    public function testDescriptorCreateSqlGeneratesDefaultNull(): void
    {
        $descriptor = [
            'name' => 'somecolumn',
            'type' => 'int',
            'null' => true,
            'default' => null,
        ];

        $sql = TableDescriptor::descriptorCreateSql($descriptor);
        $this->assertStringContainsString('DEFAULT NULL', $sql);
    }

    public function testDescriptorCreateSqlUnquotedExpressionDefault(): void
    {
        $descriptor = [
            'name' => 'created',
            'type' => 'datetime',
            'default' => 'CURRENT_TIMESTAMP',
        ];

        $sql = TableDescriptor::descriptorCreateSql($descriptor);
        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql);
    }

    public function testDescriptorCreateSqlUnquotedExpressionWithArgumentsDefault(): void
    {
        $descriptor = [
            'name' => 'created',
            'type' => 'datetime',
            'default' => 'CURRENT_TIMESTAMP(6)',
        ];

        $sql = TableDescriptor::descriptorCreateSql($descriptor);
        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP(6)', $sql);
    }

    public function testSynctableReturnsOneWhenTableCreated(): void
    {
        Database::$tableExists = false;
        $descriptor = [
            'id' => ['name' => 'id', 'type' => 'int'],
        ];

        $this->assertSame(1, TableDescriptor::synctable('dummy', $descriptor));
        Database::$tableExists = true;
    }

    public function testCollationIsCaptured(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$table_status_rows = [['Collation' => 'latin1_swedish_ci']];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertSame('latin1_swedish_ci', $descriptor['body']['collation']);
        $this->assertSame('latin1_swedish_ci', $descriptor['collation']);
    }

    public function testCollationWithoutUnderscoreDoesNotSetCharset(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4',
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertArrayNotHasKey('charset', $descriptor['body']);
        $sql = TableDescriptor::descriptorCreateSql($descriptor['body']);
        $this->assertStringNotContainsString('CHARACTER SET', $sql);
    }

    public function testTableStatusCollationWithoutUnderscoreDoesNotSetCharset(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$table_status_rows = [['Collation' => 'binary']];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertSame('binary', $descriptor['collation']);
        $this->assertArrayNotHasKey('charset', $descriptor);
    }

    public function testTableNameWithUnderscoreMatchesExactly(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'id',
                'Type' => 'int',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => null,
            ],
        ];
        Database::$table_status_rows = [
            ['Name' => 'dummyXtable', 'Collation' => 'latin1_swedish_ci'],
            ['Name' => 'dummy_table', 'Collation' => 'utf8mb4_unicode_ci'],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy_table');
        $this->assertSame('utf8mb4_unicode_ci', $descriptor['collation']);
    }

    public function testTableCreateFromDescriptorRejectsUnknownCollation(): void
    {
        Database::$collation_rows = [[]];
        $descriptor = [
            'collation' => 'utf16',
            'id' => ['name' => 'id', 'type' => 'int'],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::tableCreateFromDescriptor('dummy', $descriptor);
    }

    public function testSynctableAltersTableCollation(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'latin1_swedish_ci']];
        Database::$collation_rows = [
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
            [['Collation' => 'utf8mb4_bin', 'Charset' => 'utf8mb4']],
        ];
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'body' => ['name' => 'body', 'type' => 'text', 'default' => null],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );
    }

    public function testSynctableNoChangeWhenSchemaMatches(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'latin1_swedish_ci']];
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'body' => ['name' => 'body', 'type' => 'text', 'default' => null],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );

        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [[['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']]];
        Database::$lastSql = '';
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringNotContainsString('CHANGE', Database::$lastSql);
    }

    public function testSynctableNoChangeWithoutCollationInDescriptor(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$lastSql = '';
        $descriptor = [
            'body' => ['name' => 'body', 'type' => 'text', 'default' => null],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringNotContainsString('CHANGE', Database::$lastSql);
    }

    public function testSynctableAltersOnlyMismatchedColumn(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'title',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [[['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']]];
        Database::$lastSql = '';
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'title' => ['name' => 'title', 'type' => 'text', 'default' => null],
            'body' => ['name' => 'body', 'type' => 'text', 'default' => null],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringNotContainsString(
            'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );
        $this->assertStringContainsString(
            'CHANGE body body text NOT NULL DEFAULT NULL  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );
        $this->assertStringNotContainsString('CHANGE title', Database::$lastSql);
    }

    public function testSynctableDerivesCollationFromCharset(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [
            [],
            [['Collation' => 'latin1_swedish_ci']],
            [['Collation' => 'latin1_swedish_ci', 'Charset' => 'latin1']],
        ];
        $descriptor = [
            'charset' => 'latin1',
            'body' => ['name' => 'body', 'type' => 'text'],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci',
            Database::$lastSql
        );
    }

    public function testSynctablePreservesExplicitColumnCollation(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'title',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_bin',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'latin1_swedish_ci']];
        Database::$collation_rows = [
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
        ];
        Database::$lastSql = '';
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'title' => ['name' => 'title', 'type' => 'text', 'default' => null],
            'body' => [
                'name' => 'body',
                'type' => 'text',
                'collation' => 'utf8mb4_bin',
                'default' => null,
            ],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );
        $this->assertStringContainsString(
            'CHANGE body body text NOT NULL DEFAULT NULL COLLATE utf8mb4_bin',
            Database::$lastSql
        );
        Database::$full_columns_rows = [
            [
                'Field' => 'title',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_bin',
            ],
        ];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
            [['Collation' => 'utf8mb4_bin', 'Charset' => 'utf8mb4']],
        ];
        Database::$lastSql = '';
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringNotContainsString('CHANGE body body text', Database::$lastSql);
    }

    public function testSynctableRejectsUnknownCollation(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [[]];
        $descriptor = [
            'collation' => 'utf16',
            'body' => ['name' => 'body', 'type' => 'text', 'default' => null],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::synctable('dummy', $descriptor);
    }

    public function testSynctableRejectsUnknownColumnCollation(): void
    {
        Database::$full_columns_rows = [];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
            [],
        ];
        $descriptor = [
            'collation' => 'utf8mb4_unicode_ci',
            'body' => [
                'name' => 'body',
                'type' => 'text',
                'collation' => 'mystery_collation',
                'default' => null,
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::synctable('dummy', $descriptor);
    }

    public function testTableCreateFromDescriptorRejectsMismatchedTableCharsetAndCollation(): void
    {
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'latin1_swedish_ci',
            'id' => ['name' => 'id', 'type' => 'int'],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::tableCreateFromDescriptor('dummy', $descriptor);
    }

    public function testTableCreateFromDescriptorRejectsMismatchedColumnCharsetAndCollation(): void
    {
        Database::$collation_rows = [
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
            [['Collation' => 'latin1_swedish_ci', 'Charset' => 'latin1']],
        ];
        $descriptor = [
            'id' => [
                'name' => 'id',
                'type' => 'text',
                'charset' => 'utf8mb4',
                'collation' => 'latin1_swedish_ci',
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::tableCreateFromDescriptor('dummy', $descriptor);
    }

    public function testTableCreateFromDescriptorRejectsUnknownColumnCollation(): void
    {
        Database::$collation_rows = [
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
            [],
        ];
        $descriptor = [
            'collation' => 'utf8mb4_unicode_ci',
            'id' => [
                'name' => 'id',
                'type' => 'text',
                'collation' => 'mystery_collation',
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::tableCreateFromDescriptor('dummy', $descriptor);
    }

    public function testSynctableRejectsMismatchedTableCharsetAndCollation(): void
    {
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'latin1_swedish_ci',
            'id' => ['name' => 'id', 'type' => 'int'],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::synctable('dummy', $descriptor);
    }

    public function testSynctableRejectsMismatchedColumnCharsetAndCollation(): void
    {
        Database::$collation_rows = [
            [['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']],
            [['Collation' => 'latin1_swedish_ci', 'Charset' => 'latin1']],
        ];
        $descriptor = [
            'id' => [
                'name' => 'id',
                'type' => 'text',
                'charset' => 'utf8mb4',
                'collation' => 'latin1_swedish_ci',
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::synctable('dummy', $descriptor);
    }

    public function testTableCreateFromDescriptorRejectsAmbiguousBinaryCollation(): void
    {
        Database::$collation_rows = [[
            ['Collation' => 'binary', 'Charset' => 'latin1'],
            ['Collation' => 'binary', 'Charset' => 'utf8mb4'],
        ]];
        $descriptor = [
            'collation' => 'binary',
            'id' => ['name' => 'id', 'type' => 'int'],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::tableCreateFromDescriptor('dummy', $descriptor);
    }

    public function testSynctableRejectsAmbiguousBinaryCollation(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [[
            ['Collation' => 'binary', 'Charset' => 'latin1'],
            ['Collation' => 'binary', 'Charset' => 'utf8mb4'],
        ]];
        $descriptor = [
            'collation' => 'binary',
            'body' => ['name' => 'body', 'type' => 'text', 'default' => null],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::synctable('dummy', $descriptor);
    }

    public function testUnknownCharsetWithoutDefaultCollationThrows(): void
    {
        Database::$collation_rows = [[], []];
        $descriptor = [
            'charset' => 'mystery_charset',
            'id' => ['name' => 'id', 'type' => 'int'],
        ];
        $this->expectException(\InvalidArgumentException::class);
        TableDescriptor::tableCreateFromDescriptor('dummy', $descriptor);
    }
}
