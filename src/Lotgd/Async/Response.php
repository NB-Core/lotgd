<?php

declare(strict_types=1);

namespace Lotgd\Async;

/**
 * What an async handler answers: a list of commands the browser carries out
 * (assets/js/lotgd.async.js), and data for the caller.
 *
 * The commands are deliberately few. `assign` sets the content of an element
 * by id, `append` adds to it; `call` runs one of the functions the client
 * has registered for it (lotgd.async.allow(), never an arbitrary global);
 * `title` sets the document title. Anything richer goes into data and is handled by the caller's code.
 */
final class Response
{
    /** @var list<array<string,mixed>> */
    private array $commands = [];

    /** @var array<string,mixed> */
    private array $data = [];

    /**
     * Set a property of the element with this id. Only innerHTML is applied
     * by the client.
     */
    public function assign(string $id, string $property, string $value): self
    {
        $this->commands[] = ['cmd' => 'assign', 'id' => $id, 'prop' => $property, 'value' => $value];

        return $this;
    }

    /**
     * Add to the end of the content of the element with this id. Only
     * innerHTML is applied by the client.
     */
    public function append(string $id, string $property, string $value): self
    {
        $this->commands[] = ['cmd' => 'append', 'id' => $id, 'prop' => $property, 'value' => $value];

        return $this;
    }

    /**
     * Run a function the client has allowed for async responses.
     */
    public function call(string $function, mixed ...$args): self
    {
        $this->commands[] = ['cmd' => 'call', 'fn' => $function, 'args' => array_values($args)];

        return $this;
    }

    public function title(string $title): self
    {
        $this->commands[] = ['cmd' => 'title', 'value' => $title];

        return $this;
    }

    /**
     * Data for the caller, under a key of its own.
     */
    public function data(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Take over the commands and data of another response, after this one's.
     */
    public function merge(self $other): self
    {
        foreach ($other->commands as $command) {
            $this->commands[] = $command;
        }
        $this->data = $other->data + $this->data;

        return $this;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @return array<string,mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array{status:string,commands:list<array<string,mixed>>,data:array<string,mixed>}
     */
    public function toArray(): array
    {
        return ['status' => 'ok', 'commands' => $this->commands, 'data' => $this->data];
    }
}
