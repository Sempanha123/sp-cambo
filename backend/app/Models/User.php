<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'status', 'tenant_id', 'referral_code', 'referred_by_user_id', 'referred_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'referred_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->tenant_id !== null) {
                return;
            }

            $tenant = Tenant::query()->create([
                'name' => trim((string) ($user->name ?: $user->email ?: "User {$user->id}")),
            ]);

            $user->forceFill(['tenant_id' => $tenant->id])->saveQuietly();
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }


    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function referralRewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class, 'referrer_user_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function entitlementLots(): HasMany
    {
        return $this->hasMany(EntitlementLot::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function telegramAccount(): HasOne
    {
        return $this->hasOne(TelegramAccount::class);
    }

    /**
     * Return the customer's workspace tenant, repairing legacy rows created
     * before tenant ownership was introduced. Commerce, API-key and Telegram
     * services call this instead of assuming the relation is already present.
     */
    public function requireTenant(): Tenant
    {
        if ($this->tenant_id !== null) {
            $tenant = $this->tenant()->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return DB::transaction(function (): Tenant {
            /** @var User $locked */
            $locked = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($locked->tenant_id !== null) {
                $tenant = Tenant::query()->find($locked->tenant_id);
                if ($tenant) {
                    $this->setRelation('tenant', $tenant);
                    $this->tenant_id = $tenant->id;
                    return $tenant;
                }
            }

            if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'tenant_id')) {
                throw new RuntimeException('Tenant ownership migration has not been applied. Run php artisan migrate.');
            }

            $tenant = Tenant::query()->create([
                'name' => trim((string) ($locked->name ?: $locked->email ?: "User {$locked->id}")),
            ]);

            $locked->forceFill(['tenant_id' => $tenant->id])->saveQuietly();
            $this->tenant_id = $tenant->id;
            $this->setRelation('tenant', $tenant);

            return $tenant;
        });
    }
}
