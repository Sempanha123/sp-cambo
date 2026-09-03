export type ModelBrandKey = 'anthropic' | 'openai' | 'gemini' | 'deepseek' | 'generic'

export interface ModelPresentation {
  brand: ModelBrandKey
  provider: string
  icon: string
  label: string
  shortLabel: string
  surfaceClass: string
  iconClass: string
  ringClass: string
}

const normalize = (value?: string | null) => (value ?? '').trim().toLowerCase()

export const modelBrand = (value?: string | null): ModelBrandKey => {
  const haystack = normalize(value)

  if (/claude|anthropic|opus|sonnet|haiku|agentr(outer)?/.test(haystack)) return 'anthropic'
  if (/gemini|google ai|germini/.test(haystack)) return 'gemini'
  if (/deepseek|deepsek/.test(haystack)) return 'deepseek'
  if (/openai|codex|gpt|sol/.test(haystack)) return 'openai'
  return 'generic'
}

const words = (alias: string) => alias
  .replace(/[._/]+/g, '-')
  .split('-')
  .filter(Boolean)

const titleCase = (value: string) => value.replace(/\b\w/g, letter => letter.toUpperCase())

export const friendlyModelName = (alias?: string | null, fallback?: string | null): string => {
  if (fallback?.trim()) return fallback.trim()

  const raw = (alias ?? '').trim()
  const lower = raw.toLowerCase()
  if (!raw) return 'AI model'

  const known: Record<string, string> = {
    'opus-5': 'Claude Opus 5',
    'sonnet-5': 'Claude Sonnet 5',
    'haiku-4.5': 'Claude Haiku 4.5',
    '5.6-sol': 'GPT-5.6 Sol',
    '4.8-sol': 'GPT-4.8 Sol',
    'openai-codex': 'OpenAI Codex',
    'gemini-3.6-flash': 'Gemini 3.6 Flash',
    'gemini-3.6-pro': 'Gemini 3.6 Pro',
    'gemini-google-ai-studio': 'Gemini Google AI Studio',
    'deepseek-v4-flash': 'DeepSeek V4 Flash',
    'deepseek-v4-pro': 'DeepSeek V4 Pro',
    'deepseek': 'DeepSeek'
  }

  if (known[lower]) return known[lower]

  const brand = modelBrand(lower)
  const parts = words(raw).filter(part => !['openai', 'google', 'ai', 'studio'].includes(part.toLowerCase()))
  const readable = titleCase(parts.join(' '))

  if (brand === 'anthropic' && !/^claude\b/i.test(readable)) return `Claude ${readable}`.trim()
  if (brand === 'gemini' && !/^gemini\b/i.test(readable)) return `Gemini ${readable}`.trim()
  if (brand === 'openai' && !/^(gpt|openai)\b/i.test(readable)) return readable || 'OpenAI model'
  if (brand === 'deepseek' && !/^deepseek\b/i.test(readable)) return `DeepSeek ${readable}`.trim()

  return readable || raw
}

export const modelPresentation = (input?: string | null, fallback?: string | null): ModelPresentation => {
  const brand = modelBrand(`${input ?? ''} ${fallback ?? ''}`)
  const label = friendlyModelName(input, fallback)

  if (brand === 'anthropic') {
    return {
      brand,
      provider: 'Claude',
      icon: 'i-simple-icons-anthropic',
      label,
      shortLabel: label.replace(/^Claude\s+/i, ''),
      surfaceClass: 'bg-orange-500/10',
      iconClass: 'text-orange-400',
      ringClass: 'border-orange-400/20'
    }
  }

  if (brand === 'openai') {
    return {
      brand,
      provider: 'OpenAI',
      icon: 'i-simple-icons-openai',
      label,
      shortLabel: label.replace(/^OpenAI\s+/i, ''),
      surfaceClass: 'bg-emerald-500/10',
      iconClass: 'text-emerald-400',
      ringClass: 'border-emerald-400/20'
    }
  }

  if (brand === 'gemini') {
    return {
      brand,
      provider: 'Gemini',
      icon: 'i-simple-icons-googlegemini',
      label,
      shortLabel: label.replace(/^Gemini\s+/i, ''),
      surfaceClass: 'bg-violet-500/10',
      iconClass: 'text-violet-400',
      ringClass: 'border-violet-400/20'
    }
  }

  if (brand === 'deepseek') {
    return {
      brand,
      provider: 'DeepSeek',
      icon: 'i-simple-icons-deepseek',
      label,
      shortLabel: label.replace(/^DeepSeek\s+/i, ''),
      surfaceClass: 'bg-sky-500/10',
      iconClass: 'text-sky-400',
      ringClass: 'border-sky-400/20'
    }
  }

  return {
    brand,
    provider: 'AI',
    icon: 'i-lucide-sparkles',
    label,
    shortLabel: label,
    surfaceClass: 'bg-primary/10',
    iconClass: 'text-primary',
    ringClass: 'border-primary/20'
  }
}
