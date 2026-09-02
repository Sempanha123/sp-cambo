<?php

namespace App\Contracts;

interface KhqrGenerator
{
    /** @return array{qr_payload: string, md5: string} */
    public function generate(string $accountId, string $merchantName, string $merchantCity, string $currency, string $amountDecimal, string $reference): array;
}
