<?php

namespace App\Lights\Shelly;

/** Only fixed messages derived from cURL numeric codes; never raw error text or URLs. */
final class ConnectionFailure extends \RuntimeException
{
    public function __construct(int $number)
    {
        parent::__construct(match ($number) {
            6 => 'The Shelly cloud hostname could not be resolved. Check this computer\'s DNS/network connection.',
            7 => 'The network connection to Shelly was blocked or unavailable. The local server may need restarting outside the restricted sandbox.',
            28 => 'The Shelly status request timed out. Wait before trying again.',
            60, 77 => 'The Shelly HTTPS certificate could not be verified. Check the local CA certificates; do not disable TLS verification.',
            23 => 'The Shelly status response exceeded the safe response limit or could not be read.',
            default => 'The Shelly status transport is unavailable or failed.',
        }.' No switching command was sent.', $number);
    }
}
