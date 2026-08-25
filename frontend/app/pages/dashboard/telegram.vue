<script setup lang="ts">
definePageMeta({ layout: 'dashboard', middleware: ['auth'] })
useSeoMeta({ title: 'Telegram Store', robots: 'noindex, nofollow' })

const config = useRuntimeConfig()
const botUsername = computed(() => String(config.public.telegramBotUsername || '').trim().replace(/^@/, ''))
const botUrl = computed(() => botUsername.value ? `https://t.me/${botUsername.value}?start=store` : null)
</script>

<template>
  <SpDashboardPage
    title="Telegram Store"
    eyebrow="Standalone sales channel"
    description="Customers do not need to link a website account. They open the bot, choose a product, pay by Bakong KHQR, and receive a newly generated SP Cambo API key automatically after server-side payment verification."
  >
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(20rem,.85fr)]">
      <UCard class="sp-premium-card">
        <template #header>
          <div>
            <h2 class="font-semibold text-highlighted">
              Customer flow
            </h2>
            <p class="mt-1 text-sm text-muted">
              The Telegram identity becomes its own SP Cambo customer workspace automatically.
            </p>
          </div>
        </template>

        <div class="space-y-5">
          <ol class="space-y-4 text-sm text-muted">
            <li><strong class="text-highlighted">1.</strong> Customer opens the bot and sends <code>/start</code> or <code>/shop</code>.</li>
            <li><strong class="text-highlighted">2.</strong> The bot shows only published API-access packages with inline <strong>Buy</strong> buttons.</li>
            <li><strong class="text-highlighted">3.</strong> Tapping Buy creates the exact order and returns its Bakong KHQR payment payload.</li>
            <li><strong class="text-highlighted">4.</strong> The server reconciles payment automatically; the customer may also tap <strong>I've paid — check now</strong>.</li>
            <li><strong class="text-highlighted">5.</strong> After verified payment, the bot creates and sends a one-time API key, model aliases, Claude Code setup commands, and the no-login Key Checker URL.</li>
          </ol>

          <div class="flex flex-wrap gap-3">
            <UButton
              v-if="botUrl"
              :to="botUrl"
              target="_blank"
              icon="i-lucide-send"
              trailing-icon="i-lucide-external-link"
            >
              Open @{{ botUsername }}
            </UButton>
            <UButton
              to="/public/key-checker"
              color="neutral"
              variant="subtle"
              icon="i-lucide-gauge"
            >
              Open public Key Checker
            </UButton>
          </div>

          <UAlert
            v-if="!botUrl"
            color="warning"
            variant="subtle"
            icon="i-lucide-settings"
            title="Set the public bot username"
            description="Set NUXT_PUBLIC_TELEGRAM_BOT_USERNAME to your bot username (without @) so this dashboard can open it directly. The Telegram webhook itself uses TELEGRAM_BOT_TOKEN on Laravel."
          />
        </div>
      </UCard>

      <div class="space-y-5">
        <UAlert
          color="success"
          variant="subtle"
          icon="i-lucide-badge-check"
          title="No /link code required"
          description="The old one-time dashboard link flow is not part of the normal purchase experience. Existing linked Telegram accounts remain usable, but a new Telegram customer can buy directly."
        />
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-shield-check"
          title="API keys are secrets"
          description="The full generated key is delivered once in the customer's private Telegram chat. The public Key Checker accepts that key without website login, so customers should never forward the key or the delivery message."
        />
        <UAlert
          color="neutral"
          variant="subtle"
          icon="i-lucide-refresh-cw"
          title="Automatic payment delivery"
          description="Keep the Laravel scheduler running. telegram:reconcile-purchases checks pending Telegram orders every minute and delivers the API key when the payment provider confirms the order."
        />
      </div>
    </div>
  </SpDashboardPage>
</template>
