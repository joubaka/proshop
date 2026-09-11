<?php
namespace App\Lights\Shelly;

interface RelayDriver
{
    /** Fresh timer evidence for ON; throw on rejected, missing or ambiguous evidence. */
    public function on(object $session): array;
    public function off(object $session): void;
}
