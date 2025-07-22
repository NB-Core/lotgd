<?php

// Legacy wrapper for PhpGenericEnvironment class
// addnews ready
// translator ready
// mail ready

use Lotgd\PhpGenericEnvironment;

function sanitize_uri()
{
    PhpGenericEnvironment::sanitizeUri();
}

function php_generic_environment()
{
    PhpGenericEnvironment::setup();
}
