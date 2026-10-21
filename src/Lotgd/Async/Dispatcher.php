<?php

declare(strict_types=1);

namespace Lotgd\Async;

use Lotgd\Modules\HookHandler;

/**
 * Runs the async call a request names (async/process.php).
 *
 * A request posts one field, `call`, holding JSON:
 * `{"class": "Commentary", "method": "pollUpdates", "args": [...]}`. The class
 * is a name from the registry below -- the core handlers in
 * src/Lotgd/Async/Handler, plus those modules add through the hook
 * `async-handlers` (`['Name' => FQCN]`) -- never a PHP class name from the
 * request. The method must be a public instance method the class declares
 * itself; setters, the constructor and anything listed in EXCLUDED are not
 * callable. The arguments are passed positionally.
 *
 * Authorization, CSRF, rate limiting and the session lock are decided before
 * this runs, by async/process.php, on the context describe() returns -- the
 * same values this class then dispatches on.
 */
final class Dispatcher
{
    public const FIELD = 'call';
    public const CONTEXT_PREFIX = 'Lotgd.Async.Handler.';

    /** @var array<string,class-string> */
    private const CORE = [
        'Bans' => Handler\Bans::class,
        'Commentary' => Handler\Commentary::class,
        'Mail' => Handler\Mail::class,
        'Realtime' => Handler\Realtime::class,
        'Timeout' => Handler\Timeout::class,
        'TwoFactorAuthPasskey' => Handler\TwoFactorAuthPasskey::class,
    ];

    /**
     * Methods that are public for the PHP side only.
     *
     * @var array<string,list<string>>
     */
    private const EXCLUDED = [
        'Commentary' => ['test'],
        'Timeout' => ['getInstance'],
        'TwoFactorAuthPasskey' => ['setService', 'setRepository'],
    ];

    /** @var array<string,class-string>|null */
    private ?array $classes = null;

    /** @var array{class:string,method:string,args:list<mixed>}|null|false */
    private array|null|false $call = false;

    /**
     * @param array<string,mixed> $post The request body, normally $_POST.
     */
    public function __construct(private readonly array $post)
    {
    }

    /**
     * Whether the request names a call at all (well-formed, any class).
     */
    public function canProcessRequest(): bool
    {
        return $this->parse() !== null;
    }

    /**
     * The class and method the request names, sanitised for policy decisions
     * and logs: class as `Lotgd.Async.Handler.<Name>`. Empty strings when the
     * request names nothing usable.
     *
     * @return array{class:string,method:string}
     */
    public function describe(): array
    {
        $call = $this->parse();
        if ($call === null) {
            return ['class' => '', 'method' => ''];
        }

        return ['class' => self::CONTEXT_PREFIX . $call['class'], 'method' => $call['method']];
    }

    /**
     * What the rate limit counts the call under: class and method, and for
     * a region fetch the region too -- an announcement of mail and of stats
     * at once is two fetches right after each other, each allowed.
     */
    public function rateKey(): string
    {
        $context = $this->describe();
        $key = $context['class'] . '::' . $context['method'];
        $call = $this->parse();
        if ($call !== null && $call['class'] === 'Realtime' && $call['method'] === 'region') {
            $region = $call['args'][0] ?? '';
            $key .= ':' . (is_string($region) && preg_match('/^[a-z]{1,16}\z/', $region) === 1 ? $region : '?');
        }

        return $key;
    }

    /**
     * Run the named call and return its response.
     *
     * @throws \InvalidArgumentException For a class, method or arguments that do not resolve.
     */
    public function processRequest(): Response
    {
        $call = $this->parse();
        if ($call === null) {
            throw new \InvalidArgumentException('No async call in the request.');
        }

        $class = $this->classes()[$call['class']] ?? null;
        if ($class === null || !$this->isCallable($call['class'], $class, $call['method'])) {
            throw new \InvalidArgumentException('Unknown async call ' . $call['class'] . '.' . $call['method']);
        }

        $handler = $call['class'] === 'Timeout' ? Handler\Timeout::getInstance() : new $class();
        $method = new \ReflectionMethod($handler, $call['method']);
        if (count($call['args']) < $method->getNumberOfRequiredParameters()) {
            throw new \InvalidArgumentException('Too few arguments for ' . $call['class'] . '.' . $call['method']);
        }

        try {
            $result = $method->invokeArgs($handler, array_slice($call['args'], 0, $method->getNumberOfParameters()));
        } catch (\TypeError $error) {
            // A string where an int belongs: the caller's mistake, not the server's.
            throw new \InvalidArgumentException($error->getMessage(), 0, $error);
        }

        return $result instanceof Response ? $result : new Response();
    }

    /**
     * The registered handler names and classes.
     *
     * @return array<string,class-string>
     */
    public function classes(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        $classes = self::CORE;
        if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
            try {
                $added = HookHandler::hook('async-handlers', []);
            } catch (\Throwable) {
                $added = [];
            }
            foreach (is_array($added) ? $added : [] as $name => $class) {
                // A module cannot replace a core handler.
                if (
                    is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]*\z/', $name) === 1
                    && !isset(self::CORE[$name]) && is_string($class) && class_exists($class)
                ) {
                    $classes[$name] = $class;
                }
            }
        }

        return $this->classes = $classes;
    }

    private function isCallable(string $name, string $class, string $method): bool
    {
        if (in_array($method, self::EXCLUDED[$name] ?? [], true) || str_starts_with($method, '__')) {
            return false;
        }
        if (!method_exists($class, $method)) {
            return false;
        }
        $reflection = new \ReflectionMethod($class, $method);

        return $reflection->isPublic()
            && !$reflection->isStatic()
            && $reflection->getDeclaringClass()->getName() === $class;
    }

    /**
     * @return array{class:string,method:string,args:list<mixed>}|null
     */
    private function parse(): ?array
    {
        if ($this->call !== false) {
            return $this->call;
        }

        $raw = $this->post[self::FIELD] ?? null;
        $call = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($call)) {
            return $this->call = null;
        }
        $class = $call['class'] ?? null;
        $method = $call['method'] ?? null;
        $args = $call['args'] ?? [];
        if (!is_string($class) || !is_string($method) || !is_array($args) || !array_is_list($args)) {
            return $this->call = null;
        }
        // Names only: a value that is anything else is not a call.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*\z/', $class) !== 1 || preg_match('/^[A-Za-z][A-Za-z0-9_]*\z/', $method) !== 1) {
            return $this->call = null;
        }

        return $this->call = ['class' => $class, 'method' => $method, 'args' => $args];
    }
}
