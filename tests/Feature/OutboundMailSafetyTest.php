<?php

namespace Tests\Feature;

use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class OutboundMailSafetyTest extends TestCase
{
    public function test_outbound_email_is_disabled_by_default(): void
    {
        $this->assertFalse(config('mail.outbound_enabled'));
        $this->assertSame('array', config('mail.driver'));
        $this->assertInstanceOf(ArrayTransport::class, app('mailer')->getSymfonyTransport());
    }
}
