<script setup lang="ts">
/**
 * A code reference table for the errors documentation.
 *
 * Three of these exist — control plane, inference gateway, and the codes SP Cambo's
 * own clients classify locally — and they differ only in what the second column is
 * called, so the markup lives here rather than three times over.
 *
 * `rows` is typed structurally on purpose: the page owns the shape of its own view
 * model and does not need to import a type from a component to describe it.
 */
defineProps<{
  rows: {
    code: string
    status: string
    meaning: string
    action: string
    retryable: boolean
  }[]
  /** Header for the status column: "HTTP" for a served code, "Seen as" for a local one. */
  statusLabel: string
}>()
</script>

<template>
  <div class="sp-scroll-x my-6 rounded-lg border border-default">
    <table class="w-full text-left text-sm">
      <thead class="border-b border-default bg-elevated/50 text-xs tracking-wide text-muted uppercase">
        <tr>
          <th class="px-4 py-3 font-medium">
            Code
          </th>
          <th class="px-4 py-3 font-medium">
            {{ statusLabel }}
          </th>
          <th class="px-4 py-3 font-medium">
            Meaning
          </th>
          <th class="px-4 py-3 font-medium">
            What to do
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-default">
        <tr
          v-for="row in rows"
          :key="row.code"
        >
          <td class="px-4 py-3 align-top whitespace-nowrap">
            <code class="font-mono text-xs text-toned">{{ row.code }}</code>
            <UBadge
              v-if="row.retryable"
              color="neutral"
              variant="subtle"
              size="sm"
              class="mt-1.5 block w-fit"
            >
              Retryable
            </UBadge>
          </td>
          <td class="px-4 py-3 align-top font-mono text-xs text-muted whitespace-nowrap">
            {{ row.status }}
          </td>
          <td class="px-4 py-3 align-top text-muted">
            {{ row.meaning }}
          </td>
          <td class="px-4 py-3 align-top text-muted">
            {{ row.action }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
