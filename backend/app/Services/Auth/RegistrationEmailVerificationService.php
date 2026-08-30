<?php

namespace App\Services\Auth;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\RegistrationEmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationEmailVerificationService
{
    public const CODE_TTL_MINUTES = 10;
    public const RESEND_COOLDOWN_SECONDS = 60;
    public const MAX_ATTEMPTS = 6;

    /**
     * Send a new six-digit registration code. A resend invalidates the old code.
     */
    public function send(string $email): array
    {
        $email = $this->normalizeEmail($email);
        $now = now();
        $code = (string) random_int(100000, 999999);

        $challenge = DB::transaction(function () use ($email, $now, $code): RegistrationEmailVerification {
            $existing = RegistrationEmailVerification::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($existing?->last_sent_at) {
                $elapsed = max(0, $now->getTimestamp() - $existing->last_sent_at->getTimestamp());
                $retryAfter = self::RESEND_COOLDOWN_SECONDS - $elapsed;
                if ($retryAfter > 0) {
                    throw ValidationException::withMessages([
                        'email' => ["Please wait {$retryAfter} seconds before requesting another code."],
                    ]);
                }
            }

            return RegistrationEmailVerification::query()->updateOrCreate(
                ['email' => $email],
                [
                    'code_hash' => $this->hashCode($email, $code),
                    'attempts' => 0,
                    'last_sent_at' => $now,
                    'expires_at' => $now->copy()->addMinutes(self::CODE_TTL_MINUTES),
                    'verified_at' => null,
                    'consumed_at' => null,
                ],
            );
        });

        try {
            Mail::to($email)->send(new RegistrationVerificationCodeMail($code, self::CODE_TTL_MINUTES));
        } catch (Throwable $exception) {
            // A code that never left the server must not remain usable.
            $challenge->delete();
            throw $exception;
        }

        return [
            'expires_in' => self::CODE_TTL_MINUTES * 60,
            'resend_after' => self::RESEND_COOLDOWN_SECONDS,
        ];
    }

    /**
     * Verify the submitted code. Incorrect-attempt accounting is committed in
     * its own short transaction so a later ValidationException cannot roll it back.
     */
    public function verifyOrFail(string $email, string $code): void
    {
        $email = $this->normalizeEmail($email);
        $code = trim($code);

        $error = DB::transaction(function () use ($email, $code): ?string {
            /** @var RegistrationEmailVerification|null $challenge */
            $challenge = RegistrationEmailVerification::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->consumed_at !== null) {
                return 'Request a new verification code for this email address.';
            }

            if ($challenge->expires_at->isPast()) {
                return 'This verification code has expired. Request a new code.';
            }

            if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                return 'Too many incorrect attempts. Request a new verification code.';
            }

            if (! hash_equals($challenge->code_hash, $this->hashCode($email, $code))) {
                $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();
                return 'The verification code is incorrect.';
            }

            $challenge->forceFill(['verified_at' => now()])->save();
            return null;
        });

        if ($error !== null) {
            throw ValidationException::withMessages([
                'verification_code' => [$error],
            ]);
        }
    }

    /**
     * Consume a previously verified challenge inside the account-creation
     * transaction. The row lock makes the code one-time even under concurrency.
     */
    public function consumeVerifiedOrFail(string $email): void
    {
        $email = $this->normalizeEmail($email);

        /** @var RegistrationEmailVerification|null $challenge */
        $challenge = RegistrationEmailVerification::query()
            ->where('email', $email)
            ->lockForUpdate()
            ->first();

        if (! $challenge
            || $challenge->verified_at === null
            || $challenge->consumed_at !== null
            || $challenge->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'verification_code' => ['Verify this email with a fresh code before creating the account.'],
            ]);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function hashCode(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }
}
