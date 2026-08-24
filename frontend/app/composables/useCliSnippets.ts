/**
 * Ready-to-paste CLI and SDK configuration.
 *
 * The snippet text itself is built by `~/utils/cliSnippets`, which is pure and
 * unit-tested; this composable only makes it reactive to runtime config and to
 * the selected model alias. Snippets are derived from public runtime config,
 * never from a hard-coded host, and never contain a real credential or an
 * internal OmniRoute URL.
 */
export function useCliSnippets(options: {
  modelAlias?: MaybeRef<string | null | undefined>
  apiKey?: MaybeRef<string | null | undefined>
} = {}) {
  const config = useRuntimeConfig()

  const snippets = computed(() => buildCliSnippets({
    inferenceRootUrl: config.public.inferenceRootUrl,
    modelAlias: unref(options.modelAlias),
    apiKey: unref(options.apiKey)
  }))

  /** Gateway root without a trailing slash. Claude Code appends `/v1/messages` itself. */
  const inferenceRoot = computed(() => snippets.value.inferenceRoot)

  /** OpenAI-compatible base, which must end in `/v1`. */
  const openAiBase = computed(() => snippets.value.openAiBase)

  return {
    inferenceRoot,
    openAiBase,
    keyPlaceholder: API_KEY_PLACEHOLDER,
    claudeCodeShell: computed(() => snippets.value.claudeCodeShell),
    claudeCodePowerShell: computed(() => snippets.value.claudeCodePowerShell),
    claudeCodeSettingsJson: computed(() => snippets.value.claudeCodeSettingsJson),
    codexConfig: computed(() => snippets.value.codexConfig),
    codexShell: computed(() => snippets.value.codexShell),
    curlMessages: computed(() => snippets.value.curlMessages),
    pythonAnthropic: computed(() => snippets.value.pythonAnthropic),
    nodeAnthropic: computed(() => snippets.value.nodeAnthropic),
    openAiPython: computed(() => snippets.value.openAiPython)
  }
}
