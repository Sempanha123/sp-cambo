<script setup lang="ts">
useSeoMeta({
  title: 'Base URLs',
  description: 'The two SP Cambo base URLs: the control plane for account operations and the inference gateway for model requests.'
})

const config = useRuntimeConfig()
const { inferenceRoot, openAiBase } = useCliSnippets()

const controlPlane = computed(() => config.public.apiBaseUrl.replace(/\/+$/, ''))

const rows = computed(() => [
  {
    purpose: 'Account, packages, orders, keys, usage',
    url: controlPlane.value,
    credential: 'Browser session (from signing in)',
    note: 'Used by this website. Not intended for your application code.'
  },
  {
    purpose: 'Anthropic-compatible inference',
    url: inferenceRoot.value,
    credential: 'SP Cambo API key',
    note: 'Root only. The client appends /v1/messages itself.'
  },
  {
    purpose: 'OpenAI-compatible inference',
    url: openAiBase.value,
    credential: 'SP Cambo API key',
    note: 'Must end in /v1. Configure the Responses wire API.'
  }
])
</script>

<template>
  <SpDocsShell
    title="Base URLs"
    description="SP Cambo has two surfaces. Sending a request to the wrong one is the most common setup mistake."
  >
    <h2 id="the-two-surfaces">
      The two surfaces
    </h2>
    <p>
      The <strong>control plane</strong> manages your account: packages, orders, entitlements, API
      keys and usage. The <strong>inference gateway</strong> serves model requests. They authenticate
      differently and they are not interchangeable.
    </p>

    <div class="sp-scroll-x my-6 rounded-lg border border-default">
      <table class="w-full text-left text-sm">
        <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
          <tr>
            <th class="px-4 py-3 font-medium">
              Purpose
            </th>
            <th class="px-4 py-3 font-medium">
              Base URL
            </th>
            <th class="px-4 py-3 font-medium">
              Credential
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr
            v-for="row in rows"
            :key="row.url + row.purpose"
          >
            <td class="px-4 py-3 align-top text-default">
              {{ row.purpose }}
              <span class="mt-1 block text-xs text-muted">{{ row.note }}</span>
            </td>
            <td class="px-4 py-3 align-top">
              <span class="flex items-center gap-2">
                <code class="font-mono text-xs text-toned">{{ row.url }}</code>
                <SpCopyButton
                  :value="row.url"
                  size="sm"
                />
              </span>
            </td>
            <td class="px-4 py-3 align-top text-muted">
              {{ row.credential }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <h2 id="the-v1-trap">
      The <code>/v1</code> trap
    </h2>
    <p>
      Anthropic-compatible clients append <code>/v1/messages</code> to whatever base URL you give
      them. If you include <code>/v1</code> yourself, the client will request
      <code>/v1/v1/messages</code> and every call will fail with a not-found error that looks like an
      outage. Use the root for <code>ANTHROPIC_BASE_URL</code>.
    </p>
    <p>
      OpenAI-compatible clients are the opposite: their base URL <em>must</em> end in
      <code>/v1</code>.
    </p>

    <h2 id="what-you-will-never-see">
      What you will never see
    </h2>
    <p>
      SP Cambo does not publish, and will never ask you to configure, an upstream provider URL or an
      internal routing endpoint. If a setup guide anywhere tells you to point a client at an internal
      host or a loopback port, it is not ours. The only hosts you need are in the table above.
    </p>

    <h2 id="local-development">
      Local development
    </h2>
    <p>
      In a local development environment these URLs point at your own running services rather than
      production, which is why they are read from configuration rather than hard-coded. The values in
      the table are the ones this build is configured with, so they are always the correct ones for
      the site you are reading.
    </p>
  </SpDocsShell>
</template>
