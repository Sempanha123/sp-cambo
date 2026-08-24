/**
 * Documentation table of contents.
 *
 * Ordered as a reading path, so the shell can derive previous/next links and the
 * sidebar without each page repeating the structure.
 */
export interface DocsPage {
  label: string
  to: string
  description: string
}

export interface DocsSection {
  label: string
  pages: DocsPage[]
}

const sections: DocsSection[] = [
  {
    label: 'Getting started',
    pages: [
      {
        label: 'Quick start',
        to: '/docs/quick-start',
        description: 'From a new account to a working request.'
      },
      {
        label: 'Base URLs',
        to: '/docs/base-urls',
        description: 'Which URL to use for the control plane and for inference.'
      },
      {
        label: 'Authentication',
        to: '/docs/authentication',
        description: 'API keys, scoping, rotation and what to do if one leaks.'
      }
    ]
  },
  {
    label: 'CLI tools',
    pages: [
      {
        label: 'Claude Code',
        to: '/docs/claude-code',
        description: 'Environment variables and model aliases for Claude Code.'
      },
      {
        label: 'Codex CLI',
        to: '/docs/codex-cli',
        description: 'Custom provider configuration for Codex CLI.'
      }
    ]
  },
  {
    label: 'API',
    pages: [
      {
        label: 'API reference',
        to: '/docs/api-reference',
        description: 'Endpoints, request shapes and response envelopes.'
      },
      {
        label: 'Streaming',
        to: '/docs/streaming',
        description: 'Server-sent events, and how usage is settled from a stream.'
      },
      {
        label: 'Errors',
        to: '/docs/errors',
        description: 'Stable machine codes and how to react to each one.'
      },
      {
        label: 'Rate limits',
        to: '/docs/rate-limits',
        description: 'Per-key and per-package limits, and how to back off.'
      }
    ]
  },
  {
    label: 'Resellers',
    pages: [
      {
        label: 'Reseller API',
        to: '/docs/reseller-api',
        description: 'Management keys, scopes, managed customers and quota allocation.'
      }
    ]
  },
  {
    label: 'Billing',
    pages: [
      {
        label: 'Billing model',
        to: '/docs/billing',
        description: 'Token quota, credit balance, reservations and settlement.'
      }
    ]
  }
]

export function useDocsNavigation() {
  const route = useRoute()

  const flat = computed(() => sections.flatMap(section => section.pages))

  const currentIndex = computed(() => flat.value.findIndex(page => page.to === route.path))

  const previous = computed(() => currentIndex.value > 0 ? flat.value[currentIndex.value - 1] : null)
  const next = computed(() => {
    const index = currentIndex.value

    return index >= 0 && index < flat.value.length - 1 ? flat.value[index + 1] : null
  })

  const sidebar = computed(() => sections.map(section => ({
    label: section.label,
    pages: section.pages.map(page => ({
      ...page,
      active: route.path === page.to
    }))
  })))

  return {
    sections,
    sidebar,
    flat,
    previous,
    next
  }
}
