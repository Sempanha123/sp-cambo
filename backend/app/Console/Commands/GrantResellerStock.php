<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ResellerStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class GrantResellerStock extends Command
{
    protected $signature = 'reseller:grant-stock
        {email : Reseller account email}
        {billing_mode : TOKEN_QUOTA or CREDIT_BALANCE}
        {units : Integer units. CREDIT_BALANCE uses the pricing currency minor units}
        {--model=* : Public model alias. Repeat --model for more than one}
        {--idempotency= : Stable unique key so a retry cannot grant twice}
        {--reason= : Audit reason, at least 10 characters}
        {--expires= : Optional ISO-8601 expiry date/time}';

    protected $description = 'Grant dedicated non-spendable inventory that only reseller allocations may consume';

    public function handle(ResellerStockService $stocks): int
    {
        $models = array_values(array_filter(array_map('trim', (array) $this->option('model'))));
        $reason = trim((string) $this->option('reason'));
        $idempotency = trim((string) $this->option('idempotency'));

        if ($models === []) {
            $this->error('Provide at least one --model=PUBLIC_ALIAS.');
            return self::INVALID;
        }
        if (mb_strlen($reason) < 10) {
            $this->error('Provide --reason with at least 10 characters.');
            return self::INVALID;
        }
        if ($idempotency === '') {
            $this->error('Provide --idempotency with a stable unique value. Reuse it only when retrying the same grant.');
            return self::INVALID;
        }
        if (! ctype_digit((string) $this->argument('units')) || (int) $this->argument('units') < 1) {
            $this->error('units must be a positive integer.');
            return self::INVALID;
        }

        try {
            $reseller = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $this->argument('email'))])->firstOrFail();
            $expiresAt = filled($this->option('expires')) ? Carbon::parse((string) $this->option('expires')) : null;
            $result = $stocks->grant(
                null,
                $reseller,
                (string) $this->argument('billing_mode'),
                (int) $this->argument('units'),
                $models,
                $reason,
                $idempotency,
                $expiresAt,
            );
            $lot = $result['lot'];

            $this->info($result['replayed'] ? 'Existing reseller-stock grant replayed safely.' : 'Reseller stock granted.');
            $this->table(
                ['lot_id', 'email', 'mode', 'units', 'currency', 'exponent', 'models', 'expires'],
                [[
                    (string) $lot->id,
                    (string) $reseller->email,
                    (string) $lot->billing_mode,
                    (string) $lot->original_units,
                    (string) ($lot->currency ?? '-'),
                    $lot->currency_exponent === null ? '-' : (string) $lot->currency_exponent,
                    implode(',', $lot->allowed_model_aliases ?? []),
                    $lot->expires_at?->toAtomString() ?? 'never',
                ]]
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
