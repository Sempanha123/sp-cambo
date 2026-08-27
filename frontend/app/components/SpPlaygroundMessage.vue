<script setup lang="ts">
type InlineSegment = { kind: 'text' | 'strong' | 'code' | 'em'; text: string }
type TextBlock = { kind: 'paragraph' | 'heading' | 'quote'; level?: number; lines: string[] }
type CodeBlock = { kind: 'code'; language: string; code: string; id: string }
type ListBlock = { kind: 'list'; ordered: boolean; items: string[] }
type TableBlock = { kind: 'table'; headers: string[]; rows: string[][] }
type RuleBlock = { kind: 'rule' }
type Block = TextBlock | CodeBlock | ListBlock | TableBlock | RuleBlock

const props = defineProps<{
  role: 'user' | 'assistant'
  content: string
  streaming?: boolean
}>()

const toast = useToast()
const collapsed = ref<Record<string, boolean>>({})

const parseInline = (value: string): InlineSegment[] => {
  const result: InlineSegment[] = []
  const pattern = /(\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*)/g
  let cursor = 0
  for (const match of value.matchAll(pattern)) {
    const index = match.index ?? 0
    if (index > cursor) result.push({ kind: 'text', text: value.slice(cursor, index) })
    const token = match[0]
    if (token.startsWith('**')) result.push({ kind: 'strong', text: token.slice(2, -2) })
    else if (token.startsWith('`')) result.push({ kind: 'code', text: token.slice(1, -1) })
    else result.push({ kind: 'em', text: token.slice(1, -1) })
    cursor = index + token.length
  }
  if (cursor < value.length) result.push({ kind: 'text', text: value.slice(cursor) })
  return result.length ? result : [{ kind: 'text', text: value }]
}

const splitTableRow = (line: string) => line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(cell => cell.trim())
const isTableSeparator = (line: string) => /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line)

const blocks = computed<Block[]>(() => {
  const source = props.content.replace(/\r\n/g, '\n')
  const lines = source.split('\n')
  const out: Block[] = []
  let i = 0
  let codeIndex = 0

  while (i < lines.length) {
    const line = lines[i] ?? ''
    if (line.trim().startsWith('```')) {
      const language = line.trim().slice(3).trim() || 'code'
      const code: string[] = []
      i++
      while (i < lines.length && !(lines[i] ?? '').trim().startsWith('```')) {
        code.push(lines[i] ?? '')
        i++
      }
      if (i < lines.length) i++
      out.push({ kind: 'code', language, code: code.join('\n'), id: `code-${codeIndex++}` })
      continue
    }
    if (!line.trim()) { i++; continue }
    if (i + 1 < lines.length && line.includes('|') && isTableSeparator(lines[i + 1] ?? '')) {
      const headers = splitTableRow(line)
      const rows: string[][] = []
      i += 2
      while (i < lines.length && (lines[i] ?? '').includes('|') && (lines[i] ?? '').trim()) {
        rows.push(splitTableRow(lines[i] ?? ''))
        i++
      }
      out.push({ kind: 'table', headers, rows })
      continue
    }
    const heading = line.match(/^\s*(#{1,6})\s+(.+)$/)
    if (heading) {
      out.push({ kind: 'heading', level: heading[1]?.length ?? 2, lines: [heading[2] ?? ''] })
      i++
      continue
    }
    if (/^\s*([-*_])(?:\s*\1){2,}\s*$/.test(line)) {
      out.push({ kind: 'rule' })
      i++
      continue
    }
    if (/^\s*>\s?/.test(line)) {
      const quote: string[] = []
      while (i < lines.length && /^\s*>\s?/.test(lines[i] ?? '')) {
        quote.push((lines[i] ?? '').replace(/^\s*>\s?/, ''))
        i++
      }
      out.push({ kind: 'quote', lines: quote })
      continue
    }
    if (/^\s*[-*+]\s+/.test(line) || /^\s*\d+[.)]\s+/.test(line)) {
      const ordered = /^\s*\d+[.)]\s+/.test(line)
      const items: string[] = []
      const matcher = ordered ? /^\s*\d+[.)]\s+(.+)$/ : /^\s*[-*+]\s+(.+)$/
      while (i < lines.length) {
        const item = (lines[i] ?? '').match(matcher)
        if (!item) break
        items.push(item[1] ?? '')
        i++
      }
      out.push({ kind: 'list', ordered, items })
      continue
    }
    const paragraph: string[] = [line]
    i++
    while (i < lines.length) {
      const next = lines[i] ?? ''
      if (!next.trim() || next.trim().startsWith('```') || /^\s*(#{1,6})\s+/.test(next) || /^\s*>\s?/.test(next) || /^\s*[-*+]\s+/.test(next) || /^\s*\d+[.)]\s+/.test(next)) break
      if (i + 1 < lines.length && next.includes('|') && isTableSeparator(lines[i + 1] ?? '')) break
      paragraph.push(next)
      i++
    }
    out.push({ kind: 'paragraph', lines: paragraph })
  }
  return out
})

const copy = async (text: string, label: string) => {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      const textarea = document.createElement('textarea')
      textarea.value = text
      textarea.style.position = 'fixed'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.select()
      const copied = document.execCommand('copy')
      textarea.remove()
      if (!copied) throw new Error('copy_failed')
    }
    toast.add({ title: `${label} copied`, color: 'success' })
  } catch {
    toast.add({ title: 'Copy failed', description: 'Select the text and copy it manually.', color: 'error' })
  }
}
</script>

<template>
  <div v-if="role === 'user'" class="group flex justify-end">
    <div class="max-w-[min(82%,46rem)] rounded-[1.4rem] rounded-br-md bg-primary px-4 py-3 text-sm leading-6 text-inverted shadow-sm">
      <p class="whitespace-pre-wrap break-words">{{ content }}</p>
    </div>
  </div>

  <article v-else class="group/message relative mx-auto w-full max-w-4xl text-[0.94rem] leading-7 text-default">
    <div class="mb-2 flex items-center gap-2 text-xs text-muted">
      <span class="flex size-7 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-primary">
        <UIcon name="i-lucide-sparkles" class="size-3.5" />
      </span>
      <strong class="font-medium text-highlighted">SP Cambo</strong>
      <span v-if="streaming" class="flex items-center gap-1.5 text-primary"><span class="size-1.5 animate-pulse rounded-full bg-current" />Writing</span>
    </div>

    <div class="space-y-4 pl-0 md:pl-9">
      <template v-for="(block, blockIndex) in blocks" :key="blockIndex">
        <div v-if="block.kind === 'code'" class="overflow-hidden rounded-xl border border-default bg-[#080d16] shadow-sm">
          <div class="flex min-h-10 items-center justify-between gap-3 border-b border-white/8 bg-white/[0.035] px-3 py-2">
            <div class="flex min-w-0 items-center gap-2">
              <UIcon name="i-lucide-code-2" class="size-3.5 shrink-0 text-primary" />
              <span class="truncate font-mono text-[11px] uppercase tracking-wide text-muted">{{ block.language }}</span>
            </div>
            <div class="flex items-center gap-1">
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                :icon="collapsed[block.id] ? 'i-lucide-chevrons-down' : 'i-lucide-chevrons-up'"
                :aria-label="collapsed[block.id] ? 'Expand code' : 'Collapse code'"
                @click="collapsed[block.id] = !collapsed[block.id]"
              />
              <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-copy" aria-label="Copy code" @click="copy(block.code, 'Code')"><span class="hidden sm:inline">Copy</span></UButton>
            </div>
          </div>
          <pre v-if="!collapsed[block.id]" class="max-h-[30rem] overflow-auto p-4 text-[13px] leading-6 text-slate-200"><code>{{ block.code }}</code></pre>
          <div v-else class="px-4 py-3 text-xs text-muted">Code collapsed · {{ block.code.split('\n').length }} lines</div>
        </div>

        <component
          :is="`h${Math.min(4, Math.max(2, block.level ?? 2))}`"
          v-else-if="block.kind === 'heading'"
          class="font-semibold tracking-tight text-highlighted"
          :class="(block.level ?? 2) <= 2 ? 'pt-2 text-xl' : 'pt-1 text-base'"
        >
          <template v-for="(segment, segmentIndex) in parseInline(block.lines[0] ?? '')" :key="segmentIndex">
            <strong v-if="segment.kind === 'strong'">{{ segment.text }}</strong>
            <code v-else-if="segment.kind === 'code'" class="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.88em] text-primary">{{ segment.text }}</code>
            <em v-else-if="segment.kind === 'em'">{{ segment.text }}</em>
            <span v-else>{{ segment.text }}</span>
          </template>
        </component>

        <blockquote v-else-if="block.kind === 'quote'" class="border-l-2 border-primary/45 pl-4 text-muted">
          <p v-for="(line, lineIndex) in block.lines" :key="lineIndex">{{ line }}</p>
        </blockquote>

        <ol v-else-if="block.kind === 'list' && block.ordered" class="space-y-1.5 pl-6 marker:font-semibold marker:text-primary">
          <li v-for="(item, itemIndex) in block.items" :key="itemIndex" class="pl-1">
            <template v-for="(segment, segmentIndex) in parseInline(item)" :key="segmentIndex">
              <strong v-if="segment.kind === 'strong'">{{ segment.text }}</strong>
              <code v-else-if="segment.kind === 'code'" class="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.88em] text-primary">{{ segment.text }}</code>
              <em v-else-if="segment.kind === 'em'">{{ segment.text }}</em>
              <span v-else>{{ segment.text }}</span>
            </template>
          </li>
        </ol>

        <ul v-else-if="block.kind === 'list'" class="space-y-1.5 pl-5">
          <li v-for="(item, itemIndex) in block.items" :key="itemIndex" class="relative pl-3 before:absolute before:left-0 before:top-[0.8em] before:size-1 before:rounded-full before:bg-primary">
            <template v-for="(segment, segmentIndex) in parseInline(item)" :key="segmentIndex">
              <strong v-if="segment.kind === 'strong'">{{ segment.text }}</strong>
              <code v-else-if="segment.kind === 'code'" class="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.88em] text-primary">{{ segment.text }}</code>
              <em v-else-if="segment.kind === 'em'">{{ segment.text }}</em>
              <span v-else>{{ segment.text }}</span>
            </template>
          </li>
        </ul>

        <div v-else-if="block.kind === 'table'" class="overflow-x-auto rounded-xl border border-default">
          <table class="min-w-full text-left text-sm">
            <thead class="bg-elevated/70 text-xs uppercase tracking-wide text-muted">
              <tr><th v-for="(cell, cellIndex) in block.headers" :key="cellIndex" class="whitespace-nowrap px-3 py-2.5">{{ cell }}</th></tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr v-for="(row, rowIndex) in block.rows" :key="rowIndex" class="bg-default/10">
                <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-3 py-2.5 align-top">{{ cell }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <hr v-else-if="block.kind === 'rule'" class="border-default" />

        <div v-else-if="block.kind === 'paragraph'" class="space-y-1">
          <p v-for="(line, lineIndex) in block.lines" :key="lineIndex" class="break-words">
            <template v-for="(segment, segmentIndex) in parseInline(line)" :key="segmentIndex">
              <strong v-if="segment.kind === 'strong'" class="font-semibold text-highlighted">{{ segment.text }}</strong>
              <code v-else-if="segment.kind === 'code'" class="rounded-md border border-default bg-muted px-1.5 py-0.5 font-mono text-[0.88em] text-primary">{{ segment.text }}</code>
              <em v-else-if="segment.kind === 'em'">{{ segment.text }}</em>
              <span v-else>{{ segment.text }}</span>
            </template>
          </p>
        </div>
      </template>

      <span v-if="streaming" class="inline-block h-5 w-1 animate-pulse rounded-full bg-primary align-middle" aria-hidden="true" />
    </div>

    <div v-if="content && !streaming" class="mt-3 flex items-center gap-1 pl-0 opacity-100 transition-opacity sm:opacity-0 sm:group-hover/message:opacity-100 md:pl-9 focus-within:opacity-100">
      <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-copy" label="Copy response" @click="copy(content, 'Response')" />
    </div>
  </article>
</template>
