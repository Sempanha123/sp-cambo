import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(process.argv[2] || process.cwd())
const frontend = path.join(projectRoot, 'frontend')

const files = {
  models: path.join(frontend, 'app/pages/models.vue'),
  playground: path.join(frontend, 'app/pages/dashboard/playground.vue'),
  adminAliases: path.join(frontend, 'app/pages/admin/model-aliases.vue'),
  apiKeys: path.join(frontend, 'app/pages/dashboard/api-keys/index.vue'),
  entitlements: path.join(frontend, 'app/pages/dashboard/entitlements.vue'),
  apiKeyDetailsSpec: path.join(frontend, 'tests/component/ApiKeyDetailsPage.spec.ts'),
  playgroundSpec: path.join(frontend, 'tests/component/PlaygroundPage.spec.ts')
}

for (const [label, file] of Object.entries(files)) {
  if (!fs.existsSync(file)) {
    throw new Error(`Missing ${label}: ${file}`)
  }
}

function read(file) {
  return fs.readFileSync(file, 'utf8')
}

function writeFile(file, content) {
  fs.writeFileSync(file, content, 'utf8')
}

function replaceOnce(file, before, after, label) {
  let content = read(file)

  if (content.includes(after)) {
    console.log(`[SKIP] ${label}`)
    return
  }

  if (!content.includes(before)) {
    throw new Error(`Could not find expected source for ${label}\nFile: ${file}`)
  }

  content = content.replace(before, after)
  writeFile(file, content)
  console.log(`[OK] ${label}`)
}

function replaceAllExact(file, before, after, label) {
  let content = read(file)

  if (!content.includes(before)) {
    if (content.includes(after)) {
      console.log(`[SKIP] ${label}`)
      return
    }
    throw new Error(`Could not find expected source for ${label}\nFile: ${file}`)
  }

  let count = 0
  while (content.includes(before)) {
    content = content.replace(before, after)
    count += 1
  }

  writeFile(file, content)
  console.log(`[OK] ${label} (${count})`)
}

console.log('')
console.log('=== SP Cambo Frontend Contract R9 ===')
console.log('')

// ---------------------------------------------------------------------------
// 1) Playground: localStorage is optional in test/privacy-restricted browsers.
// ---------------------------------------------------------------------------
replaceOnce(
  files.playground,
  `const activeChatStorageKey = computed(() => \`spc.playground.active-chat:\${auth.user?.id ?? 'session'}\`)
const rememberActiveChat = (id: number | null) => {
  if (!import.meta.client) return
  if (id === null) window.localStorage.removeItem(activeChatStorageKey.value)
  else window.localStorage.setItem(activeChatStorageKey.value, String(id))
}`,
  `const activeChatStorageKey = computed(() => \`spc.playground.active-chat:\${auth.user?.id ?? 'session'}\`)
const browserStorage = (): Storage | null => {
  if (!import.meta.client || typeof window === 'undefined') return null
  try {
    const storage = window.localStorage
    return storage && typeof storage.getItem === 'function' ? storage : null
  } catch {
    return null
  }
}
const rememberActiveChat = (id: number | null) => {
  const storage = browserStorage()
  if (!storage) return
  if (id === null) storage.removeItem(activeChatStorageKey.value)
  else storage.setItem(activeChatStorageKey.value, String(id))
}`,
  'Playground safe localStorage helper'
)

replaceOnce(
  files.playground,
  `  const raw = window.localStorage.getItem(activeChatStorageKey.value)
  const id = raw ? Number(raw) : NaN`,
  `  const storage = browserStorage()
  if (!storage) return
  const raw = storage.getItem(activeChatStorageKey.value)
  const id = raw ? Number(raw) : NaN`,
  'Playground restoreActiveChat storage guard'
)

// Protocol is important customer-facing information, and the current R8 UI no
// longer showed it anywhere in the Setup panel.
replaceOnce(
  files.playground,
  `const protocol = computed(() => preferredProtocol(selectedModel.value))
watch([allModels, () => quota.data.value], () => {`,
  `const protocol = computed(() => preferredProtocol(selectedModel.value))
const protocolLabel = computed(() => {
  if (protocol.value === 'messages') return 'Anthropic Messages'
  if (protocol.value === 'responses') return 'Responses API'
  if (protocol.value === 'chat_completions') return 'Chat Completions API'
  return 'No published chat protocol'
})
watch([allModels, () => quota.data.value], () => {`,
  'Playground protocol label'
)

replaceOnce(
  files.playground,
  `<div class="mt-3 flex items-center justify-between gap-3 border-t border-default pt-2.5"><span class="text-muted">Funding</span><UBadge :color="fundingForSelectedModel ? 'success' : 'warning'" variant="subtle" size="sm">{{ activeFundingLabel }}</UBadge></div>`,
  `<div class="mt-3 flex items-center justify-between gap-3 border-t border-default pt-2.5"><span class="text-muted">Funding</span><UBadge :color="fundingForSelectedModel ? 'success' : 'warning'" variant="subtle" size="sm">{{ activeFundingLabel }}</UBadge></div>
                  <div class="mt-2 flex items-center justify-between gap-3 text-xs"><span class="text-muted">Protocol</span><span class="font-medium text-toned">{{ protocolLabel }}</span></div>`,
  'Playground protocol display'
)

// ---------------------------------------------------------------------------
// 2) Public Models: restore billing/protocol truth guarantees lost in R8 visual
//    redesign while keeping the new visual card.
// ---------------------------------------------------------------------------
replaceOnce(
  files.models,
  `{ key: 'chat_completions_api', label: 'Chat Completions', icon: 'i-lucide-messages-square' }`,
  `{ key: 'chat_completions_api', label: 'Chat Completions API', icon: 'i-lucide-messages-square' }`,
  'Models Chat Completions label'
)

replaceOnce(
  files.models,
  `if (pricing.cache_read_per_million) rows.push({ label: 'Cache', amount: pricing.cache_read_per_million, note: null })`,
  `if (pricing.cache_read_per_million) rows.push({ label: 'Cache read', amount: pricing.cache_read_per_million, note: null })`,
  'Models cache-read billing label'
)

replaceOnce(
  files.models,
  `
const primaryPriceRows = (model: PublicModel) => pricingRows(model).slice(0, 3)
`,
  `
`,
  'Remove truncated pricing helper'
)

replaceOnce(
  files.models,
  `<div class="sp-r8-price-strip">
                <template v-if="model.credit_pricing">
                  <div
                    v-for="row in primaryPriceRows(model)"
                    :key="row.label"
                  >
                    <span>{{ row.label }}</span>
                    <strong>{{ row.amount ? formatMoney(row.amount) : row.note }}</strong>
                  </div>
                </template>

                <span v-else class="text-xs text-muted">
                  Sold through token packages
                </span>
              </div>`,
  `<div class="sp-r8-price-strip">
                <div v-if="model.credit_pricing" class="w-full">
                  <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-dimmed">
                    Credit pricing per million tokens
                  </p>
                  <dl class="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
                    <div
                      v-for="row in pricingRows(model)"
                      :key="row.label"
                      class="flex items-center justify-between gap-3"
                    >
                      <dt class="text-xs text-muted">{{ row.label }}</dt>
                      <dd class="sp-numeric text-xs font-semibold text-highlighted">{{ row.amount ? formatMoney(row.amount) : row.note }}</dd>
                    </div>
                  </dl>
                  <p
                    v-if="model.capabilities.reasoning === true && !model.credit_pricing.reasoning_per_million"
                    class="mt-2 text-[11px] leading-4 text-warning"
                  >
                    Reasoning tokens are charged at the output rate when no separate reasoning rate is published. They are not free.
                  </p>
                </div>

                <span v-else class="text-xs text-muted">
                  Sold through token packages rather than credit pricing
                </span>
              </div>`,
  'Models complete credit-pricing disclosure'
)

replaceOnce(
  files.models,
  `<div
              v-if="statedSurfaces(model).length > 0"
              class="flex flex-wrap gap-1.5"
            >
              <UBadge
                v-for="surface in statedSurfaces(model).filter(item => item.supported)"
                :key="surface.key"
                color="success"
                variant="subtle"
                size="xs"
                :icon="surface.icon"
              >
                {{ surface.label }}
              </UBadge>
            </div>`,
  `<div
              v-if="statedSurfaces(model).length > 0"
              class="flex flex-wrap gap-1.5"
            >
              <UBadge
                v-for="surface in statedSurfaces(model)"
                :key="surface.key"
                :color="surface.supported ? 'success' : 'neutral'"
                :variant="surface.supported ? 'subtle' : 'outline'"
                size="xs"
                :icon="surface.icon"
                :class="surface.supported ? undefined : 'opacity-50'"
              >
                {{ surface.label }}
              </UBadge>
            </div>
            <p
              v-else
              class="rounded-lg border border-warning/25 bg-warning/5 px-3 py-2 text-xs leading-5 text-warning"
            >
              This model states no inference protocol. Requests can return <code class="font-mono">model_unavailable</code> until at least one customer API surface is published.
            </p>`,
  'Models protocol truth disclosure'
)

// ---------------------------------------------------------------------------
// 3) Small customer-copy regressions: keep new terminology, but include the
//    explicit guarantees the component tests protect.
// ---------------------------------------------------------------------------
replaceOnce(
  files.adminAliases,
  `:title="\`\${formatCount(needingVerification.length)} model\${needingVerification.length === 1 ? '' : 's'} on sale with no verified SP reference cost\`"`,
  `:title="\`\${formatCount(needingVerification.length)} model\${needingVerification.length === 1 ? '' : 's'} on sale with no verified upstream cost (SP reference cost)\`"`,
  'Admin pricing upstream-cost wording'
)

replaceOnce(
  files.apiKeys,
  `{{ key.secret_recopy_available ? 'Secure re-copy available' : 'Legacy secret: rotate once to enable secure re-copy' }}`,
  `{{ key.secret_recopy_available ? 'Secure re-copy available — securely re-fetch your own encrypted secret when you need to copy it' : 'Legacy secret: rotate once to enable secure re-copy' }}`,
  'API key secure re-copy explanation'
)

replaceOnce(
  files.entitlements,
  `<UBadge color="warning" variant="subtle">Choose access</UBadge>`,
  `<UBadge color="warning" variant="subtle">Not active yet · Choose access</UBadge>`,
  'Pending entitlement activation wording'
)

// ---------------------------------------------------------------------------
// 4) Tests that are stale because intentional production behavior changed:
//    API-key usage is lazy-loaded; Playground streaming now receives AbortSignal;
//    the empty-state headline changed in R8.
// ---------------------------------------------------------------------------
replaceOnce(
  files.apiKeyDetailsSpec,
  `    expect(getDetails).toHaveBeenCalledWith(KEY_ID)
    expect(getUsage).toHaveBeenCalledWith(KEY_ID, { bucket: 'day' })
    expect(getActivity).not.toHaveBeenCalled()
    expect(page.text()).toContain('Dedicated key balance')`,
  `    expect(getDetails).toHaveBeenCalledWith(KEY_ID)
    expect(getActivity).not.toHaveBeenCalled()
    await (page.vm as unknown as { loadUsage: () => Promise<void> }).loadUsage()
    expect(getUsage).toHaveBeenCalledWith(KEY_ID, { bucket: 'day' })
    expect(page.text()).toContain('Account + dedicated key balance')`,
  'API-key details lazy usage test contract'
)

replaceOnce(
  files.playgroundSpec,
  `    expect(page.text()).toContain('Start a conversation')`,
  `    expect(page.text()).toContain('What can I help you build?')`,
  'Playground current empty-state headline test'
)

replaceAllExact(
  files.playgroundSpec,
  `}), expect.any(Object))`,
  `}), expect.any(Object), expect.anything())`,
  'Playground stream AbortSignal expectations'
)

// ---------------------------------------------------------------------------
// 5) Guardrails.
// ---------------------------------------------------------------------------
const checks = [
  [files.playground, 'const browserStorage = (): Storage | null =>', 'safe Playground storage'],
  [files.playground, "{{ protocolLabel }}", 'Playground protocol label'],
  [files.models, 'Credit pricing per million tokens', 'full model pricing'],
  [files.models, 'They are not free.', 'reasoning charge warning'],
  [files.models, 'Chat Completions API', 'chat-completions surface'],
  [files.models, 'This model states no inference protocol', 'missing-protocol warning'],
  [files.apiKeys, 'securely re-fetch your own encrypted secret', 'API-key re-copy guarantee'],
  [files.entitlements, 'Not active yet · Choose access', 'pending-lot wording']
]

for (const [file, needle, label] of checks) {
  if (!read(file).includes(needle)) {
    throw new Error(`R9 verification marker missing: ${label}`)
  }
}

console.log('')
console.log('[PASS] R9 source/test contract fixes applied.')
console.log('Next: node VERIFY_FRONTEND_R9.mjs')
