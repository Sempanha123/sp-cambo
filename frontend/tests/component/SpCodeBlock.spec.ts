// @vitest-environment nuxt
import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import SpCodeBlock from '~/components/SpCodeBlock.vue'

const SHELL_COMMAND = 'curl https://api.example.test/v1/messages --header "x-test: <literal>"'

const mount = (props: Partial<InstanceType<typeof SpCodeBlock>['$props']> = {}) =>
  mountSuspended(SpCodeBlock, { props: { code: SHELL_COMMAND, ...props } })

describe('SpCodeBlock', () => {
  it('keeps supplied code literal rather than treating it as markup', async () => {
    const wrapper = await mount({ code: 'echo "<strong>literal</strong>"' })

    expect(wrapper.find('pre > code').text()).toBe('echo "<strong>literal</strong>"')
    expect(wrapper.find('strong').exists()).toBe(false)
  })

  it('passes the exact code to its copy control', async () => {
    const wrapper = await mount()

    expect(wrapper.findComponent({ name: 'SpCopyButton' }).props('value')).toBe(SHELL_COMMAND)
    expect(wrapper.find('.sp-code-block__copy').exists()).toBe(true)
  })

  it('prefers a filename over its language label', async () => {
    const wrapper = await mount({ filename: '~/.config/sp-cambo/config.toml', language: 'toml' })

    expect(wrapper.find('.sp-code-block__header').text()).toContain('~/.config/sp-cambo/config.toml')
    expect(wrapper.find('.sp-code-block__header').text()).not.toBe('toml')
  })

  it('omits an empty non-copyable header without hiding code', async () => {
    const wrapper = await mount({ copyable: false })

    expect(wrapper.find('.sp-code-block__header').exists()).toBe(false)
    expect(wrapper.find('pre > code').text()).toBe(SHELL_COMMAND)
  })
})
