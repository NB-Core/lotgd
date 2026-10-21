<?php

declare(strict_types=1);

namespace Lotgd\Tests\Bank;

use Lotgd\Action;
use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * The bank as the pilot of guarded actions (docs/ReactiveCore.md, Phase 3),
 * run for real through the page harness: one transaction per booking, a form
 * sent twice books once, a changed account books nothing, and with the
 * setting off the bank is the bank it was.
 */
final class GuardedBankPageTest extends TestCase
{
    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PLAYER = ['gold' => 100, 'goldinbank' => 50, 'alive' => 1, 'level' => 5, 'dragonkills' => 1];
    private const BOB = ['acctid' => 2, 'login' => 'bob', 'name' => 'Bob', 'level' => 5, 'transferredtoday' => 0, 'locked' => 0];
    private const VERSION_LOCK = '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/';
    private const LOG_LOOKUP = '/FROM action_log WHERE acctid = :acctid AND action_id = :action_id$/';
    private const REPLAY_CHECK = '/SELECT 1 FROM action_log/';
    private const RECIPIENT = '/a\.login = :loginExact/';

    public function testADepositIsOneTransactionAndItsDebugLineFollowsTheCommit(): void
    {
        $run = $this->post('depositfinish', ['amount' => '30']);

        self::assertStringContainsString('records your deposit of', $run->html, $this->context($run));
        $steps = $this->steps($run);
        self::assertSame(
            ['START TRANSACTION', 'version lock', 'action_log lookup', 'checked update', 'log insert', 'COMMIT', 'debuglog'],
            $steps
        );
    }

    public function testWithAMyIsamTableLeftTheBankBooksAsBefore(): void
    {
        // An installation upgraded from an old release: accounts is MyISAM, so
        // a rollback could not undo the booking. The guard stays off.
        $run = $this->post('depositfinish', ['amount' => '30'], ['/information_schema\.TABLES/' => [
            ['TABLE_NAME' => 'accounts', 'ENGINE' => 'MyISAM'],
            ['TABLE_NAME' => 'action_log', 'ENGINE' => 'InnoDB'],
            ['TABLE_NAME' => 'mail', 'ENGINE' => 'InnoDB'],
        ]]);

        self::assertStringContainsString('records your deposit of', $run->html, $this->context($run));
        self::assertSame(['debuglog'], $this->steps($run));
        self::assertStringNotContainsString('action_id', $run->html);
    }

    public function testWithTheSettingOffTheBankBooksAsBefore(): void
    {
        $run = $this->post('depositfinish', ['amount' => '30'], guarded: false);

        self::assertStringContainsString('records your deposit of', $run->html, $this->context($run));
        self::assertSame(['debuglog'], $this->steps($run));
    }

    public function testTheFormsCarryAnActionIdOnlyWhenGuarded(): void
    {
        foreach (['deposit', 'withdraw', 'borrow'] as $op) {
            $guarded = PageRunner::withRequest('bank.php', 0, 'GET', ['op' => $op], [], $this->globals(), ['guarded_actions' => 1]);
            self::assertMatchesRegularExpression("/name='action_id' value='[0-9a-f]{32}'/", $guarded->html, $op);
            self::assertStringContainsString("name='action_version' value='0'", $guarded->html, $op);

            $plain = PageRunner::withRequest('bank.php', 0, 'GET', ['op' => $op], [], $this->globals(), ['guarded_actions' => 0]);
            self::assertStringNotContainsString('action_id', $plain->html, $op);
        }

        $confirm = $this->post('transfer2', ['to' => 'bob', 'amount' => '10'], [self::RECIPIENT => [self::BOB]]);
        self::assertMatchesRegularExpression("/name='action_id' value='[0-9a-f]{32}'/", $confirm->html, $this->context($confirm));
    }

    public function testTheSameFormAgainShowsTheFirstBookingAndBooksNothing(): void
    {
        $run = $this->post('depositfinish', ['amount' => '30'], $this->logged('bank.deposit', ['amount' => 30], [
            'accepted' => true, 'amount' => 30, 'gold' => 70, 'goldinbank' => 80, 'name' => 'Admin',
        ]));

        self::assertStringContainsString('already written down', $run->html, $this->context($run));
        self::assertStringContainsString('balance of 80 gold in the bank and 70 gold in hand', strip_tags($run->html), 'Shown from the log, not from the account as it is now.');
        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'ROLLBACK'], $this->steps($run));
    }

    public function testAFormSentAgainAfterThePageMovedOnIsAnsweredInsteadOfSentToBadnav(): void
    {
        // The first answer was lost; the page it would have shown gave out
        // new links, so this address is no longer one of them.
        $account = ['allowednavs' => serialize(['village.php' => true])];
        $logged = $this->logged('bank.deposit', ['amount' => 30], [
            'accepted' => true, 'amount' => 30, 'gold' => 70, 'goldinbank' => 80, 'name' => 'Admin',
        ]);

        $run = $this->post('depositfinish', ['amount' => '30'], $logged + [self::REPLAY_CHECK => [['1' => 1]]], account: $account);
        self::assertStringContainsString('already written down', $run->html, $this->context($run));
        self::assertStringNotContainsString("to location 'badnav.php'", $run->html);

        $control = $this->post('depositfinish', ['amount' => '30'], $logged, account: $account);
        self::assertStringContainsString("to location 'badnav.php'", $control->html, 'Without its log row the address is an ordinary bad navigation.');
    }

    public function testAnAccountChangedSinceTheFormWasShownBooksNothing(): void
    {
        $run = $this->post('depositfinish', ['amount' => '30'], [self::VERSION_LOCK => [['version' => 9]]]);

        self::assertStringContainsString('Your account has changed since you filled in that slip', $run->html, $this->context($run));
        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'ROLLBACK'], $this->steps($run));
    }

    public function testAFormShownBeforeTheAccountChangedBooksNothing(): void
    {
        // Shown at version 4; something else moved the account on to 5 since.
        $run = $this->post(
            'withdrawfinish',
            ['amount' => '0'],
            [self::VERSION_LOCK => [['version' => 5]]],
            post: ['action_id' => self::ID, 'action_version' => '4'],
            account: ['version' => 5]
        );

        self::assertStringContainsString('Your account has changed since you filled in that slip', $run->html, $this->context($run));
        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'ROLLBACK'], $this->steps($run));
    }

    public function testAFormWithoutAnActionIdBooksNothing(): void
    {
        $run = $this->post('withdrawfinish', ['amount' => '20'], post: ['action_id' => '']);

        self::assertStringContainsString('it is no longer valid', $run->html, $this->context($run));
        self::assertSame([], $this->steps($run));
    }

    public function testATransferCreditsTheRecipientConditionallyAndMailsAfterTheCommit(): void
    {
        $run = $this->post('transfer3', ['to' => 'bob', 'amount' => '10'], [self::RECIPIENT => [self::BOB]]);

        self::assertStringContainsString('The transfer has been completed', $run->html, $this->context($run));
        $credit = $run->statementsContaining('SET goldinbank = goldinbank + :amount');
        self::assertCount(1, $credit);
        self::assertStringContainsString('version = version + 1', $credit[0], 'The recipient\'s pages must notice the money arrived.');
        self::assertStringContainsString('AND locked = 0 AND transferredtoday < :received', $credit[0]);
        self::assertSame(
            ['START TRANSACTION', 'version lock', 'action_log lookup', 'credit', 'checked update', 'log insert', 'COMMIT', 'debuglog', 'mail'],
            $this->steps($run)
        );
    }

    public function testATransferWhoseRecipientChangedMeanwhileIsUndone(): void
    {
        $run = $this->post(
            'transfer3',
            ['to' => 'bob', 'amount' => '10'],
            [self::RECIPIENT => [self::BOB]],
            ['/SET goldinbank = goldinbank \+ :amount/' => 0]
        );

        self::assertStringContainsString('accounting error', $run->html, $this->context($run));
        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'credit', 'ROLLBACK'], $this->steps($run));
    }

    public function testATransferWithTheSettingOffCreditsUnconditionallyButMovesTheVersion(): void
    {
        $run = $this->post('transfer3', ['to' => 'bob', 'amount' => '10'], [self::RECIPIENT => [self::BOB]], guarded: false);

        self::assertSame(
            // Unconditional as before; only the version moves on now, as every
            // write to an account must once the column exists.
            ['UPDATE accounts SET goldinbank = goldinbank + :amount, transferredtoday = transferredtoday + 1, version = version + 1 WHERE acctid = :acctid'],
            $run->statementsContaining('SET goldinbank = goldinbank + :amount')
        );
        self::assertSame(['debuglog', 'credit', 'mail'], $this->steps($run));
    }

    /**
     * @param array<string,string>                     $fields
     * @param array<string,list<array<string,mixed>>>  $queries
     * @param array<string,int>                        $statements
     * @param array<string,string>                     $post
     * @param array<string,mixed>                      $account
     */
    private function post(
        string $op,
        array $fields,
        array $queries = [],
        array $statements = [],
        bool $guarded = true,
        array $post = ['action_id' => self::ID],
        array $account = []
    ): PageOutcome {
        return PageRunner::withRequest(
            'bank.php',
            0,
            'POST',
            ['op' => $op],
            $post + $fields,
            $this->globals($account),
            ['guarded_actions' => $guarded ? 1 : 0],
            [],
            $statements,
            $queries + [self::VERSION_LOCK => [['version' => 0]]]
        );
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    private function globals(array $account = []): array
    {
        return ['accounts_table' => [1 => $account + self::PLAYER + ['lasthit' => date('Y-m-d H:i:s')]]];
    }

    /**
     * The action_log row a booking already made leaves behind.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $result
     * @return array<string,list<array<string,mixed>>>
     */
    private function logged(string $name, array $params, array $result): array
    {
        return [self::LOG_LOOKUP => [[
            'action_name' => $name,
            'params_hash' => Action::paramsHash($name, $params),
            'result' => json_encode($result),
        ]]];
    }

    /**
     * What the request did that matters here, named, in order.
     *
     * @return list<string>
     */
    private function steps(PageOutcome $run): array
    {
        $steps = [];
        foreach ($run->queries as $sql) {
            $sql = (string) preg_replace('/\s+/', ' ', $sql);
            if (in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
                $steps[] = $sql;
            } elseif (preg_match(self::LOG_LOOKUP, $sql) === 1) {
                $steps[] = 'action_log lookup';
            } elseif (preg_match(self::VERSION_LOCK, $sql) === 1) {
                $steps[] = 'version lock';
            } elseif (str_contains($sql, 'AND version = :expected_version')) {
                $steps[] = 'checked update';
            } elseif (str_contains($sql, 'SET goldinbank = goldinbank + :amount')) {
                $steps[] = 'credit';
            } elseif (str_starts_with($sql, 'INSERT INTO action_log')) {
                $steps[] = 'log insert';
            } elseif (str_starts_with($sql, 'INSERT INTO debuglog')) {
                $steps[] = 'debuglog';
            } elseif (str_starts_with($sql, 'INSERT INTO mail')) {
                $steps[] = 'mail';
            }
        }

        return $steps;
    }

    private function context(PageOutcome $run): string
    {
        return 'status: ' . var_export($run->status, true) . "\nqueries:\n"
            . implode("\n", array_filter($run->queries, static fn ($q): bool => !preg_match('/^SELECT intext|^INSERT INTO settings/', (string) $q)))
            . "\nbody: " . substr(strip_tags($run->html), 0, 800);
    }
}
