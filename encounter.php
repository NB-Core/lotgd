<?php

declare(strict_types=1);

use Lotgd\Action;
use Lotgd\Action\ActionResult;
use Lotgd\Encounter;
use Lotgd\Forms;
use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Realtime\Poll;
use Lotgd\Sanitize;
use Lotgd\Settings;
use Lotgd\Translator;

/**
 * The duelling ground (docs/ReactiveCore.md, Phase 7): who is online, a
 * challenge to one of them, and the encounter it opens. Every change is a
 * POST inside Action::run(); the GETs only display. Both pages show the
 * regions `presence` and `encounter`, which realtime keeps current.
 */

require_once __DIR__ . '/common.php';

Translator::getInstance()->setSchema('encounter');

$output = Output::getInstance();
$settings = Settings::getInstance();
$op = (string) Http::get('op');
$id = (int) Http::get('id');
$me = (int) $session['user']['acctid'];
$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
// Asked once: a refusal is logged by the guard itself.
$unverified = $isPost && Forms::isUnverifiedRequest();

Header::pageHeader('The Duelling Ground');
$output->output('`c`b`$The Duelling Ground`b`c`n');

/**
 * A button posting to $link with the form's token and a new action id.
 */
$button = static function (string $link, string $label, string $field = '', string $value = ''): string {
    $extra = $field !== '' ? "<input type='hidden' name='" . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . "' value='"
        . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "'>" : '';
    Nav::add('', $link);

    return "<form action='" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "' method='POST' style='display:inline'>"
        . Forms::csrfField() . Action::idField() . $extra
        . "<input type='submit' class='button' value='"
        . htmlspecialchars(Sanitize::stripAllColorCodes(Translator::translateInline($label)), ENT_QUOTES, 'UTF-8') . "'></form> ";
};

$refused = static function (ActionResult $result) use ($output): void {
    if ($result->status === ActionResult::CONFLICT) {
        $output->output("`7Your account has changed since this page was shown. Nothing was done; please look again.`n`n");

        return;
    }
    $output->output(match ($result->reason) {
        'opponent-away' => "`7They are not around to be challenged: only someone in the village, the inn, the gardens or here can be.`n`n",
        'opponent-busy' => "`7They are already in an encounter.`n`n",
        'already-in-encounter' => "`7You are already in an encounter.`n`n",
        'grant-used', 'encounter-cancelled' => "`7That challenge was withdrawn or already answered.`n`n",
        'grant-expired', 'encounter-expired' => "`7That challenge has passed.`n`n",
        'action-id-reused', 'missing-action-id' => "`7This form was already used. Please look again.`n`n",
        'pvp-off' => "`7Serious duels are not fought here: PvP is turned off.`n`n",
        'pvp-range' => "`7A serious duel is only fought between warriors close in level.`n`n",
        'pvp-immune' => "`7One of you is still new here and protected from serious fights.`n`n",
        'no-fights' => "`7No serious fight is left for the challenger today.`n`n",
        'not-alive' => "`7Only the living fight serious duels.`n`n",
        'duel-not-over' => "`7A rematch is only asked for once the duel is over.`n`n",
        default => "`7That cannot be done now.`n`n",
    });
};

if (!Encounter::enabled()) {
    $output->output('`7The duelling ground is closed.');
} elseif ($unverified) {
    http_response_code(400);
    $output->output("`7The herald cannot take this form; nothing was done. Please look again.`n`n");
} elseif ($isPost) {
    $action = match ($op) {
        'challenge' => ['encounter.challenge', [
            'target' => (int) Http::post('target'),
            'stakes' => Http::post('stakes') === Encounter::SERIOUS ? Encounter::SERIOUS : Encounter::FRIENDLY,
        ]],
        'answer' => ['encounter.answer', ['encounter' => $id, 'accept' => Http::post('answer') === 'accept']],
        'cancel' => ['encounter.cancel', ['encounter' => $id]],
        'leave' => ['encounter.leave', ['encounter' => $id]],
        'rematch' => ['encounter.rematch', ['encounter' => $id]],
        default => null,
    };
    if ($action === null) {
        $output->output('`7That cannot be done here.');
    } else {
        [$name, $params] = $action;
        $user = $session['user'];
        $result = Action::run($name, Action::postedId(), $params, static fn (): array => match ($name) {
            'encounter.challenge' => Encounter::challenge($user, $params['target'], $params['stakes']),
            'encounter.answer' => Encounter::answer($user, $params['encounter'], $params['accept']),
            'encounter.cancel' => Encounter::cancel($user, $params['encounter']),
            'encounter.rematch' => Encounter::rematch($user, $params['encounter']),
            default => Encounter::leave($user, $params['encounter']),
        });
        if ($result->status === ActionResult::DONE || $result->status === ActionResult::REPLAYED) {
            $id = (int) ($result->data['id'] ?? 0);
            $op = 'view';
        } else {
            $refused($result);
            $op = $id > 0 ? 'view' : '';
        }
    }
}

if (Encounter::enabled() && !$unverified) {
    // A player whose page cannot play live learns why, and where to change it.
    $output->rawOutput(Encounter::liveHintHtml($session['user']));
    // The versions before the content: a change in between comes again.
    Poll::noteRegionVersion('encounter', Poll::regionVersion('encounter', $me));
    Poll::noteRegionVersion('presence', Poll::regionVersion('presence', $me));
    $encounter = $op === 'view' && $id > 0 ? Encounter::find($id, $me) : null;
    $watched = $op === 'watch' && $id > 0 ? Encounter::watchable($id) : null;
    if ($watched !== null) {
        // A spectator: the duel as anyone sees it, kept current by realtime
        // on the encounter's channel while this page is shown. Loaded as a
        // whole page (not on PartialMode's list): the stream it opens then
        // reads the session that names the duel.
        Encounter::watch($id);
        Poll::noteRegionVersion('duelwatch', Encounter::watchVersion());
        Nav::add('Encounter');
        Nav::add('Look again', "encounter.php?op=watch&id={$id}");
        Nav::add('Who is around', 'encounter.php');
        $output->output("`7You find a place among the onlookers.`n`n");
        $output->rawOutput("<div data-lotgd-region='duelwatch'>" . Encounter::watchHtml() . '</div>');
    } elseif ($op === 'watch') {
        $output->output("`7There is no such duel to watch.`n`n");
        Nav::add('Encounter');
        Nav::add('Who is around', 'encounter.php');
    } elseif ($encounter !== null) {
        $view = Encounter::viewLink($id);
        $state = (string) $encounter['state'];
        $challenging = (int) $encounter['challenger'] === $me;
        // Registered first: the region links to the page it is on.
        Nav::add('Encounter');
        Nav::add('Look again', $view);
        Nav::add('Who is around', 'encounter.php');
        $output->rawOutput("<div data-lotgd-region='encounter'>" . Encounter::regionHtml($me) . '</div>');
        // The controls of the state shown: outside the region, so the client
        // hides them when the region moves on (lotgd.duel.js).
        $output->rawOutput("<div data-lotgd-encounter-controls='{$id}' data-lotgd-encounter-state='"
            . htmlspecialchars($state, ENT_QUOTES, 'UTF-8') . "'>");
        if ($state === Encounter::PENDING && (string) $encounter['stakes'] === Encounter::SERIOUS) {
            $output->output("`n`\$This is a serious duel: the loser dies, and loses gold and experience as in any player fight.`0`n`n");
        }
        if ($state === Encounter::PENDING && $challenging) {
            $output->rawOutput($button("encounter.php?op=cancel&id={$id}", 'Withdraw the challenge'));
        } elseif ($state === Encounter::PENDING) {
            $output->rawOutput(
                $button("encounter.php?op=answer&id={$id}", 'Accept', 'answer', 'accept')
                . $button("encounter.php?op=answer&id={$id}", 'Decline', 'answer', 'decline')
            );
        } elseif ($state === Encounter::ACCEPTED) {
            $output->output("`n`7You face each other on the duelling ground. Each round you both choose at once: attack, guard -- half your attack, half again your defense -- or give up. Whoever does not choose in time attacks.`n`n");
            // Giving up is final -- in a serious duel it kills: the core's
            // destructive-action button, with its confirmation.
            $leave = "encounter.php?op=leave&id={$id}";
            Nav::add('', $leave);
            $fields = [Action::FIELD => Action::newId()];
            if (isset($session['user']['version'])) {
                $fields[Action::VERSION_FIELD] = (string) (int) $session['user']['version'];
            }
            $output->rawOutput(Forms::postButton(
                $leave,
                Sanitize::stripAllColorCodes(Translator::translateInline('Give up and leave')),
                Translator::translateInline((string) $encounter['stakes'] === Encounter::SERIOUS
                    ? 'Give up this serious duel? You will die and lose gold and experience.'
                    : 'Give up the duel?'),
                'button',
                null,
                $fields
            ));
        }
        $output->rawOutput('</div>');
        if ((string) $encounter['kind'] === Encounter::KIND_DUEL && in_array($state, [Encounter::PENDING, Encounter::ACCEPTED, Encounter::ENDED], true)) {
            // Shown once the duel is over -- also when it is accepted and ends
            // while this page is open: lotgd.duel.js shows controls whose state
            // the region reaches.
            $output->rawOutput("<div data-lotgd-encounter-controls='{$id}' data-lotgd-encounter-state='" . Encounter::ENDED . "'"
                . ($state === Encounter::ENDED ? '' : ' hidden') . '>');
            $output->rawOutput($button("encounter.php?op=rematch&id={$id}", 'Challenge again (rematch)'));
            $output->rawOutput('</div>');
        }
    } else {
        if ($op === 'view') {
            $output->output("`7There is no such encounter for you.`n`n");
        }
        $latest = Encounter::latest($me);
        if ($latest !== null && in_array((string) $latest['state'], [Encounter::PENDING, Encounter::ACCEPTED], true)) {
            Nav::add('Encounter');
            Nav::add('Your encounter', Encounter::viewLink((int) $latest['id']));
            $output->rawOutput("<div data-lotgd-region='encounter'>" . Encounter::regionHtml($me) . '</div>');
        } else {
            $output->rawOutput("<div data-lotgd-region='encounter'></div>");
        }
        $output->output("`7Warriors who are about now:`n");
        // One form around the live list: its buttons name the opponent.
        Nav::add('', 'encounter.php?op=challenge');
        $stakes = "<p><label><input type='radio' name='stakes' value='" . Encounter::FRIENDLY . "' checked> "
            . htmlspecialchars(Translator::translateInline('A friendly duel: nothing but the honour'), ENT_QUOTES, 'UTF-8') . '</label>';
        if ((int) $settings->getSetting('pvp', 1)) {
            $stakes .= " <label><input type='radio' name='stakes' value='" . Encounter::SERIOUS . "'> "
                . htmlspecialchars(Translator::translateInline('A serious duel: the loser dies'), ENT_QUOTES, 'UTF-8') . '</label>';
        }
        $output->rawOutput(
            "<form action='encounter.php?op=challenge' method='POST'>" . Forms::csrfField() . Action::idField()
            . $stakes . '</p>'
            . "<div data-lotgd-region='presence'>" . Encounter::presenceHtml($me) . '</div></form>'
        );
        $running = Encounter::running();
        if ($running !== []) {
            $output->output("`n`7Duels being fought:`n");
            foreach ($running as $duel) {
                $watch = 'encounter.php?op=watch&id=' . (int) $duel['id'];
                Nav::add('', $watch);
                $output->rawOutput('<p>');
                $output->outputNotl(
                    '%s `7vs.`0 %s`0 %s ',
                    (string) $duel['challenger_name'],
                    (string) $duel['opponent_name'],
                    (string) $duel['stakes'] === Encounter::SERIOUS ? Translator::translateInline('`$(serious)`0') : Translator::translateInline('`@(friendly)`0')
                );
                $output->rawOutput("<a href='" . htmlspecialchars($watch, ENT_QUOTES, 'UTF-8') . "' class='nav'>"
                    . htmlspecialchars(Translator::translateInline('Watch'), ENT_QUOTES, 'UTF-8') . '</a></p>');
            }
        }
        Nav::add('Encounter');
        Nav::add('Look again', 'encounter.php');
    }
}

Nav::add('Navigation');
VillageNav::render();

Footer::pageFooter();
