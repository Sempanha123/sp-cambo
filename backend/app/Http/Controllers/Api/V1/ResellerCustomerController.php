<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountStatus;
use App\Exceptions\ResellerCustomerStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Models\ResellerCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ResellerAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class ResellerCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = ResellerCustomer::query()->where('reseller_user_id', $request->user()->id)->join('users', 'users.id', '=', 'reseller_customers.customer_user_id')->select('reseller_customers.*', 'users.name', 'users.email')->orderBy('reseller_customers.id')->get();

        return response()->json(['data' => $customers->map(fn ($customer) => $this->resource($customer))]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()], 'label' => ['required', 'string', 'max:150']]);
        $managed = DB::transaction(function () use ($request, $data, $audit): ResellerCustomer {
            $customer = User::query()->create(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password'], 'status' => 'ACTIVE']);
            $customerRole = Role::query()->where('name', 'CUSTOMER')->first();
            if (! $customerRole) {
                throw new \LogicException('The canonical CUSTOMER role has not been seeded.');
            }
            $customer->roles()->attach($customerRole);
            $managed = ResellerCustomer::query()->create(['reseller_user_id' => $request->user()->id, 'customer_user_id' => $customer->id, 'label' => $data['label'], 'status' => 'ACTIVE']);
            $audit->record($request->user(), 'reseller_customer.created', 'user', $customer->id, 'Reseller created managed customer.', ['reseller_customer_id' => $managed->id]);

            return $managed->setAttribute('name', $customer->name)->setAttribute('email', $customer->email);
        });

        return response()->json(['data' => $this->resource($managed)], 201);
    }

    public function updateStatus(Request $request, string $resellerCustomer, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:ACTIVE,SUSPENDED,CLOSED'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $managed = DB::transaction(function () use ($request, $resellerCustomer, $data, $audit): ResellerCustomer {
            $managed = ResellerCustomer::query()
                ->where('reseller_user_id', $request->user()->id)
                ->lockForUpdate()
                ->findOrFail($resellerCustomer);
            $customer = User::query()->lockForUpdate()->findOrFail($managed->customer_user_id);
            $previousStatus = (string) $managed->status;
            $newStatus = $data['status'];

            $allowedStatuses = match ($previousStatus) {
                'ACTIVE' => ['SUSPENDED', 'CLOSED'],
                'SUSPENDED' => ['ACTIVE', 'CLOSED'],
                default => [],
            };
            if (! in_array($newStatus, $allowedStatuses, true)) {
                throw new ResellerCustomerStatusTransitionException;
            }

            $accountStatus = match ($newStatus) {
                'ACTIVE' => AccountStatus::Active,
                'SUSPENDED' => AccountStatus::Suspended,
                'CLOSED' => AccountStatus::Disabled,
            };
            $managed->update(['status' => $newStatus]);
            $customer->update(['status' => $accountStatus]);
            $audit->record(
                $request->user(),
                'reseller_customer.status_changed',
                'reseller_customer',
                $managed->id,
                $data['reason'],
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'reseller_customer_id' => (int) $managed->id,
                    'customer_user_id' => (int) $managed->customer_user_id,
                ],
            );

            return $managed
                ->setAttribute('name', $customer->name)
                ->setAttribute('email', $customer->email);
        });

        return response()->json(['data' => $this->resource($managed)]);
    }

    public function allocate(Request $request, string $resellerCustomer, ResellerAllocationService $allocations): JsonResponse
    {
        $managed = ResellerCustomer::query()->where('reseller_user_id', $request->user()->id)->where('status', 'ACTIVE')->findOrFail($resellerCustomer);
        $data = $request->validate(['billing_mode' => ['required', 'in:TOKEN_QUOTA,CREDIT_BALANCE'], 'public_model_alias' => ['required', 'string', 'max:100'], 'units' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:191'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $transfer = $allocations->allocate($request->user(), User::query()->findOrFail($managed->customer_user_id), $data['billing_mode'], $data['public_model_alias'], (int) $data['units'], $data['idempotency_key'], $data['reason']);

        return response()->json(['data' => ['id' => $transfer->id, 'customer_id' => (string) $managed->id, 'billing_mode' => $transfer->billing_mode, 'public_model_alias' => $transfer->public_model_alias, 'units' => (string) $transfer->units, 'created_at' => $transfer->created_at->toAtomString()]], 201);
    }

    private function resource($customer): array
    {
        return ['id' => (string) $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'label' => $customer->label, 'status' => $customer->status, 'created_at' => $customer->created_at->toAtomString()];
    }
}
