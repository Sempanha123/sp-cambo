import type { ApiKeyStatus } from '~/types/commerce'

/**
 * Reseller management contracts, implemented today under `/api/v1/reseller/*`.
 *
 * Every route requires the `reseller.manage` permission and is tenant-isolated:
 * another reseller's ids return 404, never another tenant's data.
 *
 * Two unrelated credential families appear here and must never be conflated:
 * `sk-*` inference keys belong to a managed customer and call the gateway,
 * while `sk-spm-*` management keys are the reseller's own automation credential
 * and cannot perform inference.
 */

export type ResellerCustomerStatus = 'ACTIVE' | 'SUSPENDED' | 'CLOSED'

/** `GET|POST /reseller/customers`. The initial password is never returned. */
export interface ResellerCustomer {
  id: string
  name: string
  email: string
  /** Reseller-chosen label for their own records. */
  label: string
  status: ResellerCustomerStatus
  created_at: string
}

export interface ResellerCustomerInput {
  name: string
  email: string
  password: string
  password_confirmation: string
  label: string
}

/** `PATCH /reseller/customers/{id}/status` — writes immutable audit evidence. */
export interface ResellerCustomerStatusUpdateInput {
  status: ResellerCustomerStatus
  reason: string
}

/**
 * `POST /reseller/customers/{id}/allocations`.
 *
 * Units move out of the reseller's own inventory by FEFO, so an allocation is a
 * transfer and not a purchase. `idempotency_key` is bound to the inputs: reusing
 * it with different values is rejected rather than silently transferring twice.
 */
export interface ResellerAllocationInput {
  billing_mode: 'TOKEN_QUOTA' | 'CREDIT_BALANCE'
  public_model_alias: string
  units: number
  idempotency_key: string
  reason: string
}

export interface ResellerAllocation {
  id: string
  customer_id: string
  billing_mode: 'TOKEN_QUOTA' | 'CREDIT_BALANCE'
  public_model_alias: string
  /** Exact integer unit string. */
  units: string
  created_at: string
}

/**
 * A managed customer's inference key. Structurally similar to the customer-owned
 * `ApiKeySummary` but deliberately separate: this projection carries no rate
 * limits, so the reseller UI must not claim to display any.
 */
export interface ResellerCustomerKey {
  id: string
  label: string
  prefix: string
  last_four: string
  status: ApiKeyStatus
  created_at: string
  last_used_at: string | null
  expires_at: string | null
  allowed_model_aliases: string[]
}

export interface ResellerCustomerKeyCreated {
  key: ResellerCustomerKey
  /** One-time plaintext. Never persisted, logged or re-fetchable. */
  secret: string
}

export const RESELLER_MANAGEMENT_SCOPES = [
  'customers:read',
  'customers:write',
  'keys:read',
  'keys:write',
  'allocations:read',
  'allocations:write',
  'usage:read'
] as const

export type ResellerManagementScope = typeof RESELLER_MANAGEMENT_SCOPES[number]

export type ResellerManagementKeyStatus = 'ACTIVE' | 'REVOKED'

/** `GET|POST /reseller/management-keys` — `sk-spm-*`, automation only. */
export interface ResellerManagementKey {
  id: string
  label: string
  prefix: string
  last_four: string
  scopes: ResellerManagementScope[]
  status: ResellerManagementKeyStatus
  last_used_at: string | null
  expires_at: string | null
  created_at: string
}

export interface ResellerManagementKeyCreated {
  key: ResellerManagementKey
  secret: string
}
