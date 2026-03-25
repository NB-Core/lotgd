<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class LegacyHttpWrapperCompatibilityTest extends TestCase
{
    public function testLegacyWrappersEscapeButLotgdHttpReturnsRawValues(): void
    {
        $payload = $this->runIsolatedPhp(<<<'PHP'
$_GET['id'] = "ab'c";
$_POST['title'] = 'x"y';
echo json_encode([
    'legacy_get' => httpget('id'),
    'legacy_post' => httppost('title'),
    'raw_get' => \Lotgd\Http::get('id'),
    'raw_post' => \Lotgd\Http::post('title'),
], JSON_THROW_ON_ERROR);
PHP);

        $this->assertSame("ab\\'c", $payload['legacy_get'] ?? null);
        $this->assertSame('x\\"y', $payload['legacy_post'] ?? null);
        $this->assertSame("ab'c", $payload['raw_get'] ?? null);
        $this->assertSame('x"y', $payload['raw_post'] ?? null);
    }

    public function testLegacyPostParseEscapesOnlyLegacyWrapperOutput(): void
    {
        $payload = $this->runIsolatedPhp(<<<'PHP'
$_POST = ['title' => "O'Reilly"];
[, , $rawParameters] = \Lotgd\Http::postParse();
[, , $legacyParameters] = postparse();
echo json_encode([
    'raw' => $rawParameters,
    'legacy' => $legacyParameters,
], JSON_THROW_ON_ERROR);
PHP);

        $this->assertSame(["O'Reilly"], $payload['raw'] ?? null);
        $this->assertSame(["O\\'Reilly"], $payload['legacy'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function runIsolatedPhp(string $snippet): array
    {
        $root = dirname(__DIR__);
        $bootstrap = sprintf(
            "require %s;\nrequire %s;\n",
            var_export($root . '/autoload.php', true),
            var_export($root . '/lib/http.php', true)
        );

        $code = $bootstrap . "\n" . $snippet;
        $command = sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($code));
        $output = shell_exec($command);

        $this->assertNotNull($output, 'Isolated php process did not produce output.');
        $this->assertIsString($output);

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
