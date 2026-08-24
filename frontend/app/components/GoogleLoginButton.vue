<script setup lang="ts">
import type { RouteLocationRaw } from '#vue-router'

const props = defineProps<{
  intent?: 'login' | 'link'
  domain?: string
  redirectTo?: RouteLocationRaw
  mode?: 'login' | 'register' | 'link'
}>()

const emit = defineEmits<{
  (e: 'click', ev: MouseEvent): void
}>()

const api = useSpApi()
const loading = ref(false)
const error = ref<string | null>(null)

const resolvedIntent = computed<'login' | 'link'>(() =>
  props.intent ?? (props.mode === 'link' ? 'link' : 'login')
)

const handleGoogleLogin = async () => {
  loading.value = true
  error.value = null

  try {
    // The callback happens after a full-page trip through Google. Keep only
    // non-sensitive navigation intent in sessionStorage; credentials never go here.
    sessionStorage.setItem('google_auth_intent', resolvedIntent.value)

    if (props.redirectTo) {
      sessionStorage.setItem('google_redirect_to', JSON.stringify(props.redirectTo))
    } else {
      sessionStorage.removeItem('google_redirect_to')
    }

    const response = await api.google.redirect({
      intent: resolvedIntent.value,
      domain: props.domain
    })

    window.location.assign(response.url)
  } catch (err) {
    error.value = toSpApiError(err).message
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-2">
    <UButton
      :loading="loading"
      :disabled="loading"
      icon="i-lucide-log-in"
      color="neutral"
      variant="solid"
      block
      @click="(ev) => { handleGoogleLogin(); emit('click', ev) }"
    >
      <template #leading>
        <img
          src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg"
          alt=""
          aria-hidden="true"
          class="h-5 w-5"
        >
      </template>
      <span>
        <slot>
          {{ resolvedIntent === 'link' ? 'Link Google account' : 'Continue with Google' }}
        </slot>
      </span>
    </UButton>

    <UAlert
      v-if="error"
      role="alert"
      icon="i-lucide-circle-alert"
      color="error"
      variant="subtle"
      :description="error"
    />
  </div>
</template>
