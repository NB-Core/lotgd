<?php

// Legacy wrapper for Lotgd\ErrorHandler class
use Lotgd\ErrorHandler;

function lotgd_render_error(string $message, string $file, int $line, string $backtrace): void
{
    ErrorHandler::renderError($message, $file, $line, $backtrace);
}

function logd_error_handler($errno, $errstr, $errfile, $errline): void
{
    ErrorHandler::handleError($errno, $errstr, $errfile, $errline);
}

function logd_error_notify($errno, $errstr, $errfile, $errline, $backtrace): void
{
    ErrorHandler::errorNotify($errno, $errstr, $errfile, $errline, $backtrace);
}

function lotgd_exception_handler($exception): void
{
    ErrorHandler::handleException($exception);
}

function lotgd_fatal_shutdown(): void
{
    ErrorHandler::fatalShutdown();
}

ErrorHandler::register();
