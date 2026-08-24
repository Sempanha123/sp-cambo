// @vitest-environment nuxt
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import SpKhqrCode from '~/components/SpKhqrCode.vue'

/**
 * A KHQR is money. The component's entire contract is that it draws *exactly*
 * what the backend issued and nothing else: it never derives an amount, a
 * merchant or a payload, it prefers a server-rendered image over its own
 * drawing, and when it cannot draw at all it must still leave the customer a
 * payable string rather than an empty plate.
 *
 * The encoder is stubbed. `qrcode` draws through a real canvas, which happy-dom
 * does not implement, and the pixels are not what is under test here — what the
 * encoder is *handed*, and what happens when it fails, are.
 */
const { toDataURL } = vi.hoisted(() => ({ toDataURL: vi.fn() }))

vi.mock('qrcode', () => ({ default: { toDataURL } }))

/** Never a real payload; the shape only has to be recognisable in assertions. */
const PAYLOAD = '00020101021229180014test-merchant5204598953031165802KH6304ABCD'
const SERVER_IMAGE = 'data:image/png;base64,server-rendered'

enableAutoUnmount(afterEach)

beforeEach(() => {
  toDataURL.mockReset()
  toDataURL.mockImplementation(async (payload: string) => `data:image/png;base64,drawn(${payload})`)
})

const mount = (props: Partial<InstanceType<typeof SpKhqrCode>['$props']> = {}) =>
  mountSuspended(SpKhqrCode, { props: { payload: PAYLOAD, ...props } })

const image = (wrapper: Awaited<ReturnType<typeof mount>>) => wrapper.find('img')

describe('SpKhqrCode rendering source', () => {
  it('uses the server-rendered image as-is and does not redraw it', async () => {
    const wrapper = await mount({ imageUrl: SERVER_IMAGE })

    expect(image(wrapper).attributes('src')).toBe(SERVER_IMAGE)
    expect(toDataURL).not.toHaveBeenCalled()
  })

  it('draws the payload itself when the backend sent no image', async () => {
    const wrapper = await mount()

    expect(toDataURL).toHaveBeenCalledOnce()
    expect(image(wrapper).attributes('src')).toContain('drawn(')
  })

  it('hands the encoder the payload byte-for-byte, deriving nothing', async () => {
    await mount()

    expect(toDataURL.mock.calls[0]?.[0]).toBe(PAYLOAD)
  })

  it('labels the code for screen readers', async () => {
    const wrapper = await mount()

    expect(image(wrapper).attributes('alt')).toBe('Bakong KHQR payment code')
  })
})

describe('SpKhqrCode undrawable code', () => {
  it('never shows a bare plate when there is no payload to draw', async () => {
    const wrapper = await mount({ payload: '' })

    expect(image(wrapper).exists()).toBe(false)
    expect(toDataURL).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('could not be drawn')
  })

  it('falls back to the payment string when the encoder fails', async () => {
    toDataURL.mockRejectedValue(new Error('no canvas'))

    const wrapper = await mount()

    expect(image(wrapper).exists()).toBe(false)
    expect(wrapper.text()).toContain('Copy the payment string below into your banking app')
  })

  it('keeps the payload copyable even when the drawing failed, so the order stays payable', async () => {
    toDataURL.mockRejectedValue(new Error('no canvas'))

    const wrapper = await mount()

    expect(wrapper.text()).toContain(PAYLOAD)
    expect(wrapper.findComponent({ name: 'SpCopyButton' }).props('value')).toBe(PAYLOAD)
  })
})

describe('SpKhqrCode reissued attempts', () => {
  it('redraws when a replacement attempt arrives and drops the expired code', async () => {
    const wrapper = await mount()
    const expired = image(wrapper).attributes('src')

    await wrapper.setProps({ payload: `${PAYLOAD}-reissued` })
    await flushPromises()

    expect(toDataURL).toHaveBeenCalledTimes(2)
    expect(toDataURL.mock.calls[1]?.[0]).toBe(`${PAYLOAD}-reissued`)
    expect(image(wrapper).attributes('src')).not.toBe(expired)
  })

  it('discards its own drawing the moment the backend supplies an image', async () => {
    const wrapper = await mount()

    await wrapper.setProps({ imageUrl: SERVER_IMAGE })
    await flushPromises()

    expect(image(wrapper).attributes('src')).toBe(SERVER_IMAGE)
  })

  it('shows the payload string alongside the code at all times', async () => {
    const wrapper = await mount({ imageUrl: SERVER_IMAGE })

    expect(wrapper.text()).toContain(PAYLOAD)
  })
})
