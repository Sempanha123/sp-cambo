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
  if (!fs.existsSync(file)) throw new Error(`Missing ${label}: ${file}`)
}

const read = file => fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n')
const save = (file, content) => fs.writeFileSync(file, content.replace(/\r\n/g, '\n'), 'utf8')

function ensureContains(file, needle, label) {
  if (!read(file).includes(needle)) {
    throw new Error(`R9.1 verification marker missing: ${label}\nFile: ${file}`)
  }
  console.log(`[OK] ${label}`)
}

function ensureNotContains(file, needle, label) {
  if (read(file).includes(needle)) {
    throw new Error(`R9.1 verification failed: ${label}\nFile: ${file}`)
  }
  console.log(`[OK] ${label}`)
}

function replaceLiteral(file, before, after, label) {
  let content = read(file)
  if (content.includes(after)) {
    console.log(`[SKIP] ${label}`)
    return
  }
  if (!content.includes(before)) {
    throw new Error(`Could not find expected source for ${label}\nFile: ${file}`)
  }
  content = content.replace(before, after)
  save(file, content)
  console.log(`[OK] ${label}`)
}

function replaceRegex(file, regex, replacement, label, alreadyNeedle = null) {
  let content = read(file)
  if (alreadyNeedle && content.includes(alreadyNeedle)) {
    console.log(`[SKIP] ${label}`)
    return
  }
  if (!regex.test(content)) {
    throw new Error(`Could not find source pattern for ${label}\nFile: ${file}`)
  }
  content = content.replace(regex, replacement)
  save(file, content)
  console.log(`[OK] ${label}`)
}

function replaceAllLiteral(file, before, after, label) {
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
    count++
  }
  save(file, content)
  console.log(`[OK] ${label} (${count})`)
}

console.log('')
console.log('=== SP Cambo Frontend Contract R9.1 ===')
console.log('')

// ---------------------------------------------------------------------------
// 1) Playground localStorage safety. Marker-based so CRLF or nearby edits do not
//    break the patch.
// ---------------------------------------------------------------------------
replaceRegex(
  files.playground,
  /const activeChatStorageKey = computed\(\(\) => `spc\.playground\.active-chat:\$\{auth\.user\?\.id \?\? 'session'\}`\)\nconst rememberActiveChat = \(id: number \| null\) => \{[\s\S]*?\n\}\n\nconst newClientChatKey = \(\) => \{/,
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
}

const newClientChatKey = () => {`,
  'Playground safe localStorage helper',
  'const browserStorage = (): Storage | null =>'
)

replaceRegex(
  files.playground,
  /const restoreActiveChat = async \(\) => \{\n  if \(!import\.meta\.client \|\| currentChatId\.value !== null \|\| messages\.value\.length > 0\) return\n  const raw = window\.localStorage\.getItem\(activeChatStorageKey\.value\)\n  const id = raw \? Number\(raw\) : NaN/,
  `const restoreActiveChat = async () => {
  if (!import.meta.client || currentChatId.value !== null || messages.value.length > 0) return
  const storage = browserStorage()
  if (!storage) return
  const raw = storage.getItem(activeChatStorageKey.value)
  const id = raw ? Number(raw) : NaN`,
  'Playground restoreActiveChat storage guard',
  'const storage = browserStorage()\n  if (!storage) return\n  const raw = storage.getItem(activeChatStorageKey.value)'
)

// Protocol label and display.
replaceRegex(
  files.playground,
  /const protocol = computed\(\(\) => preferredProtocol\(selectedModel\.value\)\)\nwatch\(\[allModels, \(\) => quota\.data\.value\], \(\) => \{/,
  `const protocol = computed(() => preferredProtocol(selectedModel.value))
const protocolLabel = computed(() => {
  if (protocol.value === 'messages') return 'Anthropic Messages'
  if (protocol.value === 'responses') return 'Responses API'
  if (protocol.value === 'chat_completions') return 'Chat Completions API'
  return 'No published chat protocol'
})
watch([allModels, () => quota.data.value], () => {`,
  'Playground protocol label',
  'const protocolLabel = computed(() => {'
)

replaceRegex(
  files.playground,
  /(<div class="mt-3 flex items-center justify-between gap-3 border-t border-default pt-2\.5"><span class="text-muted">Funding<\/span><UBadge :color="fundingForSelectedModel \? 'success' : 'warning'" variant="subtle" size="sm">\{\{ activeFundingLabel \}\}<\/UBadge><\/div>)/,
  `$1
                  <div class="mt-2 flex items-center justify-between gap-3 text-xs"><span class="text-muted">Protocol</span><span class="font-medium text-toned">{{ protocolLabel }}</span></div>`,
  'Playground protocol display',
  '{{ protocolLabel }}'
)

// ---------------------------------------------------------------------------
// 2) Models contract restoration.
// ---------------------------------------------------------------------------
replaceLiteral(
  files.models,
  `{ key: 'chat_completions_api', label: 'Chat Completions', icon: 'i-lucide-messages-square' }`,
  `{ key: 'chat_completions_api', label: 'Chat Completions API', icon: 'i-lucide-messages-square' }`,
  'Models Chat Completions label'
)

replaceLiteral(
  files.models,
  `if (pricing.cache_read_per_million) rows.push({ label: 'Cache', amount: pricing.cache_read_per_million, note: null })`,
  `if (pricing.cache_read_per_million) rows.push({ label: 'Cache read', amount: pricing.cache_read_per_million, note: null })`,
  'Models cache-read billing label'
)

replaceRegex(
  files.models,
  /\nconst primaryPriceRows = \(model: PublicModel\) => pricingRows\(model\)\.slice\(0, 3\)\n/,
  `\n`,
  'Remove truncated pricing helper',
  null
)

replaceRegex(
  files.models,
  /<div class="sp-r8-price-strip">\n\s*<template v-if="model\.credit_pricing">[\s\S]*?<span v-else class="text-xs text-muted">\n\s*Sold through token packages\n\s*<\/span>\n\s*<\/div>/,
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
  'Models complete credit-pricing disclosure',
  'Credit pricing per million tokens'
)

replaceRegex(
  files.models,
  /<div\n\s*v-if="statedSurfaces\(model\)\.length > 0"\n\s*class="flex flex-wrap gap-1\.5"\n\s*>\n\s*<UBadge\n\s*v-for="surface in statedSurfaces\(model\)\.filter\(item => item\.supported\)"[\s\S]*?\n\s*<\/div>/,
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
  'Models protocol truth disclosure',
  'This model states no inference protocol'
)

// ---------------------------------------------------------------------------
// 3) Copy regressions.
// ---------------------------------------------------------------------------
replaceRegex(
  files.adminAliases,
  /:title="`\$\{formatCount\(needingVerification\.length\)\} model\$\{needingVerification\.length === 1 \? '' : 's'\} on sale with no verified SP reference cost`"/,
  `:title="\`\${formatCount(needingVerification.length)} model\${needingVerification.length === 1 ? '' : 's'} on sale with no verified upstream cost (SP reference cost)\`"`,
  'Admin pricing upstream-cost wording',
  'no verified upstream cost (SP reference cost)'
)

replaceRegex(
  files.apiKeys,
  /\{\{ key\.secret_recopy_available \? 'Secure re-copy available' : 'Legacy secret: rotate once to enable secure re-copy' \}\}/,
  `{{ key.secret_recopy_available ? 'Secure re-copy available — securely re-fetch your own encrypted secret when you need to copy it' : 'Legacy secret: rotate once to enable secure re-copy' }}`,
  'API key secure re-copy explanation',
  'securely re-fetch your own encrypted secret'
)

replaceRegex(
  files.entitlements,
  /<UBadge color="warning" variant="subtle">Choose access<\/UBadge>/,
  `<UBadge color="warning" variant="subtle">Not active yet · Choose access</UBadge>`,
  'Pending entitlement activation wording',
  'Not active yet · Choose access'
)

// ---------------------------------------------------------------------------
// 4) Stale test contracts from intentional production behavior.
// ---------------------------------------------------------------------------
replaceRegex(
  files.apiKeyDetailsSpec,
  /expect\(getDetails\)\.toHaveBeenCalledWith\(KEY_ID\)\n\s*expect\(getUsage\)\.toHaveBeenCalledWith\(KEY_ID, \{ bucket: 'day' \}\)\n\s*expect\(getActivity\)\.not\.toHaveBeenCalled\(\)\n\s*expect\(page\.text\(\)\)\.toContain\('Dedicated key balance'\)/,
  `expect(getDetails).toHaveBeenCalledWith(KEY_ID)
    expect(getActivity).not.toHaveBeenCalled()
    await (page.vm as unknown as { loadUsage: () => Promise<void> }).loadUsage()
    expect(getUsage).toHaveBeenCalledWith(KEY_ID, { bucket: 'day' })
    expect(page.text()).toContain('Account + dedicated key balance')`,
  'API-key details lazy usage test contract',
  `await (page.vm as unknown as { loadUsage: () => Promise<void> }).loadUsage()`
)

replaceAllLiteral(
  files.playgroundSpec,
  `expect(page.text()).toContain('Start a conversation')`,
  `expect(page.text()).toContain('What can I help you build?')`,
  'Playground current empty-state headline test'
)

replaceAllLiteral(
  files.playgroundSpec,
  `}), expect.any(Object))`,
  `}), expect.any(Object), expect.anything())`,
  'Playground stream AbortSignal expectations'
)

// ---------------------------------------------------------------------------
// 5) Final guardrails.
// ---------------------------------------------------------------------------
ensureContains(files.playground, 'const browserStorage = (): Storage | null =>', 'safe Playground storage')
ensureContains(files.playground, '{{ protocolLabel }}', 'Playground protocol display')
ensureContains(files.models, 'Credit pricing per million tokens', 'full model pricing')
ensureContains(files.models, 'They are not free.', 'reasoning charge warning')
ensureContains(files.models, 'Chat Completions API', 'chat-completions surface')
ensureContains(files.models, 'This model states no inference protocol', 'missing-protocol warning')
ensureContains(files.apiKeys, 'securely re-fetch your own encrypted secret', 'API-key re-copy guarantee')
ensureContains(files.entitlements, 'Not active yet · Choose access', 'pending-lot wording')
ensureNotContains(files.models, 'primaryPriceRows(model)', 'no truncated pricing rows')

console.log('')
console.log('[PASS] R9.1 source/test contract fixes applied.')
console.log('Next: node .\\VERIFY_FRONTEND_R9_1.mjs')
