<?php

declare(strict_types=1);

namespace Lotgd;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Lotgd\MySQL\Database;
use Throwable;

final class PlayerSearch
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 250;
    private const LIKE_ESCAPE = '!';

    /**
     * @var array<int|string, string>
     */
    private const DEFAULT_COLUMNS = ['acctid', 'login', 'name'];

    /**
     * @var Connection|object
     */
    private $connection;

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? Database::getDoctrineConnection();
    }

    /**
     * Find players that exactly match a login name.
     *
     * @param array<int|string, string>|null $columns
     *
     * @return array<int, array<string, mixed>>
     */
    public function findExactLogin(string $login, ?array $columns = null): array
    {
        return $this->executeSearch([
            'columns' => $columns,
            'limit'   => 1,
            'loginExact' => $login,
            'alphabeticalColumn' => 'a.login',
        ]);
    }

    /**
     * Find players by a display name pattern. The pattern may include SQL LIKE wildcards.
     *
     * @param array<int|string, string>|null $columns
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByDisplayNamePattern(
        string $pattern,
        ?array $columns = null,
        ?int $limit = null,
        ?string $exactName = null
    ): array {
        return $this->executeSearch([
            'columns' => $columns,
            'limit'   => $limit,
            'namePattern' => $pattern,
            'nameExact'   => $exactName,
        ]);
    }

    /**
     * Find players by a display name using a character-spaced wildcard search (e.g. %N%A%M%E%).
     *
     * @param array<int|string, string>|null $columns
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByDisplayNameFuzzy(
        string $search,
        ?array $columns = null,
        ?int $limit = null,
        ?string $exactName = null
    ): array {
        return $this->executeSearch([
            'columns' => $columns,
            'limit'   => $limit,
            'namePattern' => '%' . $this->escapeLikeWildcards($search) . '%',
            'nameCharacterPattern' => $this->buildCharacterWildcardPattern($search),
            'nameExact'   => $exactName,
        ]);
    }

    /**
     * Find potential transfer targets by combining an exact login match with a display name search.
     *
     * @param array<int|string, string>|null $columns
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForTransfer(string $search, ?array $columns = null, ?int $limit = null): array
    {
        $escaped = $this->escapeLikeWildcards($search);

        return $this->executeSearch([
            'columns' => $columns,
            'limit'   => $limit,
            'loginExact'  => $search,
            'namePattern' => '%' . $escaped . '%',
            'nameCharacterPattern' => $this->buildCharacterWildcardPattern($search),
            'nameExact'   => $search,
        ]);
    }

    /**
     * @param array{
     *     columns?: array<int|string, string>|null,
     *     limit?: int|null,
     *     loginExact?: string|null,
     *     namePattern?: string|null,
     *     nameExact?: string|null,
     *     nameCharacterPattern?: string|null,
     *     alphabeticalColumn?: string|null
     * } $options
     *
     * @return array<int, array<string, mixed>>
     */
    private function executeSearch(array $options): array
    {
        $columns = $this->normaliseColumns($options['columns'] ?? null);
        $limit   = $this->normaliseLimit($options['limit'] ?? null);
        $alphabetical = $this->normaliseAlphabeticalColumn($options['alphabeticalColumn'] ?? 'a.name');
        $conditions = [];
        $orderPriorities = [];
        $parameters = [];

        if (isset($options['loginExact'])) {
            $conditions[] = 'a.login = :loginExact';
            $parameters['loginExact'] = $options['loginExact'];
            $orderPriorities[] = ['column' => 'a.login', 'parameter' => 'loginExact'];
        }

        if (isset($options['namePattern'])) {
            $conditions[] = sprintf("a.name LIKE :namePattern ESCAPE '%s'", self::LIKE_ESCAPE);
            $parameters['namePattern'] = $options['namePattern'];
        }

        if (isset($options['nameCharacterPattern'])) {
            $conditions[] = sprintf("a.name LIKE :nameCharacterPattern ESCAPE '%s'", self::LIKE_ESCAPE);
            $parameters['nameCharacterPattern'] = $options['nameCharacterPattern'];
        }

        if (isset($options['nameExact'])) {
            $parameters['nameExact'] = $options['nameExact'];
            $orderPriorities[] = ['column' => 'a.name', 'parameter' => 'nameExact'];
        }

        if ($conditions === []) {
            throw new InvalidArgumentException('At least one search criterion must be provided.');
        }

        $wrapped = array_map(
            static fn(string $condition): string => '(' . $condition . ')',
            $conditions
        );

        $sql = sprintf(
            'SELECT %s FROM accounts a WHERE %s ORDER BY %s LIMIT %d',
            implode(', ', $columns),
            implode(' OR ', $wrapped),
            $this->buildOrderByClause($orderPriorities, $alphabetical),
            $limit
        );

        return $this->connection->executeQuery($sql, $parameters)->fetchAllAssociative();
    }

    /**
     * @param array<int|string, string>|null $columns
     *
     * @return array<int, string>
     */
    private function normaliseColumns(?array $columns): array
    {
        $columns ??= self::DEFAULT_COLUMNS;
        $selects = [];

        foreach ($columns as $key => $value) {
            if (is_int($key)) {
                $column = $value;
                $alias  = $value;
            } else {
                $column = (string) $key;
                $alias  = (string) $value;
            }

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
                throw new InvalidArgumentException(sprintf('Invalid column name "%s" supplied.', $column));
            }

            if ($alias !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
                throw new InvalidArgumentException(sprintf('Invalid column alias "%s" supplied.', $alias));
            }

            $qualified = 'a.' . $column;
            if ($alias !== null && $alias !== $column) {
                $selects[] = sprintf('%s AS %s', $qualified, $alias);
            } else {
                $selects[] = $qualified;
            }
        }

        return $selects;
    }

    private function normaliseLimit(?int $limit): int
    {
        $limit = $limit ?? self::DEFAULT_LIMIT;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    private function normaliseAlphabeticalColumn(string $column): string
    {
        if (!preg_match('/^a\.[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException('Alphabetical column must reference the accounts table alias.');
        }

        return $column;
    }

    private function escapeLikeWildcards(string $value): string
    {
        try {
            if (method_exists($this->connection, 'getDatabasePlatform')) {
                $platform = $this->connection->getDatabasePlatform();
                if ($platform && method_exists($platform, 'escapeStringForLike')) {
                    return $platform->escapeStringForLike($value, self::LIKE_ESCAPE);
                }
            }
        } catch (Throwable $exception) {
            // Ignore and fall back to manual escaping below.
        }

        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $value
        );
    }

    private function buildCharacterWildcardPattern(string $search): string
    {
        if ($search === '') {
            return '%%';
        }

        $characters = preg_split('//u', $search, -1, PREG_SPLIT_NO_EMPTY);
        $escaped = array_map(fn(string $char): string => $this->escapeLikeWildcards($char), $characters);

        return '%' . implode('%', $escaped) . '%';
    }

    /**
     * @param array<int, array{column: string, parameter: string}> $orderPriorities
     */
    private function buildOrderByClause(array $orderPriorities, string $alphabetical): string
    {
        $orderParts = [];

        foreach ($orderPriorities as $priority) {
            $orderParts[] = sprintf(
                'CASE WHEN %s = :%s THEN 0 ELSE 1 END ASC',
                $priority['column'],
                $priority['parameter']
            );
        }

        $orderParts[] = sprintf('LOWER(%s) ASC', $alphabetical);
        $orderParts[] = 'a.acctid ASC';

        return implode(', ', $orderParts);
    }
}
