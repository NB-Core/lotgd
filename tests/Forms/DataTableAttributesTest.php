<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

use Lotgd\Forms;
use PHPUnit\Framework\TestCase;

/**
 * A sortable table without an inline script: the table carries its options
 * and the assets it needs, and assets/js/lotgd.showform.js sets it up.
 */
final class DataTableAttributesTest extends TestCase
{
    public function testTheTableCarriesItsOptionsAndAssets(): void
    {
        $attributes = Forms::dataTableAttributes(['pageLength' => 25, 'language' => ['emptyTable' => "None's <here>"]]);

        self::assertStringStartsWith(' data-lotgd-datatable="', $attributes);
        self::assertStringNotContainsString('<', $attributes);
        self::assertSame(1, preg_match('/data-lotgd-datatable="([^"]*)"/', $attributes, $options));
        self::assertSame(
            ['pageLength' => 25, 'language' => ['emptyTable' => "None's <here>"]],
            json_decode(html_entity_decode($options[1], ENT_QUOTES), true)
        );
        self::assertSame(1, preg_match('/data-lotgd-datatable-assets="([^"]*)"/', $attributes, $assets));
        $assets = json_decode(html_entity_decode($assets[1], ENT_QUOTES), true);
        self::assertStringContainsString('jquery', $assets['js'][0]);
        self::assertStringContainsString('datatables', $assets['js'][1]);
        self::assertStringContainsString('datatables', $assets['css'][0]);
    }

    public function testNoOptionsIsAnEmptyObject(): void
    {
        self::assertStringContainsString('data-lotgd-datatable="{}"', Forms::dataTableAttributes());
    }
}
