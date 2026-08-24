<script setup lang="ts">
useSeoMeta({
  title: 'Reseller programme',
  description: 'Resell SP Cambo access to your own customers under verified commercial-use terms, with allocated entitlements and per-customer scoping.'
})

const auth = useAuthStore()

const how = [
  {
    title: 'Verification first',
    description: 'Commercial resale requires an explicit verification state on your account. It is granted by SP Cambo administrators, not self-serve, because upstream provider terms bind us both.',
    icon: 'i-lucide-badge-check'
  },
  {
    title: 'Allocated entitlements',
    description: 'Verified resellers receive entitlement allocations rather than raw provider credentials. You allocate from your pool to your own customers.',
    icon: 'i-lucide-split'
  },
  {
    title: 'Per-customer scoping',
    description: 'Each downstream customer gets their own keys and their own limits, so one customer cannot exhaust another customer\'s allocation.',
    icon: 'i-lucide-users-round'
  },
  {
    title: 'Auditable movements',
    description: 'Every allocation, spend and expiry is a ledger entry. You can reconcile what you sold against what was actually consumed.',
    icon: 'i-lucide-scroll-text'
  }
]

const rules = [
  'You must not resell access in a way that breaches the upstream provider terms that apply to the model family.',
  'You must not represent SP Cambo as the model provider, and you must not imply an endorsement you do not have.',
  'You are responsible for your own customers\' acceptable use, including any content they generate.',
  'Internal endpoints, upstream provider credentials and routing details are never shared, at any tier.',
  'Allocations are prepaid. There is no credit line and no post-paid settlement.'
]
</script>

<template>
  <div>
    <UContainer class="py-14 sm:py-16">
      <div class="max-w-3xl space-y-4">
        <UBadge
          color="neutral"
          variant="subtle"
          size="lg"
          class="rounded-full"
        >
          Verified commercial use
        </UBadge>
        <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance">
          Resell managed AI access without handling provider credentials
        </h1>
        <p class="text-lg text-muted text-pretty">
          SP Cambo supplies the metering, the prepaid ledger and the gateway. You keep the customer
          relationship. Allocations are prepaid and every movement is auditable on both sides.
        </p>
      </div>

      <div class="mt-12 grid gap-5 sm:grid-cols-2">
        <div
          v-for="item in how"
          :key="item.title"
          class="space-y-3 rounded-xl border border-default bg-elevated/30 p-6"
        >
          <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
            <UIcon
              :name="item.icon"
              class="size-5"
            />
          </div>
          <h2 class="font-medium text-highlighted">
            {{ item.title }}
          </h2>
          <p class="text-sm text-muted text-pretty">
            {{ item.description }}
          </p>
        </div>
      </div>
    </UContainer>

    <div class="border-y border-default bg-elevated/25">
      <UContainer class="py-14">
        <div class="grid gap-10 lg:grid-cols-2">
          <div class="space-y-3">
            <h2 class="text-2xl font-semibold tracking-tight text-highlighted">
              Programme rules
            </h2>
            <p class="text-sm text-muted text-pretty">
              These are conditions of verification, not guidelines. Breaching them ends reseller
              access and may suspend the underlying account.
            </p>
            <UButton
              to="/legal/acceptable-use"
              color="neutral"
              variant="subtle"
              size="sm"
              trailing-icon="i-lucide-arrow-right"
            >
              Acceptable use policy
            </UButton>
          </div>

          <ul class="space-y-3">
            <li
              v-for="rule in rules"
              :key="rule"
              class="flex items-start gap-3 text-sm text-muted"
            >
              <UIcon
                name="i-lucide-shield-alert"
                class="mt-0.5 size-4 shrink-0 text-warning"
              />
              <span class="text-pretty">{{ rule }}</span>
            </li>
          </ul>
        </div>
      </UContainer>
    </div>

    <UContainer class="py-14">
      <div class="rounded-xl border border-default bg-elevated/30 p-6 sm:p-8">
        <div class="max-w-2xl space-y-4">
          <h2 class="text-2xl font-semibold tracking-tight text-highlighted">
            Applying for verification
          </h2>
          <p class="text-sm text-muted text-pretty">
            Reseller verification is enabled on an existing SP Cambo account by an administrator.
            Create your account and buy at least one package first: verification is assessed against
            a real account with real usage, not an application form.
          </p>
          <div class="rounded-lg border border-dashed border-default bg-default/50 px-4 py-3">
            <p class="flex items-start gap-2 text-sm text-muted">
              <UIcon
                name="i-lucide-info"
                class="mt-0.5 size-4 shrink-0"
              />
              <span>
                There is no self-serve application endpoint yet. Until one exists, this page does not
                pretend to submit anything — request verification through the channel you already use
                to reach the SP Cambo team.
              </span>
            </p>
          </div>
          <div class="flex flex-wrap gap-3 pt-1">
            <UButton
              :to="auth.authenticated ? '/dashboard' : '/register'"
              trailing-icon="i-lucide-arrow-right"
            >
              {{ auth.authenticated ? 'Open dashboard' : 'Create an account' }}
            </UButton>
            <UButton
              to="/pricing"
              color="neutral"
              variant="subtle"
            >
              See retail packages
            </UButton>
          </div>
        </div>
      </div>
    </UContainer>
  </div>
</template>
