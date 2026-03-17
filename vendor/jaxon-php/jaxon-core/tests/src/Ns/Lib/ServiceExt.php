<?php

namespace Jaxon\Tests\Ns\Lib;

class ServiceExt
{
    private $value = 'initial';

    public function action(): void
    {}

    public function changeValue(): void
    {
        $this->value = 'changed';
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
