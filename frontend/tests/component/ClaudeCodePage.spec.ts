// @vitest-environment nuxt
import { afterEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ClaudeCodePage from '~/pages/docs/claude-code.vue'

/**
 * The initial tab must contain an immediately usable command. Leaving every
 * panel inactive makes the most important setup step appear blank until a
 * reader discovers the tab control.
 */
enableAutoUnmount(afterEach)

const render = () => mountSuspended(ClaudeCodePage)

describe('Claude Code setup', () => {
  it('renders the Bash configuration by default', async () => {
    const page = await render()
    const bash = page.get('[role="tab"][id$="trigger-bash"]')

    expect(bash.attributes('aria-selected')).toBe('true')
    expect(page.find('.sp-code-block').exists()).toBe(true)
    expect(page.find('pre > code').text()).toContain('export ANTHROPIC_BASE_URL=')
    expect(page.find('pre > code').text()).toContain('claude')
  })
})
