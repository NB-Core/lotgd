<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\Action;
use Lotgd\Action\ActionAborted;
use Lotgd\Action\ActionResult;
use Lotgd\Bank;
use Lotgd\Bank\Balance;
use Lotgd\Bank\TransferLimits;
use Lotgd\Bank\TransferRefusal;
use Lotgd\Bank\WithdrawOutcome;
use Lotgd\DateTime;
use Lotgd\Forms;
use Lotgd\Http;
use Lotgd\Mail;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\PlayerSearch;
use Lotgd\Sanitize;
use Lotgd\Settings;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready

require_once __DIR__ . "/common.php";

$output   = Output::getInstance();
$settings = Settings::getInstance();
$playerSearch = new PlayerSearch();

Translator::getInstance()->setSchema("bank");

Header::pageHeader("Ye Olde Bank");
$output->output("`^`c`bYe Olde Bank`b`c");
$opRequest = Http::get('op');
$op = is_string($opRequest) ? $opRequest : '';
$point = $settings->getSetting('moneydecimalpoint', ".");
$sep = $settings->getSetting('moneythousandssep', ",");

// With guarded_actions on, every booking runs once per form, in one
// transaction with the account's version checked (Lotgd\Action). The forms
// carry an action id; the booking returns what the page shows, so a form sent
// twice shows the first booking again instead of making a second one. With it
// off, the booking simply runs, as it always has.
$guarded = Action::enabled();
$actionField = $guarded ? Action::idField() : '';
/**
 * @param array<string,mixed>            $params
 * @param callable():array<string,mixed> $booking
 * @return array<string,mixed>|null What the page shows, or null when nothing was booked.
 */
$book = static function (string $name, array $params, callable $booking) use ($guarded, $output): ?array {
    if (!$guarded) {
        return $booking();
    }

    $result = Action::run($name, Action::postedId(), $params, $booking);
    switch ($result->status) {
        case ActionResult::DONE:
            return $result->data;
        case ActionResult::REPLAYED:
            $output->output("`@Elessa`6 taps her ledger, \"`@This one is already written down; I will not book it twice.`6\"`n`n");
            return $result->data;
        case ActionResult::CONFLICT:
            $output->output("`@Elessa`6 frowns at her ledger, \"`@Your account has changed since you filled in that slip. Nothing has been booked; please look at your balance and try again.`6\"");
            return null;
        default:
            if ($result->reason === 'missing-action-id' || $result->reason === 'action-id-reused') {
                $output->output("`@Elessa`6 hands the slip back to you, \"`@I cannot take this slip; it is no longer valid. Nothing has been booked; please fill in a new one.`6\"");
            } else {
                $output->output("`@Elessa`6 looks up from her ledger with a bit of surprise on her face, \"`@I'm terribly sorry, but I seem to have run into an accounting error, would you please try telling me what you wish to transfer again?`6\"");
            }
            return null;
    }
};

// Every operation that moves money, guarded once at the entry rather than per
// branch -- the next branch somebody adds is the one a per-branch check gets
// forgotten in. transfer2 is only a preview, but it carries the amount and
// recipient forward into transfer3, so it is on the list too.
if (Forms::isUnverifiedCoreOp($op, ['transfer2', 'transfer3', 'depositfinish', 'withdrawfinish'])) {
    http_response_code(400);
    $op = '';
    $_POST = [];
}
if ($op == "") {
    DateTime::checkDay();
    $output->output("`6As you approach the pair of impressive carved rock crystal doors, they part to allow you entrance into the bank.");
    $output->output("You find yourself standing in a room of exquisitely vaulted ceilings of carved stone.");
    $output->output("Light filters through tall windows in shafts of soft radiance.");
    $output->output("About you, clerks are bustling back and forth.");
    $output->output("The sounds of gold being counted can be heard, though the treasure is nowhere to be seen.`n`n");
    $output->output("You walk up to a counter of jet black marble.`n`n");
    $output->output("`@Elessa`6, a petite woman in an immaculately tailored business dress, greets you from behind reading spectacles with polished silver frames.`n`n");
    $output->output("`6\"`5Greetings, my good lady,`6\" you greet her, \"`5Might I inquire as to my balance this fine day?`6\"`n`n");
    $output->output("`@Elessa`6 blinks for a moment and then smiles, \"`@Hmm, `&%s`@, let's see.....`6\" she mutters as she scans down a page in her ledger.", $session['user']['name']);
    if ($session['user']['goldinbank'] >= 0) {
        $output->output("`6\"`@Aah, yes, here we are.  You have `^%s gold`@ in our prestigious bank.  Is there anything else I can do for you?`6\"", number_format($session['user']['goldinbank'], 0, $point, $sep));
    } else {
        $output->output("`6\"`@Aah, yes, here we are.  You have a `&debt`@ of `^%s gold`@ in our prestigious bank.  Is there anything else I can do for you?`6\"", number_format(abs($session['user']['goldinbank']), 0, $point, $sep));
    }
} elseif ($op == "transfer") {
    $output->output("`6`bTransfer Money`b:`n");
    if ($session['user']['goldinbank'] >= 0) {
        $output->output("`@Elessa`6 tells you, \"`@Just so that you are fully aware of our policies, you may only transfer `^%s`@ gold per the recipient's level.", $settings->getSetting("transferperlevel", 25));
        $maxout = $session['user']['level'] * $settings->getSetting("maxtransferout", 25);
        $output->output("Similarly, you may transfer no more than `^%s`@ gold total during the day.`6\"`n", $maxout);
        if ($session['user']['amountouttoday'] > 0) {
            $output->output("`6She scans her ledgers briefly, \"`@For your knowledge, you have already transferred `^%s`@ gold today.`6\"`n", $session['user']['amountouttoday']);
        }
        $output->outputNotl("`n");
        $preview = Translator::translateInline("Preview Transfer");
        $output->rawOutput("<form action='bank.php?op=transfer2' method='POST'>" . Forms::csrfField());
        $output->output("Transfer how much: ");
        $output->rawOutput("<input name='amount' id='amount' width='5'>");
        $output->outputNotl("`n");
        $output->output("To: ");
        $output->rawOutput("<input name='to'>");
        $output->output(" (partial names are ok, you will be asked to confirm the transaction before it occurs).`n");
        $output->rawOutput("<input type='submit' class='button' value='$preview'></form>");
        $output->rawOutput("<script language='javascript'>document.getElementById('amount').focus();</script>");
        Nav::add("", "bank.php?op=transfer2");
    } else {
        $output->output("`@Elessa`6 tells you that she refuses to transfer money for someone who is in debt.");
    }
} elseif ($op == "transfer2") {
    $output->output("`6`bConfirm Transfer`b:`n");
    $toPost = Http::post('to');
    $to = is_string($toPost) ? $toPost : '';
    $columns = ['acctid', 'login', 'name', 'level', 'locked'];
    $matches = $playerSearch->findForTransfer($to, $columns);
    $matches = array_values(array_filter(
        $matches,
        static fn(array $candidate): bool => empty($candidate['locked'])
    ));
    $amountPost = Http::post('amount');
    $amt = Bank::postedAmount($amountPost);
    $matchCount = count($matches);
    $charset = $settings->getSetting("charset", "UTF-8");
    if ($matchCount === 1) {
        $row = $matches[0];
        $msg = Translator::translateInline("Complete Transfer");
        $output->rawOutput("<form action='bank.php?op=transfer3' method='POST'>" . Forms::csrfField() . $actionField);
        $output->output(
            "`6Transfer `^%s`6 gold to `&%s`6.",
            number_format($amt, 0, $point, $sep),
            $row['name']
        );
        $output->rawOutput(
            "<input type='hidden' name='to' value='" .
            HTMLEntities($row['login'], ENT_COMPAT, $charset) .
            "'><input type='hidden' name='amount' value='$amt'><input type='submit' class='button' value='$msg'></form>",
            true
        );
        Nav::add("", "bank.php?op=transfer3");
    } elseif ($matchCount >= 100) {
        $output->output("`@Elessa`6 looks at you disdainfully and coldly, but politely, suggests you try narrowing down the field of who you want to send money to just a little bit!`n`n");
        $msg = Translator::translateInline("Preview Transfer");
        $output->rawOutput("<form action='bank.php?op=transfer2' method='POST'>" . Forms::csrfField());
        $output->output("Transfer how much: ");
        $output->rawOutput("<input name='amount' id='amount' width='5' value='$amt'><br>");
        $output->output("To: ");
        $output->rawOutput("<input name='to' value='" . HTMLEntities($to, ENT_COMPAT, $charset) . "'>");
        $output->output(" (partial names are ok, you will be asked to confirm the transaction before it occurs).`n");
        $output->rawOutput("<input type='submit' class='button' value='$msg'></form>");
        $output->rawOutput("<script language='javascript'>document.getElementById('amount').focus();</script>", true);
        Nav::add("", "bank.php?op=transfer2");
    } elseif ($matchCount > 1) {
        $output->rawOutput("<form action='bank.php?op=transfer3' method='POST'>" . Forms::csrfField() . $actionField);
        $output->rawOutput("<label for='bank_to'>");
        $output->output(
            "`6Transfer `^%s`6 gold to ",
            number_format($amt, 0, $point, $sep)
        );
        $output->rawOutput("</label>");
        $output->rawOutput("<select name='to' id='bank_to' class='input'>");
        foreach ($matches as $row) {
            $label = HTMLEntities(Sanitize::stripAllColorCodes($row['name']), ENT_COMPAT, $charset);
            $value = HTMLEntities($row['login'], ENT_COMPAT, $charset);
            $output->rawOutput("<option value='$value'>$label</option>");
        }
        $msg = Translator::translateInline("Complete Transfer");
        $output->rawOutput("</select><input type='hidden' name='amount' value='$amt'><input type='submit' class='button' value='$msg'></form>", true);
        Nav::add("", "bank.php?op=transfer3");
    } else {
        $output->output("`@Elessa`6 blinks at you from behind her spectacles, \"`@I'm sorry, but I can find no one matching that name who does business with our bank!  Please try again.`6\"");
    }
} elseif ($op == "transfer3") {
    $amountPost = Http::post('amount');
    $amt = Bank::postedAmount($amountPost);
    $toPost = Http::post('to');
    $to = is_string($toPost) ? $toPost : '';
    $output->output("`6`bTransfer Completion`b`n");

    // The lookup stays here because it is a query, not arithmetic; everything
    // that decides whether the money may move lives in Lotgd\Bank, including
    // the order the rules are applied in.
    $transferred = $book('bank.transfer', ['amount' => $amt, 'to' => $to], static function () use ($amt, $to, $playerSearch, $settings, $guarded): array {
        global $session;

        $row = null;
        if ($session['user']['gold'] + $session['user']['goldinbank'] >= $amt) {
            $result = $playerSearch->findExactLogin(
                $to,
                ['acctid', 'login', 'name', 'level', 'transferredtoday', 'locked']
            );
            $row = $result[0] ?? null;
        }
        $limits = new TransferLimits(
            (int) $settings->getSetting("maxtransferout", 25),
            (int) $settings->getSetting("transferperlevel", 25),
            (int) $settings->getSetting("transferreceive", 3)
        );

        $transfer = Bank::transfer(
            new Balance(
                (int) $session['user']['gold'],
                (int) $session['user']['goldinbank'],
                (int) $session['user']['level'],
                (int) $session['user']['amountouttoday']
            ),
            (int) $session['user']['acctid'],
            $row,
            $amt,
            $limits
        );

        $shown = [
            'accepted' => $transfer->accepted(),
            'refusal' => $transfer->refusal?->name,
            'have' => (int) $session['user']['gold'] + (int) $session['user']['goldinbank'],
            'senderDailyLimit' => $transfer->senderDailyLimit,
            'recipientPerTransferLimit' => $transfer->recipientPerTransferLimit,
            'recipientName' => (string) ($row['name'] ?? ''),
        ];
        if (!$transfer->accepted()) {
            return $shown;
        }

        $recipient = (int) $row['acctid'];
        Action::afterCommit(static fn () => debuglog("transferred $amt gold to", $recipient));
        $session['user']['gold'] = $transfer->sender->gold;
        $session['user']['goldinbank'] = $transfer->sender->goldInBank;
        $session['user']['amountouttoday'] = $transfer->sender->amountOutToday;
        // The recipient's version moves on, so a guarded page of theirs that
        // read the old balance cannot book over the credit. Guarded, the row
        // is also changed only if it still allows what Bank::transfer()
        // checked against the row read before the lock.
        $credited = Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('accounts')
            . ' SET goldinbank = goldinbank + :amount, transferredtoday = transferredtoday + 1, version = version + 1'
            . ' WHERE acctid = :acctid'
            . ($guarded ? ' AND locked = 0 AND transferredtoday < :received' : ''),
            ['amount' => $amt, 'acctid' => $recipient] + ($guarded ? ['received' => $limits->transfersReceivedPerDay] : []),
            ['amount' => ParameterType::INTEGER, 'acctid' => ParameterType::INTEGER, 'received' => ParameterType::INTEGER]
        );
        if ($guarded && (int) $credited !== 1) {
            throw new ActionAborted('recipient-changed');
        }
        $senderName = $session['user']['name'];
        Action::afterCommit(static function () use ($recipient, $senderName, $amt): void {
            $subj = array("`^You have received a money transfer!`0");
            $body = array("`&%s`6 has transferred `^%s`6 gold to your bank account!", $senderName, $amt);
            Mail::systemMail($recipient, $subj, $body);
        });

        return $shown;
    });

    if ($transferred === null) {
        // Nothing was booked; the reason is on the page already.
    } elseif ($transferred['accepted']) {
        $output->output("`@Elessa`6 smiles, \"`@The transfer has been completed!`6\"");
    } else {
        switch ($transferred['refusal']) {
            case TransferRefusal::NotEnoughMoney->name:
                $output->output("`@Elessa`6 stands up to her full, but still diminutive height and glares at you, \"`@How can you transfer `^%s`@ gold when you only possess `^%s`@?`6\"", number_format($amt, 0, $point, $sep), number_format($transferred['have'], 0, $point, $sep));
                break;
            case TransferRefusal::SenderDailyLimit->name:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; you are not allowed to transfer more than `^%s`@ gold total per day.`6\"", $transferred['senderDailyLimit']);
                break;
            case TransferRefusal::RecipientPerTransferLimit->name:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; `&%s`@ may only receive up to `^%s`@ gold per day.`6\"", $transferred['recipientName'], $transferred['recipientPerTransferLimit']);
                break;
            case TransferRefusal::RecipientDailyCount->name:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; `&%s`@ has received too many transfers today, you will have to wait until tomorrow.`6\"", $transferred['recipientName']);
                break;
            case TransferRefusal::BelowMinimum->name:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; you might want to send a worthwhile transfer, at least as much as your level.`6\"");
                break;
            case TransferRefusal::SelfTransfer->name:
                $output->output("`@Elessa`6 glares at you, her eyes flashing dangerously, \"`@You may not transfer money to yourself!  That makes no sense!`6\"");
                break;
            case TransferRefusal::RecipientLocked->name:
                $output->output("`@Elessa`6 gently closes her ledger, \"`@I'm sorry, but `&%s`@'s account is currently locked. I cannot complete that transfer.`6\"", $transferred['recipientName']);
                break;
            default:
                $output->output("`@Elessa`6 looks up from her ledger with a bit of surprise on her face, \"`@I'm terribly sorry, but I seem to have run into an accounting error, would you please try telling me what you wish to transfer again?`6\"");
                break;
        }
    }
} elseif ($op == "deposit") {
    $output->output("`0");
    $output->rawOutput("<form action='bank.php?op=depositfinish' method='POST'>" . Forms::csrfField() . $actionField);
    $balance = Translator::translateInline("`@Elessa`6 says, \"`@You have a balance of `^%s`@ gold in the bank.`6\"`n");
    $debt = Translator::translateInline("`@Elessa`6 says, \"`@You have a `\$debt`@ of `^%s`@ gold to the bank.`6\"`n");
    $output->outputNotl($session['user']['goldinbank'] >= 0 ? $balance : $debt, number_format(abs($session['user']['goldinbank']), 0, $point, $sep));
    $output->output("`6Searching through all your pockets and pouches, you calculate that you currently have `^%s`6 gold on hand.`n`n", number_format($session['user']['gold'], 0, $point, $sep));
    $dep = Translator::translateInline("`^Deposit how much?");
    $pay = Translator::translateInline("`^Pay off how much?");
    $output->outputNotl($session['user']['goldinbank'] >= 0 ? $dep : $pay);
    $dep = Translator::translateInline("Deposit");
    $output->rawOutput(" <input id='input' name='amount' width=5 > <input type='submit' class='button' value='$dep'>");
    $output->output("`n`iEnter 0 or nothing to deposit it all`i");
    $output->rawOutput("</form>");
    $output->rawOutput("<script language='javascript'>document.getElementById('input').focus();</script>", true);
    Nav::add("", "bank.php?op=depositfinish");
} elseif ($op == "depositfinish") {
    $amountPost = Http::post('amount');
    $amount = Bank::postedAmount($amountPost);
    $notenough = Translator::translateInline("`\$ERROR: Not enough gold in hand to deposit.`n`n`^You plunk your `&%s`^ gold on the counter and declare that you would like to deposit all `&%s`^ gold of it.`n`n`@Elessa`6 stares blandly at you for a few seconds until you become self conscious and recount your money, realizing your mistake.");
    $depositdebt = Translator::translateInline("`@Elessa`6 records your deposit of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a debt of `\$%s`@ gold to the bank and `^%s`@ gold in hand.`6\"");
    $depositbalance = Translator::translateInline("`@Elessa`6 records your deposit of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a balance of `^%s`@ gold in the bank and `^%s`@ gold in hand.`6\"");

    $deposited = $book('bank.deposit', ['amount' => $amount], static function () use ($amount): array {
        global $session;

        $deposit = Bank::deposit(
            new Balance((int) $session['user']['gold'], (int) $session['user']['goldinbank']),
            $amount
        );
        if ($deposit->accepted) {
            Action::afterCommit(static fn () => debuglog("deposited " . $deposit->amount . " gold in the bank"));
            $session['user']['goldinbank'] = $deposit->balance->goldInBank;
            $session['user']['gold'] = $deposit->balance->gold;
        }

        return [
            'accepted' => $deposit->accepted,
            'amount' => $deposit->amount,
            'gold' => (int) $session['user']['gold'],
            'goldinbank' => (int) $session['user']['goldinbank'],
            'name' => (string) $session['user']['name'],
        ];
    });

    if ($deposited === null) {
        // Nothing was booked; the reason is on the page already.
    } elseif (!$deposited['accepted']) {
        $output->outputNotl($notenough, number_format($deposited['gold'], 0, $point, $sep), number_format($deposited['amount'], 0, $point, $sep));
    } else {
        $output->outputNotl($deposited['goldinbank'] >= 0 ? $depositbalance : $depositdebt, number_format($deposited['amount'], 0, $point, $sep), $deposited['name'], number_format(abs($deposited['goldinbank']), 0, $point, $sep), number_format($deposited['gold'], 0, $point, $sep));
    }
} elseif ($op == "borrow") {
    $maxborrow = $session['user']['level'] * $settings->getSetting("borrowperlevel", 20);
    $borrow = Translator::translateInline("Borrow");
    $balance = Translator::translateInline("`@Elessa`6 scans through her ledger, \"`@You have a balance of `^%s`@ gold in the bank.`6\"`n");
    $debt = Translator::translateInline("`@Elessa`6 scans through her ledger, \"`@You have a `\$debt`@ of `^%s`@ gold to the bank.`6\"`n");
    $output->rawOutput("<form action='bank.php?op=withdrawfinish' method='POST'>" . Forms::csrfField() . $actionField);
    $output->outputNotl($session['user']['goldinbank'] >= 0 ? $balance : $debt, number_format(abs($session['user']['goldinbank']), 0, $point, $sep));
    $output->output("`6\"`@How much would you like to borrow `&%s`@?  At your level, you may borrow up to a total of `^%s`@ from the bank.`6\"`n`n", $session['user']['name'], $maxborrow);
    $output->rawOutput(" <input id='input' name='amount' width=5 > <input type='hidden' name='borrow' value='x'><input type='submit' class='button' value='$borrow'>");
    $output->output("`n(Money will be withdrawn until you have none left, the remainder will be borrowed)");
    $output->rawOutput("</form>");
    $output->rawOutput("<script language='javascript'>document.getElementById('input').focus();</script>");
    Nav::add("", "bank.php?op=withdrawfinish");
} elseif ($op == "withdraw") {
    $withdraw = Translator::translateInline("Withdraw");
    $balance = Translator::translateInline("`@Elessa`6 scans through her ledger, \"`@You have a balance of `^%s`@ gold in the bank.`6\"`n");
    $debt = Translator::translateInline("`@Elessa`6 scans through her ledger, \"`@You have a `\$debt`@ of `^%s`@ gold in the bank.`6\"`n");
    $output->rawOutput("<form action='bank.php?op=withdrawfinish' method='POST'>" . Forms::csrfField() . $actionField);
    $output->outputNotl($session['user']['goldinbank'] >= 0 ? $balance : $debt, number_format(abs($session['user']['goldinbank']), 0, $point, $sep));
    $output->output("`6\"`@How much would you like to withdraw `&%s`@?`6\"`n`n", $session['user']['name']);
    $output->rawOutput("<input id='input' name='amount' width=5 > <input type='submit' class='button' value='$withdraw'>");
    $output->output("`n`iEnter 0 or nothing to withdraw it all`i");
    $output->rawOutput("</form>");
    $output->rawOutput("<script language='javascript'>document.getElementById('input').focus();</script>");
    Nav::add("", "bank.php?op=withdrawfinish");
} elseif ($op == "withdrawfinish") {
    $amountPost = Http::post('amount');
    $amount = Bank::postedAmount($amountPost);
    $borrowPost = Http::post('borrow');
    $borrow = is_string($borrowPost) ? $borrowPost : '';

    $withdrawn = $book('bank.withdraw', ['amount' => $amount, 'borrow' => $borrow !== ''], static function () use ($amount, $borrow, $settings): array {
        global $session;

        $before = (int) $session['user']['goldinbank'];
        $withdrawal = Bank::withdraw(
            new Balance(
                (int) $session['user']['gold'],
                (int) $session['user']['goldinbank'],
                (int) $session['user']['level']
            ),
            $amount,
            $borrow !== "",
            (int) $settings->getSetting("borrowperlevel", 20)
        );

        switch ($withdrawal->outcome) {
            case WithdrawOutcome::Withdrawn:
                $session['user']['goldinbank'] = $withdrawal->balance->goldInBank;
                $session['user']['gold'] = $withdrawal->balance->gold;
                Action::afterCommit(static fn () => debuglog("withdrew " . $withdrawal->withdrawn . " gold from the bank"));
                break;

            case WithdrawOutcome::WithdrawnAndBorrowed:
            case WithdrawOutcome::Borrowed:
                if ($withdrawal->withdrawn > 0) {
                    Action::afterCommit(static fn () => debuglog("withdrew " . $withdrawal->requested . " gold from the bank"));
                }
                $session['user']['goldinbank'] = $withdrawal->balance->goldInBank;
                $session['user']['gold'] = $withdrawal->balance->gold;
                Action::afterCommit(static fn () => debuglog("borrows " . $withdrawal->borrowed . " gold from the bank"));
                break;

            default:
                break;
        }

        return [
            'outcome' => $withdrawal->outcome->name,
            'requested' => $withdrawal->requested,
            'withdrawn' => $withdrawal->withdrawn,
            'borrowed' => $withdrawal->borrowed,
            'borrowingLimit' => $withdrawal->borrowingLimit,
            'before' => $before,
            'gold' => (int) $session['user']['gold'],
            'goldinbank' => (int) $session['user']['goldinbank'],
            'name' => (string) $session['user']['name'],
        ];
    });

    switch ($withdrawn['outcome'] ?? null) {
        case WithdrawOutcome::NotEnoughInBank->name:
            $output->output("`\$ERROR: Not enough gold in the bank to withdraw.`^`n`n");
            $output->output("`6Having been informed that you have `^%s`6 gold in your account, you declare that you would like to withdraw all `^%s`6 of it.`n`n", number_format($withdrawn['before'], 0, $point, $sep), number_format($withdrawn['requested'], 0, $point, $sep));
            $output->output("`@Elessa`6 looks at you for a few moments without blinking, then advises you to take basic arithmetic.  You realize your folly and think you should try again.");
            break;

        case WithdrawOutcome::OverBorrowingLimit->name:
            $output->output("`6Considering the `^%s`6 gold in your account, you ask to borrow `^%s`6. `@Elessa`6 peers through her ledger, runs a few calculations and then informs you that, at your level, you may only borrow up to a total of `^%s`6 gold.", number_format($withdrawn['before'], 0, $point, $sep), number_format($withdrawn['requested'] - $withdrawn['before'], 0, $point, $sep), number_format($withdrawn['borrowingLimit'], 0, $point, $sep));
            break;

        case WithdrawOutcome::Withdrawn->name:
            $output->output("`@Elessa`6 records your withdrawal of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a balance of `^%s`@ gold in the bank and `^%s`@ gold in hand.`6\"", number_format($withdrawn['withdrawn'], 0, $point, $sep), $withdrawn['name'], number_format(abs($withdrawn['goldinbank']), 0, $point, $sep), number_format($withdrawn['gold'], 0, $point, $sep));
            break;

        case WithdrawOutcome::WithdrawnAndBorrowed->name:
        case WithdrawOutcome::Borrowed->name:
            if ($withdrawn['withdrawn'] > 0) {
                $output->output("`6You withdraw your remaining `^%s`6 gold.", number_format($withdrawn['withdrawn'], 0, $point, $sep));
                $output->output("`6Additionally, you borrow `^%s`6 gold.", number_format($withdrawn['borrowed'], 0, $point, $sep));
            } else {
                $output->output("`6You borrow `^%s`6 gold.", number_format($withdrawn['borrowed'], 0, $point, $sep));
            }
            $output->output("`@Elessa`6 records your withdrawal of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a debt of `\$%s`@ gold to the bank and `^%s`@ gold in hand.`6\"", number_format($withdrawn['requested'], 0, $point, $sep), $withdrawn['name'], number_format(abs($withdrawn['goldinbank']), 0, $point, $sep), number_format($withdrawn['gold'], 0, $point, $sep));
            break;
    }
}
Nav::add("Navigation");
VillageNav::render();
Nav::add("Money");
if ($session['user']['goldinbank'] >= 0) {
    Nav::add("W?Withdraw", "bank.php?op=withdraw");
    Nav::add("D?Deposit", "bank.php?op=deposit");
    if ($settings->getSetting("borrowperlevel", 20)) {
        Nav::add("L?Take out a Loan", "bank.php?op=borrow");
    }
} else {
    Nav::add("D?Pay off Debt", "bank.php?op=deposit");
    if ($settings->getSetting("borrowperlevel", 20)) {
        Nav::add("L?Borrow More", "bank.php?op=borrow");
    }
}
if ($settings->getSetting("allowgoldtransfer", 1)) {
    if ($session['user']['level'] >= $settings->getSetting("mintransferlev", 3) || $session['user']['dragonkills'] > 0) {
        Nav::add("M?Transfer Money", "bank.php?op=transfer");
    }
}

Footer::pageFooter();
