// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { bootMeeting, clock, pickTiles, shareOptions, TILES } from './meeting-page.js'
import { showMeeting } from './messenger-meeting.js'

const p = (id, more = {}) => ({ id: String(id), name: 'P' + id, ...more })

describe('meeting page', () => {
    it('draws at most six tiles, always me, and whoever speaks or raised a hand, in the old order', () => {
        const all = [p(1), p(2), p(3), p(4), p(5), p(6), p(7, { speaking: true }), p(8, { hand: true }), p(9, { me: true })]
        const ids = pickTiles(all).map((x) => x.id)
        expect(ids).toHaveLength(TILES)
        expect(ids).toEqual(['1', '2', '3', '7', '8', '9'])
    })

    it('counts in English digits past an hour', () => {
        expect(clock(756)).toBe('00:12:36')
        expect(clock(3661)).toBe('01:01:01')
    })

    it('shares slides with sound, light on the line', () => {
        const [capture, publish] = shareOptions()
        expect(capture.audio).toBe(true)
        expect(capture.systemAudio).toBe('include')
        expect(capture.contentHint).toBe('detail')
        expect(publish.screenShareEncoding.maxBitrate).toBeLessThanOrEqual(800000)
    })

    it('says the server\'s own words when the eleventh is refused, and offers to try again', async () => {
        document.body.innerHTML = '<div data-meeting data-me="1" data-csrf="x" data-urls=\'{"join":"/j","state":"/s"}\' data-words="{}">'
            + '<div data-mm-door><p data-mm-say></p><button data-mm-act="join"></button></div><div data-mm-stage></div><p data-mm-toast hidden></p></div>'
        globalThis.fetch = async () => ({ ok: false, status: 409, json: async () => ({ error: 'meeting_full', message: 'full of 10' }) })
        const m = bootMeeting(document.querySelector('[data-meeting]'))
        await m.join()
        expect(document.querySelector('[data-mm-say]').textContent).toBe('full of 10')
        expect(document.querySelector('[data-mm-act="join"]').hidden).toBe(false)
        expect(document.querySelector('[data-mm-stage]').hidden).toBe(true)
    })

    it('shows and hides the invitation, and never writes markup from a title', () => {
        document.body.innerHTML = '<div id="b" hidden><span data-msgr-meet-avatar></span><b data-msgr-meet-name></b><small data-msgr-meet-title></small></div>'
        const box = document.getElementById('b')
        showMeeting(box, { id: 'a', title: '<img src=x>', peer: { id: '3', name: 'Karim' } })
        expect(box.hidden).toBe(false)
        expect(box.querySelector('img')).toBe(null)
        showMeeting(box, null)
        expect(box.hidden).toBe(true)
    })
})
