<?php

namespace App\InvoiceScanning\Contracts;

use App\InvoiceScan;

interface InvoiceExtractor
{
    public function extract(InvoiceScan $scan): array;
}
