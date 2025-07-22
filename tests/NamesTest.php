<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Names;
use PHPUnit\Framework\TestCase;

final class NamesTest extends TestCase
{
    protected function setUp(): void
    {
        global $session;
        $session = ['user' => [
            'name' => 'Sir John`0',
            'playername' => 'John',
            'title' => 'Sir',
            'ctitle' => '',
        ]];
    }

    public function testGetPlayerTitleRespectsCustomTitle(): void
    {
        global $session;
        $this->assertSame('Sir', Names::getPlayerTitle());
        $session['user']['ctitle'] = 'Lord';
        $this->assertSame('Lord', Names::getPlayerTitle());
    }

    public function testGetPlayerBasenameUsesPlayernameOrParsesName(): void
    {
        global $session;
        $this->assertSame('John', Names::getPlayerBasename());
        $session['user']['playername'] = '';
        $this->assertSame('John', Names::getPlayerBasename());
    }

    public function testChangePlayerNameAppliesCurrentTitle(): void
    {
        global $session;
        $this->assertSame('Sir Hero`0', Names::changePlayerName('Hero'));
        $session['user']['ctitle'] = 'Lord';
        $this->assertSame('Lord Hero`0', Names::changePlayerName('Hero'));
    }

    public function testChangePlayerCtitleUsesDefaultTitleWhenEmpty(): void
    {
        $this->assertSame('Sir John`0', Names::changePlayerCtitle(''));
        $this->assertSame('Baron John`0', Names::changePlayerCtitle('Baron'));
    }

    public function testChangePlayerTitlePrefersCustomTitle(): void
    {
        global $session;
        $this->assertSame('Master John`0', Names::changePlayerTitle('Master'));
        $session['user']['ctitle'] = 'Lord';
        $this->assertSame('Lord John`0', Names::changePlayerTitle('Master'));
    }
}
