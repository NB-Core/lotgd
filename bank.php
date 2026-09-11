<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\Bank;
use Lotgd\Bank\Balance;
use Lotgd\Bank\TransferLimits;
use Lotgd\Bank\TransferRefusal;
use Lotgd\Bank\WithdrawOutcome;
use Lotgd\DateTime;
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
        $output->rawOutput("<form action='bank.php?op=transfer2' method='POST'>");
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
    $amt = abs(is_numeric($amountPost) ? (int)$amountPost : 0);
    $matchCount = count($matches);
    $charset = $settings->getSetting("charset", "UTF-8");
    if ($matchCount === 1) {
        $row = $matches[0];
        $msg = Translator::translateInline("Complete Transfer");
        $output->rawOutput("<form action='bank.php?op=transfer3' method='POST'>");
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
        $output->rawOutput("<form action='bank.php?op=transfer2' method='POST'>");
        $output->output("Transfer how much: ");
        $output->rawOutput("<input name='amount' id='amount' width='5' value='$amt'><br>");
        $output->output("To: ");
        $output->rawOutput("<input name='to' value='" . HTMLEntities($to, ENT_COMPAT, $charset) . "'>");
        $output->output(" (partial names are ok, you will be asked to confirm the transaction before it occurs).`n");
        $output->rawOutput("<input type='submit' class='button' value='$msg'></form>");
        $output->rawOutput("<script language='javascript'>document.getElementById('amount').focus();</script>", true);
        Nav::add("", "bank.php?op=transfer2");
    } elseif ($matchCount > 1) {
        $output->rawOutput("<form action='bank.php?op=transfer3' method='POST'>");
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
    $amt = abs(is_numeric($amountPost) ? (int)$amountPost : 0);
    $toPost = Http::post('to');
    $to = is_string($toPost) ? $toPost : '';
    $output->output("`6`bTransfer Completion`b`n");

    // The lookup stays here because it is a query, not arithmetic; everything
    // that decides whether the money may move lives in Lotgd\Bank, including
    // the order the rules are applied in.
    $row = null;
    if ($session['user']['gold'] + $session['user']['goldinbank'] >= $amt) {
        $result = $playerSearch->findExactLogin(
            $to,
            ['acctid', 'login', 'name', 'level', 'transferredtoday', 'locked']
        );
        $row = $result[0] ?? null;
    }

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
        new TransferLimits(
            (int) $settings->getSetting("maxtransferout", 25),
            (int) $settings->getSetting("transferperlevel", 25),
            (int) $settings->getSetting("transferreceive", 3)
        )
    );

    if ($transfer->accepted()) {
        debuglog("transferred $amt gold to", $row['acctid']);
        $session['user']['gold'] = $transfer->sender->gold;
        $session['user']['goldinbank'] = $transfer->sender->goldInBank;
        $session['user']['amountouttoday'] = $transfer->sender->amountOutToday;
        Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('accounts')
            . ' SET goldinbank = goldinbank + :amount, transferredtoday = transferredtoday + 1'
            . ' WHERE acctid = :acctid',
            ['amount' => $amt, 'acctid' => (int) $row['acctid']],
            ['amount' => ParameterType::INTEGER, 'acctid' => ParameterType::INTEGER]
        );
        $output->output("`@Elessa`6 smiles, \"`@The transfer has been completed!`6\"");
        $subj = array("`^You have received a money transfer!`0");
        $body = array("`&%s`6 has transferred `^%s`6 gold to your bank account!",$session['user']['name'],$amt);
        Mail::systemMail($row['acctid'], $subj, $body);
    } else {
        switch ($transfer->refusal) {
            case TransferRefusal::NotEnoughMoney:
                $output->output("`@Elessa`6 stands up to her full, but still diminutive height and glares at you, \"`@How can you transfer `^%s`@ gold when you only possess `^%s`@?`6\"", number_format($amt, 0, $point, $sep), number_format($session['user']['gold'] + $session['user']['goldinbank'], 0, $point, $sep));
                break;
            case TransferRefusal::SenderDailyLimit:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; you are not allowed to transfer more than `^%s`@ gold total per day.`6\"", $transfer->senderDailyLimit);
                break;
            case TransferRefusal::RecipientPerTransferLimit:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; `&%s`@ may only receive up to `^%s`@ gold per day.`6\"", $row['name'], $transfer->recipientPerTransferLimit);
                break;
            case TransferRefusal::RecipientDailyCount:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; `&%s`@ has received too many transfers today, you will have to wait until tomorrow.`6\"", $row['name']);
                break;
            case TransferRefusal::BelowMinimum:
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; you might want to send a worthwhile transfer, at least as much as your level.`6\"");
                break;
            case TransferRefusal::SelfTransfer:
                $output->output("`@Elessa`6 glares at you, her eyes flashing dangerously, \"`@You may not transfer money to yourself!  That makes no sense!`6\"");
                break;
            case TransferRefusal::RecipientLocked:
                $output->output("`@Elessa`6 gently closes her ledger, \"`@I'm sorry, but `&%s`@'s account is currently locked. I cannot complete that transfer.`6\"", $row['name']);
                break;
            default:
                $output->output("`@Elessa`6 looks up from her ledger with a bit of surprise on her face, \"`@I'm terribly sorry, but I seem to have run into an accounting error, would you please try telling me what you wish to transfer again?`6\"");
                break;
        }
    }
} elseif ($op == "deposit") {
    $output->output("`0");
    $output->rawOutput("<form action='bank.php?op=depositfinish' method='POST'>");
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
    $amount = abs(is_numeric($amountPost) ? (int)$amountPost : 0);
    $notenough = Translator::translateInline("`\$ERROR: Not enough gold in hand to deposit.`n`n`^You plunk your `&%s`^ gold on the counter and declare that you would like to deposit all `&%s`^ gold of it.`n`n`@Elessa`6 stares blandly at you for a few seconds until you become self conscious and recount your money, realizing your mistake.");
    $depositdebt = Translator::translateInline("`@Elessa`6 records your deposit of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a debt of `\$%s`@ gold to the bank and `^%s`@ gold in hand.`6\"");
    $depositbalance = Translator::translateInline("`@Elessa`6 records your deposit of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a balance of `^%s`@ gold in the bank and `^%s`@ gold in hand.`6\"");

    $deposit = Bank::deposit(
        new Balance((int) $session['user']['gold'], (int) $session['user']['goldinbank']),
        $amount
    );

    if (!$deposit->accepted) {
        $output->outputNotl($notenough, number_format($session['user']['gold'], 0, $point, $sep), number_format($deposit->amount, 0, $point, $sep));
    } else {
        debuglog("deposited " . $deposit->amount . " gold in the bank");
        $session['user']['goldinbank'] = $deposit->balance->goldInBank;
        $session['user']['gold'] = $deposit->balance->gold;
        $output->outputNotl($session['user']['goldinbank'] >= 0 ? $depositbalance : $depositdebt, number_format($deposit->amount, 0, $point, $sep), $session['user']['name'], number_format(abs($session['user']['goldinbank']), 0, $point, $sep), number_format($session['user']['gold'], 0, $point, $sep));
    }
} elseif ($op == "borrow") {
    $maxborrow = $session['user']['level'] * $settings->getSetting("borrowperlevel", 20);
    $borrow = Translator::translateInline("Borrow");
    $balance = Translator::translateInline("`@Elessa`6 scans through her ledger, \"`@You have a balance of `^%s`@ gold in the bank.`6\"`n");
    $debt = Translator::translateInline("`@Elessa`6 scans through her ledger, \"`@You have a `\$debt`@ of `^%s`@ gold to the bank.`6\"`n");
    $output->rawOutput("<form action='bank.php?op=withdrawfinish' method='POST'>");
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
    $output->rawOutput("<form action='bank.php?op=withdrawfinish' method='POST'>");
    $output->outputNotl($session['user']['goldinbank'] >= 0 ? $balance : $debt, number_format(abs($session['user']['goldinbank']), 0, $point, $sep));
    $output->output("`6\"`@How much would you like to withdraw `&%s`@?`6\"`n`n", $session['user']['name']);
    $output->rawOutput("<input id='input' name='amount' width=5 > <input type='submit' class='button' value='$withdraw'>");
    $output->output("`n`iEnter 0 or nothing to withdraw it all`i");
    $output->rawOutput("</form>");
    $output->rawOutput("<script language='javascript'>document.getElementById('input').focus();</script>");
    Nav::add("", "bank.php?op=withdrawfinish");
} elseif ($op == "withdrawfinish") {
    $amountPost = Http::post('amount');
    $amount = abs(is_numeric($amountPost) ? (int)$amountPost : 0);
    $borrowPost = Http::post('borrow');
    $borrow = is_string($borrowPost) ? $borrowPost : '';

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
        case WithdrawOutcome::NotEnoughInBank:
            $output->output("`\$ERROR: Not enough gold in the bank to withdraw.`^`n`n");
            $output->output("`6Having been informed that you have `^%s`6 gold in your account, you declare that you would like to withdraw all `^%s`6 of it.`n`n", number_format($session['user']['goldinbank'], 0, $point, $sep), number_format($withdrawal->requested, 0, $point, $sep));
            $output->output("`@Elessa`6 looks at you for a few moments without blinking, then advises you to take basic arithmetic.  You realize your folly and think you should try again.");
            break;

        case WithdrawOutcome::OverBorrowingLimit:
            $output->output("`6Considering the `^%s`6 gold in your account, you ask to borrow `^%s`6. `@Elessa`6 peers through her ledger, runs a few calculations and then informs you that, at your level, you may only borrow up to a total of `^%s`6 gold.", number_format($session['user']['goldinbank'], 0, $point, $sep), number_format($withdrawal->requested - $session['user']['goldinbank'], 0, $point, $sep), number_format($withdrawal->borrowingLimit, 0, $point, $sep));
            break;

        case WithdrawOutcome::Withdrawn:
            $session['user']['goldinbank'] = $withdrawal->balance->goldInBank;
            $session['user']['gold'] = $withdrawal->balance->gold;
            debuglog("withdrew " . $withdrawal->withdrawn . " gold from the bank");
            $output->output("`@Elessa`6 records your withdrawal of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a balance of `^%s`@ gold in the bank and `^%s`@ gold in hand.`6\"", number_format($withdrawal->withdrawn, 0, $point, $sep), $session['user']['name'], number_format(abs($session['user']['goldinbank']), 0, $point, $sep), number_format($session['user']['gold'], 0, $point, $sep));
            break;

        case WithdrawOutcome::WithdrawnAndBorrowed:
        case WithdrawOutcome::Borrowed:
            if ($withdrawal->withdrawn > 0) {
                $output->output("`6You withdraw your remaining `^%s`6 gold.", number_format($withdrawal->withdrawn, 0, $point, $sep));
                debuglog("withdrew " . $withdrawal->requested . " gold from the bank");
                $output->output("`6Additionally, you borrow `^%s`6 gold.", number_format($withdrawal->borrowed, 0, $point, $sep));
            } else {
                $output->output("`6You borrow `^%s`6 gold.", number_format($withdrawal->borrowed, 0, $point, $sep));
            }
            $session['user']['goldinbank'] = $withdrawal->balance->goldInBank;
            $session['user']['gold'] = $withdrawal->balance->gold;
            debuglog("borrows " . $withdrawal->borrowed . " gold from the bank");
            $output->output("`@Elessa`6 records your withdrawal of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a debt of `\$%s`@ gold to the bank and `^%s`@ gold in hand.`6\"", number_format($withdrawal->requested, 0, $point, $sep), $session['user']['name'], number_format(abs($session['user']['goldinbank']), 0, $point, $sep), number_format($session['user']['gold'], 0, $point, $sep));
            break;
    }
}
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
