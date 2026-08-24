<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Telegram bot', robots: 'noindex, nofollow' })

const api = useSpApi()
const toast = useToast()
const account = await useSpResource('me:telegram', () => api.account.telegram(), { server: false })
const creating = ref(false)
const unlinking = ref(false)
const linkToken = ref<{ token: string, expires_at: string } | null>(null)

const createToken = async () => {
  creating.value = true
  try {
    linkToken.value = await api.account.createTelegramLinkToken()
    toast.add({ title: 'Link code created', description: 'Send this one-time code to the SP Cambo Telegram bot within 10 minutes.', color: 'success' })
  } catch (error) {
    toast.add({ title: 'Could not create link code', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally {
    creating.value = false
  }
}

const unlink = async () => {
  unlinking.value = true
  try {
    await api.account.unlinkTelegram()
    linkToken.value = null
    await account.refresh()
    toast.add({ title: 'Telegram disconnected', color: 'success' })
  } catch (error) {
    toast.add({ title: 'Could not disconnect Telegram', description: error instanceof Error ? error.message : 'Please try again.', color: 'error' })
  } finally {
    unlinking.value = false
  }
}
</script>

<template>
  <SpDashboardPage
    title="Telegram bot"
    description="Link Telegram securely, browse plans, pay with Bakong KHQR, and receive your SP Cambo API access after server-side payment verification."
    eyebrow="Account integration"
  >
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(20rem,.85fr)]">
      <UCard>
        <template #header>
          <div>
            <h2 class="font-semibold text-highlighted">Connection</h2>
            <p class="mt-1 text-sm text-muted">A one-time dashboard code binds this SP Cambo account to exactly one Telegram chat.</p>
          </div>
        </template>

        <SpResourceState
          :loading="account.initialLoading.value"
          :unavailable="account.unavailable.value"
          :failed="account.failed.value"
          :offline="account.error.value?.code === 'network_unreachable'"
          :error-message="account.error.value?.message"
          error-title="Telegram status could not be loaded"
          @retry="account.refresh()"
        >
          <div v-if="account.data.value?.linked" class="space-y-4">
            <UAlert
              color="success"
              variant="subtle"
              icon="i-lucide-circle-check"
              title="Telegram is linked"
              :description="account.data.value.username ? `@${account.data.value.username}` : 'Your Telegram chat is connected.'"
            />
            <UButton color="error" variant="soft" icon="i-lucide-unlink" :loading="unlinking" @click="unlink">
              Disconnect Telegram
            </UButton>
          </div>

          <div v-else class="space-y-4">
            <UButton icon="i-lucide-link" :loading="creating" @click="createToken">Create one-time link code</UButton>
            <UAlert v-if="linkToken" color="info" variant="subtle" title="Send this command to the SP Cambo bot">
              <template #description>
                <div class="mt-2 space-y-2">
                  <code class="block overflow-x-auto rounded-md bg-default px-3 py-2 text-sm">/link {{ linkToken.token }}</code>
                  <p class="text-xs">Expires {{ new Date(linkToken.expires_at).toLocaleString() }}. The code becomes unusable after it is linked.</p>
                </div>
              </template>
            </UAlert>
          </div>
        </SpResourceState>
      </UCard>

      <UCard>
        <template #header>
          <h2 class="font-semibold text-highlighted">Bot purchase flow</h2>
        </template>
        <ol class="space-y-4 text-sm text-muted">
          <li><strong class="text-highlighted">1.</strong> Link this dashboard account with <code>/link CODE</code>.</li>
          <li><strong class="text-highlighted">2.</strong> Send <code>/plans</code> and then <code>/buy PLAN_SLUG</code>.</li>
          <li><strong class="text-highlighted">3.</strong> Pay the Bakong KHQR generated for that exact order.</li>
          <li><strong class="text-highlighted">4.</strong> Use <code>/check</code>, or let the server reconciler verify it automatically.</li>
          <li><strong class="text-highlighted">5.</strong> After verification, the bot delivers a new one-time SP Cambo API key, base URLs and purchased model aliases.</li>
        </ol>
        <UAlert class="mt-5" color="warning" variant="subtle" icon="i-lucide-shield-alert" title="Treat delivered API keys like passwords" description="The full secret is sent once. Do not forward or publish that Telegram message." />
      </UCard>
    </div>
  </SpDashboardPage>
</template>
