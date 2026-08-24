<script setup lang="ts">
/**
 * Legal page frame: prose column, sibling navigation and a standing notice that
 * the formal agreement has not been published by the operator yet.
 *
 * These pages describe the rules and practices the running service actually
 * enforces. They deliberately do not invent a legal entity, jurisdiction,
 * governing law or contact address — those must come from the project owner.
 */
defineProps<{
  title: string
  description?: string
  /**
   * Date the wording was last reviewed, already written for display.
   *
   * Deliberately not run through `Intl` here: these pages are server-rendered,
   * and a locale-dependent format would differ between server and client.
   */
  reviewed: string
}>()

const links = [
  { label: 'Terms of service', to: '/legal/terms' },
  { label: 'Acceptable use', to: '/legal/acceptable-use' },
  { label: 'Privacy', to: '/legal/privacy' }
]
</script>

<template>
  <UContainer class="py-10 lg:py-14">
    <div class="mx-auto max-w-3xl">
      <div class="mb-8 space-y-4 border-b border-default pb-8">
        <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
          Legal
        </p>
        <h1 class="text-3xl font-semibold tracking-tight text-highlighted text-balance">
          {{ title }}
        </h1>
        <p
          v-if="description"
          class="text-base text-muted text-pretty"
        >
          {{ description }}
        </p>
        <p class="text-sm text-dimmed">
          Last reviewed {{ reviewed }}
        </p>
      </div>

      <UAlert
        icon="i-lucide-scale"
        color="neutral"
        variant="subtle"
        title="Operating rules, not a finalised contract"
        description="This page states how SP Cambo actually operates today, so you can rely on it when deciding how to use the service. The formal agreement — including the contracting entity, governing law and dispute process — has not been published yet, and this page does not invent one."
        class="mb-10"
      />

      <div class="sp-prose">
        <slot />
      </div>

      <nav
        class="mt-14 border-t border-default pt-8"
        aria-label="Legal pages"
      >
        <p class="mb-3 text-xs font-medium tracking-wide text-dimmed uppercase">
          Related
        </p>
        <ul class="grid gap-2 sm:grid-cols-3">
          <li
            v-for="link in links"
            :key="link.to"
          >
            <NuxtLink
              :to="link.to"
              class="block rounded-lg border border-default px-4 py-3 text-sm font-medium transition-colors"
              :class="link.to === $route.path
                ? 'border-accented bg-elevated/60 text-highlighted'
                : 'text-muted hover:border-accented hover:bg-elevated/40 hover:text-default'"
              :aria-current="link.to === $route.path ? 'page' : undefined"
            >
              {{ link.label }}
            </NuxtLink>
          </li>
        </ul>
      </nav>
    </div>
  </UContainer>
</template>
