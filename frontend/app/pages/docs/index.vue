<script setup lang="ts">
useSeoMeta({
  title: 'Documentation',
  description: 'SP Cambo documentation: quick start, base URLs, authentication, Claude Code and Codex CLI setup, API reference, streaming, errors, rate limits and the billing model.'
})

const { sections } = useDocsNavigation()
const { inferenceRoot } = useCliSnippets()
</script>

<template>
  <div>
    <UContainer class="py-14 sm:py-16">
      <div class="max-w-3xl space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight text-highlighted text-balance">
          Documentation
        </h1>
        <p class="text-lg text-muted text-pretty">
          Everything needed to point a client at SP Cambo, understand what a request costs, and
          handle failures correctly.
        </p>
      </div>

      <div class="mt-10 grid gap-4 sm:grid-cols-3">
        <UCard :ui="{ body: 'space-y-2' }">
          <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
            Control plane
          </p>
          <p class="text-sm text-muted">
            Accounts, packages, orders and keys.
          </p>
          <NuxtLink
            to="/docs/base-urls"
            class="inline-flex text-sm font-medium text-primary hover:underline"
          >
            Base URLs
          </NuxtLink>
        </UCard>

        <UCard :ui="{ body: 'space-y-2' }">
          <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
            Inference gateway
          </p>
          <code class="block truncate font-mono text-xs text-toned">{{ inferenceRoot }}</code>
          <NuxtLink
            to="/docs/api-reference"
            class="inline-flex text-sm font-medium text-primary hover:underline"
          >
            API reference
          </NuxtLink>
        </UCard>

        <UCard :ui="{ body: 'space-y-2' }">
          <p class="text-xs font-medium tracking-wide text-dimmed uppercase">
            In a hurry
          </p>
          <p class="text-sm text-muted">
            Two variables and one command.
          </p>
          <NuxtLink
            to="/docs/quick-start"
            class="inline-flex text-sm font-medium text-primary hover:underline"
          >
            Quick start
          </NuxtLink>
        </UCard>
      </div>

      <div class="mt-14 space-y-10">
        <section
          v-for="section in sections"
          :key="section.label"
          class="space-y-4"
        >
          <h2 class="text-sm font-medium tracking-wide text-dimmed uppercase">
            {{ section.label }}
          </h2>

          <ul class="grid gap-3 sm:grid-cols-2">
            <li
              v-for="page in section.pages"
              :key="page.to"
            >
              <NuxtLink
                :to="page.to"
                class="group flex h-full items-start justify-between gap-4 rounded-lg border border-default bg-elevated/25 p-5 transition-colors hover:border-accented hover:bg-elevated/50"
              >
                <span class="space-y-1.5">
                  <span class="block font-medium text-highlighted">{{ page.label }}</span>
                  <span class="block text-sm text-muted text-pretty">{{ page.description }}</span>
                </span>
                <UIcon
                  name="i-lucide-arrow-up-right"
                  class="mt-0.5 size-4 shrink-0 text-dimmed transition-colors group-hover:text-default"
                />
              </NuxtLink>
            </li>
          </ul>
        </section>
      </div>
    </UContainer>
  </div>
</template>
