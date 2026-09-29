<?php

declare(strict_types=1);

namespace Lotgd\Installer;

use Lotgd\Doctrine\MigrationRunner;
use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\Settings;

/**
 * Install the game from the command line, without the browser installer.
 *
 * Meant for containers, which already carry the database credentials in
 * their environment: `php bin/install --admin=Name`. It does not duplicate
 * the installer. It fills in what the browser pages would have asked for and
 * runs the same stages (writing dbconnect.php, migrations and the base data,
 * modules, the administrator, the completion marker), then checks the result
 * against the database rather than trusting the pages' output.
 *
 * There is no default administrator. The name is required, and the password
 * is either read from standard input or generated and shown once, so no
 * installation ever starts with an account someone else could guess.
 */
final class HeadlessInstall
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const GENERATED_PASSWORD_LENGTH = 24;

    /** Login names the game's own registration would accept, without spaces. */
    private const ADMIN_PATTERN = '/^[A-Za-z][A-Za-z0-9]{2,24}$/';

    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public const USAGE = <<<'TXT'
        Usage: php bin/install --admin=NAME [--password-stdin] [--modules=recommended|none]

        Installs the game into the database named by MYSQL_HOST, MYSQL_USER,
        MYSQL_PASSWORD and MYSQL_DATABASE, and creates the administrator NAME.

          --admin=NAME        Required. 3-25 letters and digits, starting with a letter.
          --password-stdin    Read the administrator password from the first line
                              of standard input (at least 12 characters). Without it
                              a random password is generated and printed once.
          --modules=SET       recommended (default) or none.

        Refuses to run on a database that already holds the game; updates apply
        themselves on the first page request.
        TXT;

    /**
     * @param array<string, string> $env Environment variables, usually getenv()
     */
    public function __construct(private array $env)
    {
    }

    /**
     * Read the command line.
     *
     * @param list<string> $argv   Arguments without the script name
     * @param resource|null $stdin Read for --password-stdin
     *
     * @return array{admin:string,password:string,generated:bool,modules:string}
     *
     * @throws \InvalidArgumentException With a message for the operator
     */
    public function parse(array $argv, $stdin = null): array
    {
        $admin = null;
        $fromStdin = false;
        $modules = 'recommended';
        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--admin=')) {
                $admin = substr($argument, 8);
            } elseif ($argument === '--password-stdin') {
                $fromStdin = true;
            } elseif (str_starts_with($argument, '--modules=')) {
                $modules = substr($argument, 10);
            } else {
                throw new \InvalidArgumentException(sprintf('Unknown argument "%s".', $argument));
            }
        }

        if ($admin === null || $admin === '') {
            throw new \InvalidArgumentException('--admin=NAME is required; there is no default administrator.');
        }
        if (preg_match(self::ADMIN_PATTERN, $admin) !== 1) {
            throw new \InvalidArgumentException('--admin must be 3-25 letters and digits, starting with a letter.');
        }
        if (!in_array($modules, ['recommended', 'none'], true)) {
            throw new \InvalidArgumentException('--modules must be "recommended" or "none".');
        }

        if ($fromStdin) {
            $line = is_resource($stdin) ? fgets($stdin) : false;
            $password = $line === false ? '' : rtrim($line, "\r\n");
            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                throw new \InvalidArgumentException(sprintf(
                    'The password read from standard input must be at least %d characters.',
                    self::MIN_PASSWORD_LENGTH
                ));
            }

            return ['admin' => $admin, 'password' => $password, 'generated' => false, 'modules' => $modules];
        }

        return ['admin' => $admin, 'password' => self::generatePassword(), 'generated' => true, 'modules' => $modules];
    }

    /**
     * A random password without characters that are easy to misread.
     */
    public static function generatePassword(int $length = self::GENERATED_PASSWORD_LENGTH): string
    {
        $alphabet = self::PASSWORD_ALPHABET;
        $last = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $last)];
        }

        return $password;
    }

    /**
     * The installer's database settings, from the container's environment.
     *
     * @return array<string, string|int>
     *
     * @throws \InvalidArgumentException When a required variable is missing
     */
    public function dbinfo(): array
    {
        $missing = [];
        foreach (['MYSQL_HOST', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_DATABASE'] as $name) {
            if (trim($this->env[$name] ?? '') === '') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'The environment does not name the database: %s missing. The Docker Compose file sets these for the web service.',
                implode(', ', $missing)
            ));
        }

        $cachePath = trim($this->env['MYSQL_DATACACHEPATH'] ?? '');

        return [
            'DB_HOST' => $this->env['MYSQL_HOST'],
            'DB_USER' => $this->env['MYSQL_USER'],
            'DB_PASS' => $this->env['MYSQL_PASSWORD'],
            'DB_NAME' => $this->env['MYSQL_DATABASE'],
            'DB_PREFIX' => '',
            'DB_USEDATACACHE' => $cachePath !== '' && (int) ($this->env['MYSQL_USEDATACACHE'] ?? 0) === 1 ? 1 : 0,
            'DB_DATACACHEPATH' => $cachePath,
        ];
    }

    /**
     * Whether the database already holds a game.
     *
     * @param callable(string): bool $tableExists
     */
    public static function alreadyInstalled(callable $tableExists): bool
    {
        return $tableExists('settings') || $tableExists('accounts');
    }

    /**
     * The module operations stage 8 would receive: the recommended modules
     * that exist, installed and activated.
     *
     * @param list<string> $recommended
     *
     * @return array<string, string>
     */
    public static function moduleOperations(array $recommended, string $modulesDirectory): array
    {
        $operations = [];
        foreach ($recommended as $module) {
            if (is_file($modulesDirectory . '/' . $module . '.php')) {
                $operations[$module] = 'install,activate';
            }
        }

        return $operations;
    }

    /**
     * Write dbconnect.php for these settings where the installer would.
     *
     * @param array<string, string|int> $dbinfo
     *
     * @return string The path written
     *
     * @throws \RuntimeException When it cannot be written
     */
    public static function writeDbconnect(Installer $installer, array $dbinfo): string
    {
        $path = $installer->dbconnectWritePath();
        if (file_put_contents($path, $installer->dbconnectContentsFor($dbinfo), LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Could not write %s.', $path));
        }
        chmod($path, 0640);
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return $path;
    }

    /**
     * Run the installer's stages as the browser would after its questions.
     *
     * Needs the game bootstrapped in installer mode: common.php loaded at
     * global scope with IS_INSTALLER, because the stages read and write the
     * globals it defines.
     *
     * @param array{admin:string,password:string,generated:bool,modules:string} $request
     * @param array<string, string|int>                                         $dbinfo
     * @param array<string, string>                                             $moduleOperations
     */
    public static function runStages(Installer $installer, array $request, array $dbinfo, array $moduleOperations): void
    {
        global $session, $stage;

        $session['dbinfo'] = $dbinfo + ['upgrade' => false, 'has_migration_metadata' => false];
        $session['fromversion'] = '-1';
        $session['skipmodules'] = false;
        $session['overridememorylimit'] = false;

        $posts = [
            7 => ['type' => 'install'],
            8 => ['modulesok' => '1', 'modules' => $moduleOperations],
            9 => [],
            10 => ['name' => $request['admin'], 'pass1' => $request['password'], 'pass2' => $request['password']],
            11 => ['delete_installer' => '1'],
        ];
        foreach ($posts as $number => $post) {
            $stage = $number;
            $session['stagecompleted'] = $number - 1;
            $_GET = [];
            $_POST = $post;
            $installer->runStage($number);
        }
        $_POST = [];
    }

    /**
     * What is missing from a finished installation.
     *
     * @return list<string> Empty when the game is installed and the administrator exists
     */
    public static function verify(string $codeVersion, string $admin): array
    {
        $problems = [];

        $settings = Settings::getInstance();
        $settings->clearSettings();
        $installed = (string) $settings->getSetting('installer_version', '-1');
        if ($installed !== $codeVersion) {
            $problems[] = sprintf('installer_version is "%s", expected "%s"', $installed, $codeVersion);
        }

        $count = (int) Database::getDoctrineConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . Database::prefix('accounts') . ' WHERE login = ? AND (superuser & ?) = ?',
            [$admin, SU_MEGAUSER, SU_MEGAUSER]
        );
        if ($count !== 1) {
            $problems[] = sprintf('the administrator "%s" was not created', $admin);
        }

        $pending = (new MigrationRunner())->pending();
        if ($pending !== []) {
            $problems[] = sprintf('%d migration(s) not applied: %s', count($pending), implode(', ', $pending));
        }

        return $problems;
    }

    /**
     * The installer pages' text, for when something went wrong.
     */
    public static function installerText(): string
    {
        $html = (string) Output::getInstance()->getRawOutput();
        $text = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
