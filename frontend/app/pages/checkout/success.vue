<script setup lang="ts">
import type { Order } from '~/types/commerce'

definePageMeta({
  layout: 'public',
  title: 'Payment Successful',
  robots: 'noindex'
})

const route = useRoute()
const api = useSpApi()
const toast = useToast()

const order = ref<Order | null>(null)
const loading = ref(true)
const claim = ref<{ id: string, status: string } | null>(null)
const polling = ref(false)

const fetchOrder = async () => {
  try {
    const response: Order = await api.orders.get(route.params.id as string)
    order.value = response
    // Check if any package has auto_creates_api_key
    if (response.items.some(item => item.package_slug)) {
      pollForClaim()
    }
  } catch (error) {
    console.error(error)
    toast.add({
      title: 'Could not load order',
      description: toSpApiError(error).message,
      color: 'error',
      icon: 'i-lucide-alert-circle'
    })
  } finally {
    loading.value = false
  }
}

const pollForClaim = async () => {
  if (polling.value) return
  polling.value = true

  try {
    const response = await api.request<Array<{ status: string, id: string }>>('/me/api-key-claims', { method: 'GET' })
    const pending = response.find(c => c.status === 'PENDING')
    if (pending) {
      claim.value = { id: pending.id, status: pending.status }
    } else {
      // Still waiting, poll again in 5 seconds
      setTimeout(() => {
        polling.value = false
        pollForClaim()
      }, 5000)
    }
  } catch {
    // Poll failed, try again in 5 seconds
    setTimeout(() => {
      polling.value = false
      pollForClaim()
    }, 5000)
  }
}

onMounted(fetchOrder)
</script>

<template>
  <div class="w-full max-w-2xl space-y-6 mx-auto px-4 py-12">
    <UCard>
      <template #header>
        <div class="flex items-center gap-3">
          <UIcon
            v-if="!loading"
            name="i-lucide-check-circle"
            class="w-6 h-6 text-green-500"
          />
          <UProgress
            v-else
            animation="carousel"
            class="w-6 h-6"
          />
          <h2 class="text-xl font-semibold">
            {{ loading ? 'Verifying...' : 'Payment Successful' }}
          </h2>
        </div>
      </template>

      <div
        v-if="order"
        class="space-y-4"
      >
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="p-3 bg-gray-50 rounded-lg">
            <p class="text-sm text-gray-600">
              Package
            </p>
            <p class="font-medium">
              {{ order.items[0]?.package_name || 'Package' }}
            </p>
          </div>
          <div class="p-3 bg-gray-50 rounded-lg">
            <p class="text-sm text-gray-600">
              Amount
            </p>
            <p class="font-medium">
              ${{ (Number(order.total.minor) / 100).toFixed(2) }}
            </p>
          </div>
        </div>

        <div
          v-if="order.items.some(item => item.package_slug)"
          class="pt-4 border-t"
        >
          <h3 class="font-medium mb-2">
            API Key Delivery
          </h3>

          <div
            v-if="claim && claim.status === 'PENDING'"
            class="space-y-3"
          >
            <UAlert
              title="Key Ready to Claim"
              color="success"
              icon="i-lucide-check-circle"
            >
              <template #description>
                Your API key has been created and is ready to claim.
              </template>
            </UAlert>
            <UButton
              :to="`/dashboard/claim-key?claim=${claim.id}`"
              color="primary"
              block
            >
              Claim My API Key
            </UButton>
          </div>

          <div
            v-else
            class="space-y-3"
          >
            <UAlert
              title="Preparing Your Key"
              color="info"
              icon="i-lucide-loader-2"
            >
              <template #description>
                Your API key is being prepared. This usually takes a few moments.
              </template>
            </UAlert>
            <UButton
              :loading="loading"
              color="primary"
              block
              variant="outline"
              @click="fetchOrder"
            >
              Check Again
            </UButton>
          </div>
        </div>
      </div>
    </UCard>
  </div>
</template>
