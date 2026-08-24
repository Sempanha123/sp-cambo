<script setup lang="ts">
definePageMeta({
  layout: 'dashboard',
  middleware: ['auth']
})

useSeoMeta({
  title: 'Orders & payments',
  description: 'Every order on your account, what it cost, and whether it was paid, fulfilled or closed.',
  robots: 'noindex'
})

const api = useSpApi()

const orders = await useSpResource('dashboard:orders', () => api.orders.list(), { server: false })

type Filter = 'all' | 'open' | 'completed' | 'closed'

const filter = ref<Filter>('all')

const sorted = computed(() => sortOrdersNewestFirst(orders.data.value ?? []))

const openOrders = computed(() => sorted.value.filter(isOrderOpen))
const completedOrders = computed(() => sorted.value.filter(isOrderCompleted))
const closedOrders = computed(() => sorted.value.filter(isOrderClosed))

const visible = computed(() => {
  if (filter.value === 'open') {
    return openOrders.value
  }

  if (filter.value === 'completed') {
    return completedOrders.value
  }

  if (filter.value === 'closed') {
    return closedOrders.value
  }

  return sorted.value
})

const filters = computed<Array<{ label: string, value: Filter, count: number }>>(() => [
  { label: 'All', value: 'all', count: sorted.value.length },
  { label: 'Needs payment', value: 'open', count: openOrders.value.length },
  { label: 'Completed', value: 'completed', count: completedOrders.value.length },
  { label: 'Closed', value: 'closed', count: closedOrders.value.length }
])

/** Template aliases for the shared helpers in `~/utils/orderState`. */
const itemSummary = orderItemSummary
const action = orderPrimaryAction
</script>

<template>
  <SpDashboardPage
    title="Orders & payments"
    icon="i-lucide-receipt"
    description="Prepaid purchases only. Nothing here renews, and no payment method is kept on file for a future charge."
  >
    <template #actions>
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-refresh-cw"
        :loading="orders.loading.value"
        @click="orders.refresh()"
      >
        Refresh
      </UButton>
      <UButton
        to="/dashboard/buy"
        icon="i-lucide-plus"
      >
        New order
      </UButton>
    </template>

    <SpAsyncSection
      :loading="orders.initialLoading.value"
      :unavailable="orders.unavailable.value"
      :failed="orders.failed.value"
      :empty="orders.isEmpty.value"
      :offline="orders.error.value?.code === 'network_unreachable'"
      :error-message="orders.error.value?.message"
      unavailable-title="Order history is not published yet"
      unavailable-description="The control plane has not shipped the orders endpoint. No order can be listed, and none will be invented here."
      empty-title="No orders yet"
      empty-description="Your first purchase will appear here with its payment status and receipt details."
      empty-icon="i-lucide-receipt"
      loading-variant="rows"
      @retry="orders.refresh()"
    >
      <div class="space-y-4">
        <UAlert
          v-if="openOrders.length > 0"
          icon="i-lucide-clock"
          color="warning"
          variant="subtle"
          :title="`${openOrders.length} order${openOrders.length === 1 ? '' : 's'} awaiting payment`"
          description="An unpaid order keeps its price until its payment code expires. Continue from the order below — do not create a duplicate."
        />

        <div
          class="flex flex-wrap gap-1.5"
          role="tablist"
          aria-label="Filter orders"
        >
          <UButton
            v-for="item in filters"
            :key="item.value"
            role="tab"
            :aria-selected="filter === item.value"
            :color="filter === item.value ? 'primary' : 'neutral'"
            :variant="filter === item.value ? 'subtle' : 'ghost'"
            size="sm"
            @click="filter = item.value"
          >
            {{ item.label }}
            <span class="sp-numeric text-dimmed">{{ item.count }}</span>
          </UButton>
        </div>

        <p
          v-if="visible.length === 0"
          class="rounded-lg border border-default bg-elevated/30 p-6 text-center text-sm text-muted"
        >
          No orders in this view.
        </p>

        <ul
          v-else
          class="divide-y divide-default overflow-hidden rounded-lg border border-default"
        >
          <li
            v-for="order in visible"
            :key="order.id"
            class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
            :class="isOrderOpen(order) ? 'bg-warning/5' : 'bg-elevated/20'"
          >
            <div class="min-w-0 space-y-1">
              <div class="flex flex-wrap items-center gap-2">
                <p class="truncate font-mono text-sm text-highlighted">
                  {{ order.reference }}
                </p>
                <SpStatusBadge :status="order.status.toLowerCase()" />
                <UBadge
                  v-if="order.applied_promotion"
                  color="success"
                  variant="subtle"
                  size="sm"
                  icon="i-lucide-tag"
                >
                  {{ order.applied_promotion.code }}
                </UBadge>
              </div>
              <p class="truncate text-sm text-default">
                {{ itemSummary(order) }}
              </p>
              <p class="text-xs text-muted">
                Placed {{ formatDateTime(order.created_at) }}
                <span v-if="order.fulfilled_at">· fulfilled {{ formatDateTime(order.fulfilled_at) }}</span>
              </p>
            </div>

            <div class="flex shrink-0 items-center gap-4">
              <div class="text-right">
                <p class="sp-numeric font-medium text-highlighted">
                  {{ formatMoney(order.total) }}
                </p>
                <p
                  v-if="!isZeroMoney(order.discount_total)"
                  class="sp-numeric text-xs text-success"
                >
                  −{{ formatMoney(order.discount_total) }} discount
                </p>
              </div>

              <UButton
                :to="action(order).to"
                :icon="action(order).icon"
                :color="action(order).primary ? 'primary' : 'neutral'"
                :variant="action(order).primary ? 'solid' : 'subtle'"
                size="sm"
              >
                {{ action(order).label }}
              </UButton>
            </div>
          </li>
        </ul>
      </div>
    </SpAsyncSection>

    <div class="grid gap-3 sm:grid-cols-2">
      <div class="rounded-lg border border-default p-4 text-xs text-muted">
        <p class="font-medium text-default">
          If you paid but the order is not marked paid
        </p>
        <p class="mt-1">
          Open the order and ask SP Cambo to re-check. Verification reads the transfer from Bakong and credits
          the order exactly once, so re-checking is always safe. Paying a second time is not.
        </p>
      </div>
      <div class="rounded-lg border border-default p-4 text-xs text-muted">
        <p class="font-medium text-default">
          Receipts and figures
        </p>
        <p class="mt-1">
          Every amount here is the amount the control plane recorded, in exact units — nothing is recalculated
          in your browser. Discounts shown are the ones the server applied when the order was created.
        </p>
      </div>
    </div>
  </SpDashboardPage>
</template>
