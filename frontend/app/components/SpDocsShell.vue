<script setup lang="ts">
/**
 * Documentation page frame: sidebar table of contents, prose column and
 * previous/next navigation derived from `useDocsNavigation`.
 */
defineProps<{
  title: string
  description?: string
}>()

const { sidebar, previous, next } = useDocsNavigation()
</script>

<template>
  <UContainer class="py-10 lg:py-14">
    <div class="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-12">
      <aside class="hidden lg:block">
        <nav
          class="sticky top-[calc(var(--ui-header-height)+2rem)] space-y-6"
          aria-label="Documentation"
        >
          <div
            v-for="section in sidebar"
            :key="section.label"
            class="space-y-2"
          >
            <p class="px-2 text-xs font-medium tracking-wide text-dimmed uppercase">
              {{ section.label }}
            </p>
            <ul class="space-y-0.5">
              <li
                v-for="page in section.pages"
                :key="page.to"
              >
                <NuxtLink
                  :to="page.to"
                  class="block rounded-md px-2 py-1.5 text-sm transition-colors"
                  :class="page.active
                    ? 'bg-elevated font-medium text-highlighted'
                    : 'text-muted hover:bg-elevated/60 hover:text-default'"
                  :aria-current="page.active ? 'page' : undefined"
                >
                  {{ page.label }}
                </NuxtLink>
              </li>
            </ul>
          </div>
        </nav>
      </aside>

      <div class="min-w-0">
        <div class="mb-8 space-y-3 border-b border-default pb-8">
          <NuxtLink
            to="/docs"
            class="inline-flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted uppercase transition-colors hover:text-default lg:hidden"
          >
            <UIcon
              name="i-lucide-chevron-left"
              class="size-3.5"
            />
            All documentation
          </NuxtLink>

          <h1 class="text-3xl font-semibold tracking-tight text-highlighted text-balance">
            {{ title }}
          </h1>
          <p
            v-if="description"
            class="text-base text-muted text-pretty"
          >
            {{ description }}
          </p>
        </div>

        <div class="sp-prose">
          <slot />
        </div>

        <nav
          v-if="previous || next"
          class="mt-14 grid gap-3 border-t border-default pt-8 sm:grid-cols-2"
          aria-label="Documentation pagination"
        >
          <NuxtLink
            v-if="previous"
            :to="previous.to"
            class="group rounded-lg border border-default p-4 transition-colors hover:border-accented hover:bg-elevated/40"
          >
            <span class="flex items-center gap-1.5 text-xs text-dimmed">
              <UIcon
                name="i-lucide-arrow-left"
                class="size-3.5"
              />
              Previous
            </span>
            <span class="mt-1 block font-medium text-highlighted">{{ previous.label }}</span>
          </NuxtLink>
          <span v-else />

          <NuxtLink
            v-if="next"
            :to="next.to"
            class="group rounded-lg border border-default p-4 text-right transition-colors hover:border-accented hover:bg-elevated/40 sm:col-start-2"
          >
            <span class="flex items-center justify-end gap-1.5 text-xs text-dimmed">
              Next
              <UIcon
                name="i-lucide-arrow-right"
                class="size-3.5"
              />
            </span>
            <span class="mt-1 block font-medium text-highlighted">{{ next.label }}</span>
          </NuxtLink>
        </nav>
      </div>
    </div>
  </UContainer>
</template>
