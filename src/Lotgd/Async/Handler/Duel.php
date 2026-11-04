<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Lotgd\Action;
use Lotgd\Action\ActionResult;
use Lotgd\Async\Response;
use Lotgd\Encounter;
use Lotgd\Encounter\Duel as RoundsOfADuel;
use Lotgd\GameLog;
use Lotgd\Security\Csrf;
use Lotgd\SecurityLog;

/**
 * A live duel's moves over the async endpoint (docs/ReactiveCore.md,
 * Phase 7): a choice for the current round, and the resolution of a round
 * whose time ran out. Both write, inside Action::run(), so the async token is
 * checked here whatever csrf_mode says. Each answers `duel: {status, state?,
 * reason?}`; what the round did reaches both pages as an announcement of the
 * encounter region.
 *
 * The action id is derived from the move, so a call repeated after a lost
 * answer gets the first one's result instead of running twice.
 */
class Duel
{
    public function choose(int $encounter, int $round, string $choice): Response
    {
        return $this->move('duel.choose', $encounter, $round, ['choice' => $choice], static fn (array $user): array => RoundsOfADuel::choose($user, $encounter, $round, $choice));
    }

    public function resolve(int $encounter, int $round): Response
    {
        return $this->move('duel.resolve', $encounter, $round, [], static fn (array $user): array => RoundsOfADuel::resolve($user, $encounter, $round));
    }

    /**
     * @param array<string,mixed>                          $params
     * @param callable(array<string,mixed>):array<string,mixed> $fn
     */
    private function move(string $name, int $encounter, int $round, array $params, callable $fn): Response
    {
        global $session;

        $response = new Response();
        $user = $session['user'] ?? [];
        $acctid = (int) ($user['acctid'] ?? 0);
        if ($acctid <= 0 || empty($user['loggedin']) || !Encounter::enabled()) {
            return $response->data('duel', ['status' => 'rejected', 'reason' => 'disabled']);
        }
        if (!Csrf::matches(Csrf::SCOPE_ASYNC, Csrf::requestToken())) {
            SecurityLog::event('Duel move refused: async token does not match', ['move' => $name], $acctid, GameLog::SEVERITY_WARNING, false);

            return $response->data('duel', ['status' => 'rejected', 'reason' => 'csrf_invalid']);
        }

        $params += ['encounter' => $encounter, 'round' => $round];
        $actionId = substr(hash('sha256', $name . '|' . $encounter . '|' . $round . '|' . $acctid . '|' . (string) json_encode($params)), 0, 32);
        $result = Action::run($name, $actionId, $params, static fn (): array => $fn($user));
        // A move is playing, as a page would count it.
        $response->data('session', ['remaining' => Timeout::touch($acctid)]);

        return $response->data('duel', match ($result->status) {
            ActionResult::DONE, ActionResult::REPLAYED => ['status' => 'done', 'state' => (string) ($result->data['state'] ?? '')],
            ActionResult::CONFLICT => ['status' => 'conflict'],
            default => ['status' => 'rejected', 'reason' => (string) $result->reason],
        });
    }
}
