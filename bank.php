<?php

declare(strict_types=1);

use Lotgd\DateTime;
use Lotgd\Http;
use Lotgd\Mail;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Sanitize;
use Lotgd\Settings;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready

require_once __DIR__ . "/common.php";

$output   = Output::getInstance();
$settings = Settings::getInstance();

Translator::getInstance()->setSchema("bank");

Header::pageHeader("Ye Olde Bank");
$output->output("`^`c`bYe Olde Bank`b`c");
$op = Http::get('op');
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
    $string = "%";
    $to = Http::post('to');
    for ($x = 0; $x < strlen($to); $x++) {
        $string .= substr($to, $x, 1) . "%";
    }
    $sql = "SELECT name,login FROM " . Database::prefix("accounts") . " WHERE name LIKE '" . addslashes($string) . "' AND locked=0 ORDER by login='$to' DESC, name='$to' DESC, login";
    $result = Database::query($sql);
    $amt = abs((int)Http::post('amount'));
    if (Database::numRows($result) == 1) {
        $row = Database::fetchAssoc($result);
        $msg = Translator::translateInline("Complete Transfer");
        $output->rawOutput("<form action='bank.php?op=transfer3' method='POST'>");
        $output->output("`6Transfer `^%s`6 to `&%s`6.", $amt, $row['name']);
        $output->rawOutput("<input type='hidden' name='to' value='" . HTMLEntities($row['login'], ENT_COMPAT, $settings->getSetting("charset", "UTF-8")) . "'><input type='hidden' name='amount' value='$amt'><input type='submit' class='button' value='$msg'></form>", true);
        Nav::add("", "bank.php?op=transfer3");
    } elseif (Database::numRows($result) > 100) {
        $output->output("`@Elessa`6 looks at you disdainfully and coldly, but politely, suggests you try narrowing down the field of who you want to send money to just a little bit!`n`n");
        $msg = Translator::translateInline("Preview Transfer");
        $output->rawOutput("<form action='bank.php?op=transfer2' method='POST'>");
        $output->output("Transfer how much: ");
        $output->rawOutput("<input name='amount' id='amount' width='5' value='$amt'><br>");
        $output->output("To: ");
        $output->rawOutput("<input name='to' value='$to'>");
        $output->output(" (partial names are ok, you will be asked to confirm the transaction before it occurs).`n");
        $output->rawOutput("<input type='submit' class='button' value='$msg'></form>");
        $output->rawOutput("<script language='javascript'>document.getElementById('amount').focus();</script>", true);
        Nav::add("", "bank.php?op=transfer2");
    } elseif (Database::numRows($result) > 1) {
        $output->rawOutput("<form action='bank.php?op=transfer3' method='POST'>");
                $output->rawOutput("<label for='bank_to'>");
                $output->output("`6Transfer `^%s`6 to ", $amt);
                $output->rawOutput("</label>");
                $output->rawOutput("<select name='to' id='bank_to' class='input'>");
        while ($row = Database::fetchAssoc($result)) {
            $output->rawOutput("<option value=\"" . HTMLEntities($row['login'], ENT_COMPAT, $settings->getSetting("charset", "UTF-8")) . "\">" . Sanitize::fullSanitize($row['name']) . "</option>");
        }
        $msg = Translator::translateInline("Complete Transfer");
        $output->rawOutput("</select><input type='hidden' name='amount' value='$amt'><input type='submit' class='button' value='$msg'></form>", true);
        Nav::add("", "bank.php?op=transfer3");
    } else {
        $output->output("`@Elessa`6 blinks at you from behind her spectacles, \"`@I'm sorry, but I can find no one matching that name who does business with our bank!  Please try again.`6\"");
    }
} elseif ($op == "transfer3") {
    $amt = abs((int)Http::post('amount'));
    $to = Http::post('to');
    $output->output("`6`bTransfer Completion`b`n");
    if ($session['user']['gold'] + $session['user']['goldinbank'] < $amt) {
        $output->output("`@Elessa`6 stands up to her full, but still diminutive height and glares at you, \"`@How can you transfer `^%s`@ gold when you only possess `^%s`@?`6\"", number_format($amt, 0, $point, $sep), number_format($session['user']['gold'] + $session['user']['goldinbank'], 0, $point, $sep));
    } else {
        $sql = "SELECT name,acctid,level,transferredtoday FROM " . Database::prefix("accounts") . " WHERE login='$to'";
        $result = Database::query($sql);
        if (Database::numRows($result) == 1) {
            $row = Database::fetchAssoc($result);
            $maxout = $session['user']['level'] * $settings->getSetting("maxtransferout", 25);
            $maxtfer = $row['level'] * $settings->getSetting("transferperlevel", 25);
            if ($session['user']['amountouttoday'] + $amt > $maxout) {
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; you are not allowed to transfer more than `^%s`@ gold total per day.`6\"", $maxout);
            } elseif ($maxtfer < $amt) {
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; `&%s`@ may only receive up to `^%s`@ gold per day.`6\"", $row['name'], $maxtfer);
            } elseif ($row['transferredtoday'] >= $settings->getSetting("transferreceive", 3)) {
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; `&%s`@ has received too many transfers today, you will have to wait until tomorrow.`6\"", $row['name']);
            } elseif ($amt < (int)$session['user']['level']) {
                $output->output("`@Elessa`6 shakes her head, \"`@I'm sorry, but I cannot complete that transfer; you might want to send a worthwhile transfer, at least as much as your level.`6\"");
            } elseif ($row['acctid'] == $session['user']['acctid']) {
                $output->output("`@Elessa`6 glares at you, her eyes flashing dangerously, \"`@You may not transfer money to yourself!  That makes no sense!`6\"");
            } else {
                debuglog("transferred $amt gold to", $row['acctid']);
                $session['user']['gold'] -= $amt;
                if ($session['user']['gold'] < 0) {
                    //withdraw in case they don't have enough on hand.
                    $session['user']['goldinbank'] += $session['user']['gold'];
                    $session['user']['gold'] = 0;
                }
                $session['user']['amountouttoday'] += $amt;
                $sql = "UPDATE " . Database::prefix("accounts") . " SET goldinbank=goldinbank+$amt,transferredtoday=transferredtoday+1 WHERE acctid='{$row['acctid']}'";
                Database::query($sql);
                $output->output("`@Elessa`6 smiles, \"`@The transfer has been completed!`6\"");
                $subj = array("`^You have received a money transfer!`0");
                $body = array("`&%s`6 has transferred `^%s`6 gold to your bank account!",$session['user']['name'],$amt);
                Mail::systemMail($row['acctid'], $subj, $body);
            }
        } else {
            $output->output("`@Elessa`6 looks up from her ledger with a bit of surprise on her face, \"`@I'm terribly sorry, but I seem to have run into an accounting error, would you please try telling me what you wish to transfer again?`6\"");
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
    $amount = abs((int)Http::post('amount'));
    if ($amount == 0) {
        $amount = $session['user']['gold'];
    }
    $notenough = Translator::translateInline("`\$ERROR: Not enough gold in hand to deposit.`n`n`^You plunk your `&%s`^ gold on the counter and declare that you would like to deposit all `&%s`^ gold of it.`n`n`@Elessa`6 stares blandly at you for a few seconds until you become self conscious and recount your money, realizing your mistake.");
    $depositdebt = Translator::translateInline("`@Elessa`6 records your deposit of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a debt of `\$%s`@ gold to the bank and `^%s`@ gold in hand.`6\"");
    $depositbalance = Translator::translateInline("`@Elessa`6 records your deposit of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a balance of `^%s`@ gold in the bank and `^%s`@ gold in hand.`6\"");
    if ($amount > $session['user']['gold']) {
        $output->outputNotl($notenough, number_format($session['user']['gold'], 0, $point, $sep), number_format($amount, 0, $point, $sep));
    } else {
        debuglog("deposited " . $amount . " gold in the bank");
        $session['user']['goldinbank'] += $amount;
        $session['user']['gold'] -= $amount;
        $output->outputNotl($session['user']['goldinbank'] >= 0 ? $depositbalance : $depositdebt, number_format($amount, 0, $point, $sep), $session['user']['name'], number_format(abs($session['user']['goldinbank']), 0, $point, $sep), number_format($session['user']['gold'], 0, $point, $sep));
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
    $amount = abs((int)Http::post('amount'));
    if ($amount == 0) {
        $amount = abs($session['user']['goldinbank']);
    }
    if ($amount > $session['user']['goldinbank'] && Http::post('borrow') == "") {
        $output->output("`\$ERROR: Not enough gold in the bank to withdraw.`^`n`n");
        $output->output("`6Having been informed that you have `^%s`6 gold in your account, you declare that you would like to withdraw all `^%s`6 of it.`n`n", number_format($session['user']['goldinbank'], 0, $point, $sep), number_format($amount, 0, $point, $sep));
        $output->output("`@Elessa`6 looks at you for a few moments without blinking, then advises you to take basic arithmetic.  You realize your folly and think you should try again.");
    } elseif ($amount > $session['user']['goldinbank']) {
        $lefttoborrow = $amount;
        $didwithdraw = 0;
        $maxborrow = $session['user']['level'] * $settings->getSetting("borrowperlevel", 20);
        if ($lefttoborrow <= $session['user']['goldinbank'] + $maxborrow) {
            if ($session['user']['goldinbank'] > 0) {
                $output->output("`6You withdraw your remaining `^%s`6 gold.", number_format($session['user']['goldinbank'], 0, $point, $sep));
                $lefttoborrow -= $session['user']['goldinbank'];
                $session['user']['gold'] += $session['user']['goldinbank'];
                $session['user']['goldinbank'] = 0;
                debuglog("withdrew $amount gold from the bank");
                $didwithdraw = 1;
            }
            if ($lefttoborrow - $session['user']['goldinbank'] > $maxborrow) {
                if ($didwithdraw) {
                    $output->output("`6Additionally, you ask to borrow `^%s`6 gold.", number_format($leftoborrow, 0, $point, $sep));
                } else {
                    $output->output("`6You ask to borrow `^%s`6 gold.", number_format($lefttoborrow, 0, $point, $sep));
                }
                $output->output("`@Elessa`6 looks up your account and informs you that you may only borrow up to `^%s`6 gold.", number_format($maxborrow, 0, $point, $sep));
            } else {
                if ($didwithdraw) {
                    $output->output("`6Additionally, you borrow `^%s`6 gold.", number_format($lefttoborrow, 0, $point, $sep));
                } else {
                    $output->output("`6You borrow `^%s`6 gold.", number_format($lefttoborrow, 0, $point, $sep));
                }
                $session['user']['goldinbank'] -= $lefttoborrow;
                $session['user']['gold'] += $lefttoborrow;
                debuglog("borrows $lefttoborrow gold from the bank");
                $output->output("`@Elessa`6 records your withdrawal of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a debt of `\$%s`@ gold to the bank and `^%s`@ gold in hand.`6\"", number_format($amount, 0, $point, $sep), $session['user']['name'], number_format(abs($session['user']['goldinbank']), 0, $point, $sep), number_format($session['user']['gold'], 0, $point, $sep));
            }
        } else {
            $output->output("`6Considering the `^%s`6 gold in your account, you ask to borrow `^%s`6. `@Elessa`6 peers through her ledger, runs a few calculations and then informs you that, at your level, you may only borrow up to a total of `^%s`6 gold.", number_format($session['user']['goldinbank'], 0, $point, $sep), number_format($lefttoborrow - $session['user']['goldinbank'], 0, $point, $sep), number_format($maxborrow, 0, $point, $sep));
        }
    } else {
        $session['user']['goldinbank'] -= $amount;
        $session['user']['gold'] += $amount;
        debuglog("withdrew $amount gold from the bank");
        $output->output("`@Elessa`6 records your withdrawal of `^%s `6gold in her ledger. \"`@Thank you, `&%s`@.  You now have a balance of `^%s`@ gold in the bank and `^%s`@ gold in hand.`6\"", number_format($amount, 0, $point, $sep), $session['user']['name'], number_format(abs($session['user']['goldinbank']), 0, $point, $sep), number_format($session['user']['gold'], 0, $point, $sep));
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
