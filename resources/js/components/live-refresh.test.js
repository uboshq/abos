// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { liveRefresh } from './screens.js'

/*
 * ⭐ ডেলিভারি ট্র্যাকিং প্রতি ৩০ সেকেন্ডে নিজে নতুন (মালিক, ২ অক্টোবর ২০২৬) — কেবল [data-live] ঘরটা।
 */
const page = (inner) => `<html><body><main><div data-live>${inner}</div></main></body></html>`

describe('liveRefresh', () => {
    let el
    beforeEach(() => {
        document.body.innerHTML = '<div data-live><span>পুরনো</span><input name="q"></div>'
        el = document.querySelector('[data-live]')
        globalThis.fetch = vi.fn(async () => ({ ok: true, text: async () => page('<span>নতুন</span>') }))
    })
    afterEach(() => vi.restoreAllMocks())

    const make = (url = '/sales/tracking') => Object.assign(liveRefresh({ url, seconds: 30 }), { $el: el })

    it('swaps in the fresh part of the same address', async () => {
        await make().pull()
        expect(globalThis.fetch).toHaveBeenCalledWith('/sales/tracking', expect.anything())
        expect(el.textContent).toContain('নতুন')
    })

    it('leaves the box alone while someone is typing in it', async () => {
        el.querySelector('input').focus()
        await make().pull()
        expect(globalThis.fetch).not.toHaveBeenCalled()
        expect(el.textContent).toContain('পুরনো')
    })

    it('keeps the old view when the server fails', async () => {
        globalThis.fetch = vi.fn(async () => ({ ok: false, text: async () => '' }))
        await make().pull()
        expect(el.textContent).toContain('পুরনো')
    })

    it('polls every thirty seconds', () => {
        vi.useFakeTimers()
        const c = make()
        const spy = vi.spyOn(c, 'pull').mockResolvedValue()
        c.init()
        vi.advanceTimersByTime(30_000)
        expect(spy).toHaveBeenCalledTimes(1)
        c.destroy()
        vi.useRealTimers()
    })
})
