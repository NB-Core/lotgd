<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\TableDescriptor;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
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

    public function testDescriptorCreateSqlQuotedLowercaseDefault(): void
    {
        $descriptor = [
            'name' => 'weapon',
            'type' => 'varchar(255)',
            'default' => 'Fists',
        ];

        $sql = TableDescriptor::descriptorCreateSql($descriptor);
        $this->assertStringContainsString("DEFAULT 'Fists'", $sql);
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

    public function testSynctableThrowsExceptionOnCreateFailure(): void
    {
        Database::$tableExists = false;
        $originalInstance = Database::$instance;
        Database::$instance = new class {
            public function query(string $sql)
            {
                return false;
            }

            public function error(): string
            {
                return 'creation failed';
            }
        };

        $descriptor = [
            'id' => ['name' => 'id', 'type' => 'int'],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('creation failed');

        try {
            TableDescriptor::synctable('dummy', $descriptor);
        } finally {
            Database::$instance = $originalInstance;
            Database::$tableExists = true;
        }
    }

    public function testSynctableThrowsExceptionOnAlterFailure(): void
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
        Database::$alterFail = true;

        $descriptor = [
            'id' => ['name' => 'id', 'type' => 'int'],
            'name' => ['name' => 'name', 'type' => 'int'],
        ];

        $result = null;

        try {
            $result = TableDescriptor::synctable('dummy', $descriptor);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertNull($result);
        }

        Database::$alterFail = false;
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

    public function testSynctableReplacesZeroDatetime(): void
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
            [
                'Field' => 'created',
                'Type' => 'datetime',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => null,
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [[['Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4']]];

        $mock = new class extends DbMysqli {
            public array $table = [['id' => 1, 'created' => '0000-00-00 00:00:00']];

            public function query(string $sql)
            {
                $this->queries[] = $sql;
                if (preg_match(
                    "/SELECT COUNT\(\*\) AS c FROM dummy WHERE created='0000-00-00 00:00:00'/",
                    $sql
                )) {
                    $count = 0;
                    foreach ($this->table as $row) {
                        if ($row['created'] === '0000-00-00 00:00:00') {
                            $count++;
                        }
                    }
                    return [['c' => $count]];
                }
                if (preg_match(
                    "/UPDATE dummy SET created='([^']+)' WHERE created='0000-00-00 00:00:00'/",
                    $sql,
                    $m
                )) {
                    foreach ($this->table as &$row) {
                        if ($row['created'] === '0000-00-00 00:00:00') {
                            $row['created'] = $m[1];
                        }
                    }
                    return true;
                }

                return parent::query($sql);
            }
        };

        $original = Database::$instance;
        Database::$instance = $mock;

        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $descriptor['extra'] = ['name' => 'extra', 'type' => 'int'];

        try {
            $changes = TableDescriptor::synctable('dummy', $descriptor);
        } finally {
            Database::$instance = $original;
        }

        $this->assertSame(DATETIME_DATEMIN, $mock->table[0]['created']);
        $this->assertSame(1, $changes);
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
