<?php

namespace App\InvoiceScanning;

use App\InvoiceScanning\Contracts\InvoiceExtractor;
use App\InvoiceScanning\Providers\OpenAiInvoiceExtractor;
use InvalidArgumentException;

class InvoiceExtractorManager
{
    public function driver(): InvoiceExtractor
    {
        return match (config('invoice_scanning.driver')) {
            'openai' => app(OpenAiInvoiceExtractor::class),
            default => throw new InvalidArgumentException('Unsupported invoice scanning driver.'),
        };
    }
}
