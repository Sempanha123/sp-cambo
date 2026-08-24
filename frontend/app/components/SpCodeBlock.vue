<script setup lang="ts">
withDefaults(defineProps<{
  code: string
  /** Shown in the header strip, e.g. `bash`, `~/.codex/config.toml`. */
  filename?: string
  language?: string
  copyable?: boolean
}>(), {
  filename: undefined,
  language: undefined,
  copyable: true
})
</script>

<template>
  <div class="sp-code-block">
    <div
      v-if="filename || language || copyable"
      class="sp-code-block__header flex items-center justify-between gap-3 px-3 py-2"
    >
      <span class="truncate font-mono text-xs">
        {{ filename ?? language }}
      </span>

      <SpCopyButton
        v-if="copyable"
        :value="code"
        size="sm"
        class="sp-code-block__copy"
      />
    </div>

    <div class="sp-code-block__content sp-scroll-x">
      <pre class="px-4 py-3.5 font-mono text-[13px]/relaxed"><code>{{ code }}</code></pre>
    </div>
  </div>
</template>
