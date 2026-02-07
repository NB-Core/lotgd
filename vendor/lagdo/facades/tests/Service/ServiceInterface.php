<?php

namespace Lagdo\Facades\Tests\Service;

interface ServiceInterface
{
    /**
     * @param string $message
     *
     * @return void
     */
    public function log(string $message);
}
