import type { MoneyAmount } from './commerce'

export interface ReferralInvite {
  id: string
  name: string
  joined_at: string | null
  referred_at: string | null
  converted: boolean
  fulfilled_orders: number
  registration_rewarded: boolean
}

export interface ReferralRewardRow {
  id: string
  status: string
  referred_user: { id: string, name: string } | null
  order_reference: string | null
  order_total: MoneyAmount
  reward: MoneyAmount
  awarded_at: string | null
  created_at: string | null
}

export interface ReferralRegistrationRewardRow {
  id: string
  status: string
  referred_user: { id: string, name: string } | null
  reward_mode: 'CREDIT_BALANCE' | 'TOKEN_QUOTA'
  reward_units: string
  currency: string | null
  currency_exponent: number | null
  awarded_at: string | null
}

export interface ReferralDashboard {
  enabled: boolean
  code: string
  share_url: string
  cookie_days: number
  commission_bps: number
  referred_bonus_bps: number
  reward_expiry_days: number
  registration_reward_enabled: boolean
  registration_reward_mode: 'CREDIT_BALANCE' | 'TOKEN_QUOTA'
  registration_credit_minor: string
  registration_token_units: string
  referred_by: { name: string, referred_at: string | null } | null
  metrics: {
    invited: number
    converted: number
    rewarded_orders: number
    rewarded_registrations: number
    earned: MoneyAmount[]
  }
  invites: ReferralInvite[]
  rewards: ReferralRewardRow[]
  registration_rewards: ReferralRegistrationRewardRow[]
}

export interface ReferralResolution {
  valid: boolean
  code: string | null
  cookie_days: number
}

export interface AdminReferralSettings {
  enabled: boolean
  registration_reward_enabled: boolean
  registration_reward_mode: 'CREDIT_BALANCE' | 'TOKEN_QUOTA'
  registration_credit_minor: string
  registration_token_units: string
  registration_reward_model_aliases: string[]
  commission_bps: number
  referred_bonus_bps: number
  minimum_order_minor: string
  cookie_days: number
  reward_expiry_days: number
  commission_all_orders: boolean
  referred_bonus_first_order_only: boolean
}

export interface AdminReferralReward {
  id: string
  status: string
  referrer: { id: string, name: string, email: string } | null
  referred_user: { id: string, name: string, email: string } | null
  order_reference: string | null
  order_total_minor: string
  referrer_reward_minor: string
  referred_bonus_minor: string
  currency: string
  currency_exponent: number
  awarded_at: string | null
  created_at: string | null
}

export interface AdminReferralRegistrationReward {
  id: string
  status: string
  referrer: { id: string, name: string, email: string } | null
  referred_user: { id: string, name: string, email: string } | null
  reward_mode: 'CREDIT_BALANCE' | 'TOKEN_QUOTA'
  reward_units: string
  currency: string | null
  currency_exponent: number | null
  allowed_model_aliases: string[]
  awarded_at: string | null
  created_at: string | null
}

export interface AdminReferralOverview {
  settings: AdminReferralSettings
  metrics: {
    referrers: number
    referred_users: number
    converted_users: number
    rewarded_orders: number
    rewarded_registrations: number
    earned: Array<{
      currency: string
      exponent: number
      referrer_minor: string
      bonus_minor: string
    }>
    registration_earned: Array<{
      mode: 'CREDIT_BALANCE' | 'TOKEN_QUOTA'
      units: string
      currency: string | null
      exponent: number | null
    }>
  }
  recent_rewards: AdminReferralReward[]
  recent_registration_rewards: AdminReferralRegistrationReward[]
  available_aliases: string[]
}
