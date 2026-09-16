<?php

declare(strict_types=1);

namespace {
    /**
     * A module that contributes a control, the way one is meant to.
     *
     * Appends and returns. Note what it does *not* supply: no element, no
     * class, no escaping, no token. That is the whole point of the list -- the
     * module says what the control does and the core decides how it looks.
     */
    function goodcitizen_mailactions(string $hookName, array $args): array
    {
        $args[] = ['kind' => 'post', 'url' => 'runmodule.php?module=goodcitizen', 'label' => 'Archive'];

        return $args;
    }

    function secondmodule_mailactions(string $hookName, array $args): array
    {
        $args[] = ['kind' => 'link', 'url' => 'runmodule.php?module=secondmodule', 'label' => 'Print'];

        return $args;
    }

    /** Appends something that does not describe a control. */
    function sloppy_mailactions(string $hookName, array $args): array
    {
        $args[] = ['url' => 'runmodule.php?module=sloppy'];

        return $args;
    }

    /** Returns the wrong type entirely. */
    function broken_mailactions(string $hookName, array $args): string
    {
        return 'not an array';
    }

    /** Returns an empty array, taking the whole payload with it. */
    function greedy_mailactions(string $hookName, array $args): array
    {
        return [];
    }
}

namespace Lotgd\Tests\Mail {

    use Lotgd\Forms;
    use Lotgd\Mail\ReadActions;
    use Lotgd\Modules;
    use Lotgd\MySQL\Database;
    use PHPUnit\Framework\TestCase;

    /**
     * Whether a module can actually put a control in the mail read view.
     *
     * This cannot be asked through the page harness in tests/Security/PageCsrf:
     * there `Database::$queryCacheResults` is empty, so `Modules::hook()` finds
     * no rows and every hook silently does nothing. A test written there would
     * pass whether or not the hook call existed at all -- the shape of
     * assertion this audit has spent its time removing.
     *
     * So it is asked here instead, in-process, with fake modules and no
     * database, the way tests/Modules/Hooks does it. That is also the reason
     * the action list lives in a class rather than in pages/mail/case_read.php.
     *
     * @group hooks
     */
    final class MailReadActionsHookTest extends TestCase
    {
        /** @var array<string,mixed> */
        private array $message = [
            'messageid' => 7,
            'msgfrom' => 2,
            'name' => 'Sender',
            'subject' => 'the subject',
            'body' => 'the body',
            'sent' => '2026-01-02 10:00:00',
        ];

        protected function setUp(): void
        {
            global $session;
            $session = ['user' => ['superuser' => 0]];
        }

        protected function tearDown(): void
        {
            unset(Database::$queryCacheResults['hook-' . ReadActions::HOOK]);
            Modules::setInjectedModules([]);
        }

        /**
         * Install fake modules against the action hook.
         *
         * @param array<string,string> $modules name => callback
         */
        private function install(array $modules): void
        {
            $rows = [];
            $injected = [];
            foreach ($modules as $name => $callback) {
                $rows[] = [
                    'modulename' => $name,
                    'location' => ReadActions::HOOK,
                    'hook_callback' => $callback,
                    'whenactive' => '',
                ];
                $injected[$name] = true;
            }

            Database::$queryCacheResults['hook-' . ReadActions::HOOK] = $rows;
            Modules::setInjectedModules([1 => $injected, 0 => $injected]);
        }

        /**
         * The core's own controls are there before any module is.
         *
         * The positive control. Every assertion below about a module's entry
         * appearing would also pass against a list that was nothing but module
         * entries, and every assertion about one *not* appearing would pass
         * against an empty list.
         */
        public function testTheCoreOffersItsOwnActionsWithNoModulesAtAll(): void
        {
            $labels = array_column(ReadActions::actions($this->message), 'label');

            self::assertSame(['Delete', 'Mark Unread', 'Report to Admin'], $labels);
        }

        public function testAModuleCanAddAControl(): void
        {
            $this->install(['goodcitizen' => 'goodcitizen_mailactions']);

            $labels = array_column(ReadActions::actions($this->message), 'label');

            self::assertSame(['Delete', 'Mark Unread', 'Report to Admin', 'Archive'], $labels);
        }

        /**
         * And it comes out looking like the rest of the row.
         *
         * This is the reason the hook carries a list instead of letting a
         * module emit its own markup: the contributed control inherits the
         * class, the escaping and -- because it posts -- a CSRF token, none of
         * which the module supplied or could get wrong.
         */
        public function testAModulesControlIsRenderedLikeTheCoresOwn(): void
        {
            $this->install(['goodcitizen' => 'goodcitizen_mailactions']);

            $html = Forms::actionBar(ReadActions::actions($this->message));

            self::assertSame(4, substr_count($html, "class='button mail-nav__link'"));
            self::assertStringContainsString("<form action='runmodule.php?module=goodcitizen'", $html);
            self::assertSame(
                substr_count($html, '<form'),
                substr_count($html, "name='form_csrf_token'"),
                "the module's control should carry a token like every other posting control"
            );
        }

        public function testTwoModulesBothSurviveInPriorityOrder(): void
        {
            $this->install([
                'goodcitizen' => 'goodcitizen_mailactions',
                'secondmodule' => 'secondmodule_mailactions',
            ]);

            $labels = array_column(ReadActions::actions($this->message), 'label');

            self::assertSame(
                ['Delete', 'Mark Unread', 'Report to Admin', 'Archive', 'Print'],
                $labels
            );
        }

        /**
         * A module's malformed entry costs that entry only.
         *
         * The guard is in Forms::actionBar(), so the list still carries the bad
         * entry -- it is the rendering that drops it. Asserted on the markup
         * for that reason, and paired with the core's controls surviving.
         */
        public function testAMalformedContributionDoesNotCostTheRestOfTheRow(): void
        {
            $this->install(['sloppy' => 'sloppy_mailactions']);

            $html = Forms::actionBar(ReadActions::actions($this->message));

            self::assertStringNotContainsString('module=sloppy', $html);
            self::assertStringContainsString('Delete', $html);
            self::assertStringContainsString('Mark Unread', $html);
            self::assertStringContainsString('Report to Admin', $html);
        }

        /**
         * A module returning the wrong type is harmless.
         *
         * Modules.php:619-622 warns and keeps the payload it went in with, so
         * the row is untouched. The warning is expected here rather than
         * suppressed: it is how an operator learns which module is broken, and
         * a test that muted it would be asserting the opposite of the point.
         */
        public function testAModuleReturningANonArrayLeavesTheRowIntact(): void
        {
            $this->install(['broken' => 'broken_mailactions']);

            // restore_error_handler(), not set_error_handler($previous): the
            // latter pushes another handler onto the stack instead of popping
            // this one, so the stack grows and PHPUnit marks the test risky for
            // leaving a handler behind.
            $seen = [];
            set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
                $seen[] = $message;

                return true;
            });

            try {
                $labels = array_column(ReadActions::actions($this->message), 'label');
            } finally {
                restore_error_handler();
            }

            self::assertCount(1, $seen, 'the engine should report the module that returned the wrong type');
            self::assertStringContainsString('did not return an array', $seen[0]);
            self::assertStringContainsString('broken', $seen[0], 'and name it');

            self::assertSame(['Delete', 'Mark Unread', 'Report to Admin'], $labels);
        }

        /**
         * A module returning an empty array empties the row, and nothing here
         * can prevent that.
         *
         * Modules::hook() assigns each module's return value over the payload
         * (src/Lotgd/Modules.php:624) rather than merging it. This is written
         * down as a test because it is the one failure mode of this design that
         * has no guard: by the time the list comes back, Delete and Mark Unread
         * are simply gone, and a renderer cannot restore what it never saw.
         *
         * If this test ever fails, Modules::hook() has changed its contract and
         * the warning in ReadActions::actions()'s docblock -- and in
         * docs/Hooks.md -- should be revisited.
         */
        public function testAModuleReturningAnEmptyArrayEmptiesTheRow(): void
        {
            $this->install(['greedy' => 'greedy_mailactions']);

            self::assertSame([], ReadActions::actions($this->message));
            self::assertSame(
                "<div class='mail-nav'></div>",
                Forms::actionBar(ReadActions::actions($this->message))
            );
        }

        /**
         * The navigation bar is not hooked, and that is a decision.
         *
         * A module with somewhere else to send the player means the tab strip,
         * which has had `mailfunctions` for that all along.
         */
        public function testTheNavigationBarIsNotOfferedToModules(): void
        {
            $this->install(['goodcitizen' => 'goodcitizen_mailactions']);

            $labels = array_column(ReadActions::navigation($this->message, 5, 9), 'label');

            self::assertNotContains('Archive', $labels);
            self::assertSame(['< Previous', 'Next >', 'Reply', 'Forward'], $labels);
        }

        public function testAnAbsentNeighbourBecomesADisabledEntry(): void
        {
            $oldest = ReadActions::navigation($this->message, 0, 9);

            self::assertSame('disabled', $oldest[0]['kind']);
            self::assertSame('link', $oldest[1]['kind']);
            self::assertArrayNotHasKey('url', $oldest[0]);
        }

        public function testASystemMessageOffersNoReport(): void
        {
            $labels = array_column(ReadActions::actions(['messageid' => 7, 'msgfrom' => 0]), 'label');

            self::assertSame(['Delete', 'Mark Unread'], $labels);
        }
    }
}
