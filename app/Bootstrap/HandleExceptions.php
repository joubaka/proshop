<?php

namespace App\Bootstrap;

use Illuminate\Foundation\Bootstrap\HandleExceptions as BaseHandleExceptions;

class HandleExceptions extends BaseHandleExceptions
{
    /**
     * Convert PHP errors to ErrorException instances.
     *
     * Ignores E_DEPRECATED and E_USER_DEPRECATED to allow
     * Laravel 5.8 to run on PHP 8.x without fatal errors.
     *
     * @param  int  $level
     * @param  string  $message
     * @param  string  $file
     * @param  int  $line
     * @param  array  $context
     * @return void
     *
     * @throws \ErrorException
     */
    public function handleError($level, $message, $file = '', $line = 0, $context = [])
    {
        if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
            return;
        }

        parent::handleError($level, $message, $file, $line, $context);
    }
}
