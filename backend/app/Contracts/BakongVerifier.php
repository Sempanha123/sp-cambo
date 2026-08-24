<?php

namespace App\Contracts;

interface BakongVerifier
{
    /** @return array{found: bool, transaction_hash: string|null, to_account_id: string|null, currency: string|null, amount_decimal: string|null} */
    public function checkByMd5(string $md5): array;
}
