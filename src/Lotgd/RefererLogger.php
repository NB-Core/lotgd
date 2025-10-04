<?php

declare(strict_types=1);

namespace Lotgd;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;

final class RefererLogger
{
    private const CONTROL_PATTERN = '/[\x00-\x1F\x7F]/';

    /**
     * @param array<string,mixed> $server
     */
    public static function log(array $server, string $requestUri, string $remoteAddr): void
    {
        $refererValue = isset($server['HTTP_REFERER']) && is_string($server['HTTP_REFERER'])
            ? $server['HTTP_REFERER']
            : '';
        $hostValue = isset($server['HTTP_HOST']) && is_string($server['HTTP_HOST'])
            ? $server['HTTP_HOST']
            : '';

        if ($refererValue === '' || $hostValue === '') {
            return;
        }

        if (preg_match(self::CONTROL_PATTERN, $refererValue) === 1 || preg_match(self::CONTROL_PATTERN, $hostValue) === 1) {
            return;
        }

        $rawReferer = trim($refererValue);
        $rawHostHeader = trim($hostValue);

        if ($rawReferer === '' || $rawHostHeader === '') {
            return;
        }

        $scheme = strtolower((string) parse_url($rawReferer, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return;
        }

        $refererHost = parse_url($rawReferer, PHP_URL_HOST);
        if (!is_string($refererHost) || $refererHost === '') {
            return;
        }

        $refererPort = parse_url($rawReferer, PHP_URL_PORT);
        $site = strtolower($refererHost);
        if (
            $refererPort !== null
            && !($refererPort === 80 && $scheme === 'http')
            && !($refererPort === 443 && $scheme === 'https')
        ) {
            $site .= ':' . $refererPort;
        }

        $hostHeader = strtolower($rawHostHeader);
        $hostHeader = preg_replace('/:(80|443)$/', '', $hostHeader) ?? '';
        $siteForComparison = preg_replace('/:(80|443)$/', '', $site) ?? '';

        if ($siteForComparison === $hostHeader) {
            return;
        }

        $requestUri = trim($requestUri);
        if ($requestUri !== '' && preg_match(self::CONTROL_PATTERN, $requestUri) === 1) {
            $requestUri = '';
        }
        if ($requestUri !== '' && $requestUri[0] !== '/') {
            $requestUri = '/' . $requestUri;
        }

        $dest = $hostHeader;
        if ($requestUri !== '') {
            $dest .= $requestUri;
        }

        $remoteValue = $remoteAddr;
        if (preg_match(self::CONTROL_PATTERN, $remoteValue) === 1) {
            $remoteValue = '';
        }

        $remoteAddr = trim($remoteValue);
        if ($remoteAddr !== '' && preg_match(self::CONTROL_PATTERN, $remoteAddr) === 1) {
            $remoteAddr = '';
        }

        $conn = Database::getDoctrineConnection();
        $table = Database::prefix('referers');
        $now = date('Y-m-d H:i:s');

        $existing = $conn->fetchAllAssociative(
            "SELECT refererid FROM {$table} WHERE uri = :uri",
            [
                'uri' => $rawReferer,
            ],
            [
                'uri' => ParameterType::STRING,
            ]
        );

        $row = $existing[0] ?? null;

        if (isset($row['refererid'])) {
            $conn->executeStatement(
                "UPDATE {$table} SET count = count + 1, last = :last, site = :site, dest = :dest, ip = :ip WHERE refererid = :id",
                [
                    'last' => $now,
                    'site' => $site,
                    'dest' => $dest,
                    'ip'   => $remoteAddr,
                    'id'   => (int) $row['refererid'],
                ],
                [
                    'last' => ParameterType::STRING,
                    'site' => ParameterType::STRING,
                    'dest' => ParameterType::STRING,
                    'ip'   => ParameterType::STRING,
                    'id'   => ParameterType::INTEGER,
                ]
            );

            return;
        }

        $conn->executeStatement(
            "INSERT INTO {$table} (uri, count, last, site, dest, ip) VALUES (:uri, :count, :last, :site, :dest, :ip)",
            [
                'uri'   => $rawReferer,
                'count' => 1,
                'last'  => $now,
                'site'  => $site,
                'dest'  => $dest,
                'ip'    => $remoteAddr,
            ],
            [
                'uri'   => ParameterType::STRING,
                'count' => ParameterType::INTEGER,
                'last'  => ParameterType::STRING,
                'site'  => ParameterType::STRING,
                'dest'  => ParameterType::STRING,
                'ip'    => ParameterType::STRING,
            ]
        );
    }
}
