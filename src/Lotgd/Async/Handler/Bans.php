<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Doctrine\DBAL\ParameterType;
use Jaxon\Response\Response;
use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\Translator;

use function Jaxon\jaxon;

/**
 * Handle asynchronous lookups for accounts affected by a ban rule.
 */
class Bans
{
    /**
     * Return the formatted list of accounts affected by the given ban.
     */
    public function affectedUsers(string $ipFilter, string $uniqueId, string $targetId = 'user0'): Response
    {
        $ipFilter = trim($ipFilter);
        $uniqueId = trim($uniqueId);
        $targetId = $targetId !== '' ? $targetId : 'user0';

        $connection = Database::getDoctrineConnection();

        $bansTable = Database::prefix('bans');
        $accountsTable = Database::prefix('accounts');

        $sql = <<<SQL
            SELECT DISTINCT a.name
            FROM {$bansTable} AS b
            INNER JOIN {$accountsTable} AS a ON (
                (SUBSTRING(a.lastip, 1, LENGTH(b.ipfilter)) = b.ipfilter AND b.ipfilter <> '')
                OR (b.uniqueid = a.uniqueid AND b.uniqueid <> '')
            )
            WHERE b.ipfilter = :ipFilter
              AND b.uniqueid = :uniqueId
        SQL;

        $rows = $connection->fetchAllAssociative(
            $sql,
            [
                'ipFilter' => $ipFilter,
                'uniqueId' => $uniqueId,
            ],
            [
                'ipFilter' => ParameterType::STRING,
                'uniqueId' => ParameterType::STRING,
            ]
        );

        $output = Output::getInstance();
        $none = Translator::translateInline('NONE');

        $names = [];
        foreach ($rows as $row) {
            if (! isset($row['name'])) {
                continue;
            }
            $names[] = $output->appoencode('`0' . (string) $row['name']);
        }

        if ($names === []) {
            $names[] = $output->appoencode($none);
        }

        $response = jaxon()->newResponse();
        $response->assign($targetId, 'innerHTML', implode('<br>', $names));

        return $response;
    }
}
