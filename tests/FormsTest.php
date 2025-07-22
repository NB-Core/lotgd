<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Forms;
use PHPUnit\Framework\TestCase;

final class FormsTest extends TestCase
    {
        protected function setUp(): void
        {
            global $forms_output;
            $forms_output = '';
        }

        public function testCheckboxChecked(): void
        {
            global $forms_output;
            Forms::showForm(['flag' => 'Flag,checkbox'], ['flag' => 1]);
            $this->assertStringContainsString("type='checkbox' name='flag' value='1' checked", $forms_output);
        }

        public function testCheckboxUnchecked(): void
        {
            global $forms_output;
            Forms::showForm(['flag' => 'Flag,checkbox'], ['flag' => 0]);
            $this->assertStringContainsString("type='checkbox' name='flag' value='1'", $forms_output);
            $this->assertStringNotContainsString('checked', $forms_output);
        }
    }
