<?php

declare(strict_types=1);

use Lotgd\Action;
use Lotgd\Action\ActionResult;
use Lotgd\Clan\Application;
use Lotgd\Forms;
use Lotgd\Grant;
use Lotgd\Http;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Sanitize;
use Lotgd\Translator;

/**
 * Accepting a clan applicant through a grant (docs/ReactiveCore.md, Phase 5).
 * The GET shows the application; the POST accepts it, inside a guarded
 * action that closes the offer for every officer.
 */

    Header::pageHeader("Clan Halls");
    $output->output("`b`c`&Clan Halls`c`b");

    $grantId = (int) Http::get('grant');
    $link = Application::VIEW_LINK . '&grant=' . $grantId;
    $isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';

if (!Grant::enabled() || $grantId <= 0) {
    $output->output("`7There is nothing waiting for you here.");
} elseif ($isPost) {
    if (Forms::isUnverifiedRequest()) {
        http_response_code(400);
        $output->output("`7The registrar cannot take this form; nothing has been decided. Please open the application again.");
    } else {
        $result = Action::run(
            Application::ACTION,
            Action::postedId(),
            ['grant' => $grantId],
            static function () use ($grantId, $session): array {
                return Application::accept($grantId, $session['user'], Action::postedId());
            }
        );
        switch ($result->status) {
            case ActionResult::DONE:
            case ActionResult::REPLAYED:
                $output->output("`&%s`7 is now a member of your clan.", (string) ($result->data['name'] ?? ''));
                break;
            case ActionResult::CONFLICT:
                $output->output("`7Your account has changed since you opened the application. Nothing has been decided; please open it again.");
                break;
            default:
                $output->output(match ($result->reason) {
                    'grant-used' => "`7Another officer has already decided on this application.",
                    'grant-expired' => "`7This application has waited too long; it is no longer open.",
                    'applicant-gone' => "`7The applicant has withdrawn the application.",
                    'not-officer' => "`7You are no longer an officer of this clan.",
                    default => "`7This application can no longer be decided here.",
                });
                break;
        }
    }
} else {
    $grant = Grant::find($grantId, (int) $session['user']['acctid']);
    if ($grant === null) {
        $output->output("`7This application has already been decided, or is no longer open.");
    } else {
        $applicant = (int) ($grant['target']['applicant'] ?? 0);
        $name = (string) Database::getDoctrineConnection()->fetchOne(
            'SELECT name FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
            ['acctid' => $applicant],
            ['acctid' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $output->output("`7The registrar hands you an application form. It reads: `&%s`7.`n`n", $name);
        $output->output("Accept this applicant into your clan? Every other officer will see the application as decided.`n`n");
        $output->rawOutput(
            "<form action='" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "' method='POST'>"
            . Forms::csrfField() . Action::idField()
            . "<input type='submit' class='button' value='"
            . htmlspecialchars(Sanitize::stripAllColorCodes(Translator::translateInline('Accept')), ENT_QUOTES, 'UTF-8') . "'>"
            . '</form>'
        );
        Nav::add('', $link);
    }
}

    Nav::add("Navigation");
    Nav::add("Return to your Clan Rooms", "clan.php");
