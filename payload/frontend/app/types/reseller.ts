import type { ApiKeyStatus, QuotaDisplay } from '~/types/commerce'

export type ResellerCustomerStatus = 'ACTIVE' | 'SUSPENDED' | 'CLOSED'

export interface ResellerCustomer {
  id: string
  name: string
  email: string
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

export interface ResellerCustomerStatusUpdateInput {
  status: ResellerCustomerStatus
  reason: string
}

/** Legacy single-model allocation. */
export interface ResellerAllocationInput {
  billing_mode: 'TOKEN_QUOTA' | 'CREDIT_BALANCE'
  public_model_alias: string
  units: number
  idempotency_key: string
  reason: string
}

/** Preferred package-level allocation: one shared balance across all models in the lot. */
export interface ResellerPackageAllocationInput {
  inventory_lot_id: string
  /** Preferred customer-facing package quantity, e.g. "10" Credits. */
  display_units?: string
  /** Legacy raw settlement units; keep only for backward-compatible integrations. */
  units?: number
  idempotency_key: string
  reason: string
}

export interface ResellerAllocation {
  id: string
  customer_id: string
  allocation_kind: 'MODEL' | 'PACKAGE'
  inventory_lot_id: string | null
  package_name: string | null
  billing_mode: 'TOKEN_QUOTA' | 'CREDIT_BALANCE'
  public_model_alias: string | null
  allowed_model_aliases: string[]
  units: string
  display?: QuotaDisplay | null
  expires_at?: string | null
  created_at: string
}

export interface ResellerAllocationCustomerBalance {
  original_units: string
  remaining_units: string
  reserved_units: string
  available_units: string
  used_units: string
  status: string | null
  expires_at: string | null
  target_entitlement_lot_ids: string[]
  display?: QuotaDisplay | null
}

export interface ResellerAllocationHistoryItem extends ResellerAllocation {
  idempotency_key: string
  reason: string
  /** Live balance of the entitlement lot(s) created for this exact sale. */
  customer_balance: ResellerAllocationCustomerBalance | null
}

export interface ResellerAllocationQuery {
  limit?: number
  billing_mode?: 'TOKEN_QUOTA' | 'CREDIT_BALANCE'
  model?: string
}

export interface ResellerUsageQuery {
  from?: string
  to?: string
  limit?: number
  model?: string
  key_id?: string
}

export interface ResellerUsageMoney {
  minor: string
  currency: string
  exponent: number
}

export interface ResellerUsageTotals {
  requests: number
  input_tokens: string
  output_tokens: string
  cache_read_tokens: string
  cache_write_tokens: string
  reasoning_tokens: string
  total_tokens: string
  metered_units: string
}

export interface ResellerUsageByModel extends ResellerUsageTotals {
  public_model: string
}

export interface ResellerUsageRecord {
  id: string
  api_key_id: string | null
  public_model: string
  endpoint: string
  input_tokens: string
  output_tokens: string
  cache_read_tokens: string
  cache_write_tokens: string
  reasoning_tokens: string
  total_tokens: string
  metered_units: string
  credit_charge: ResellerUsageMoney | null
  settled_at: string
}

export interface ResellerCustomerUsage {
  customer_id: string
  range: {
    from: string
    to: string
  }
  totals: ResellerUsageTotals
  credit_charges: ResellerUsageMoney[]
  by_model: ResellerUsageByModel[]
  recent: ResellerUsageRecord[]
}

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
