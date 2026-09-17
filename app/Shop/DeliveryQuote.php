<?php

namespace App\Shop;

use InvalidArgumentException;

final class DeliveryQuote
{
    public function __construct(
        public readonly string $method,
        public readonly string $label,
        public readonly int $amountCents,
        public readonly array $metadata = [],
    ) {
        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $method) || $amountCents < 0) {
            throw new InvalidArgumentException('Invalid delivery quote.');
        }
    }

    public static function collection(string $label = 'Collect from ProShop'): self
    {
        return new self('collection', $label, 0);
    }
}
