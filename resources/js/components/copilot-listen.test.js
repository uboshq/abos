// ⭐ কোপাইলটের মাইক — মানুষ না থামানো পর্যন্ত শোনা (মালিক, ১৩ নভেম্বর ২০২৬: "Claude অ্যাপের মতো — যতক্ষণ মন চায় কথা বলা, শেষ হলে সেন্ড")
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { copilotQuick, joinSaid, MAX_LISTEN_MS } from './copilot.js'

/** ব্রাউজারের কথা-চেনার নকল — প্রতিটা start() একটা নতুন দফা; হাতে ফল আর শেষ পাঠানো যায় */
class FakeRecognizer {
    static made = []

    constructor () {
        this.started = false
        FakeRecognizer.made.push(this)
    }

    start () { this.started = true }
    stop () { this.onend?.() }
    abort () { this.onend?.() }

    say (text, final = true) {
        this.onresult?.({ results: [Object.assign([{ transcript: text }], { isFinal: final })] })
    }
}

function copilot () {
    const c = copilotQuick({ url: '/q', feedbackUrl: '/f', csrf: 't', locale: 'bn', page: 'x', failed: 'f', noKey: 'n', ask: 'a', reply: 'r', micOn: 'm', micOff: 'o' })
    c.$nextTick = (fn) => fn()
    c.$refs = {}
    c.asked = []
    c.ask = (text) => { c.asked.push([text, c.byVoice]) }
    return c
}

describe('কোপাইলটের মাইক', () => {
    beforeEach(() => {
        FakeRecognizer.made = []
        globalThis.window = { SpeechRecognition: FakeRecognizer, speechSynthesis: { cancel () {} } }
        vi.useFakeTimers()
    })
    afterEach(() => {
        vi.useRealTimers()
        delete globalThis.window
    })

    it('আগের কথা আর নতুন অংশ — মাঝে একটা ফাঁক', () => {
        expect(joinSaid('আজ কত', 'বিক্রি')).toBe('আজ কত বিক্রি')
        expect(joinSaid('', ' বিক্রি ')).toBe('বিক্রি')
        expect(joinSaid('আজ', '')).toBe('আজ')
    })

    it('ব্রাউজার থামলেও প্রশ্ন যায় না — আবার শোনে, কথা জমে, "পাঠান"-এ পুরোটা যায় আর উত্তর শোনায়', () => {
        const c = copilot()
        c.listen()
        FakeRecognizer.made[0].say('আজ কত বিক্রি হলো')
        FakeRecognizer.made[0].onend()

        expect(c.asked, '⛔ একটু থামতেই আধা প্রশ্ন চলে গেল').toEqual([])
        expect(FakeRecognizer.made.length, '⛔ থামার পরে আবার শোনা শুরু হয়নি').toBe(2)
        expect(c.listening).toBe(true)

        FakeRecognizer.made[1].say('আর কার কাছে বাকি', false)
        expect(c.heard, '⛔ আগের কথা মুছে নতুনটা — জমছে না').toBe('আজ কত বিক্রি হলো আর কার কাছে বাকি')

        c.sendListening()
        expect(c.asked, '⛔ "পাঠান"-এ পুরো প্রশ্ন যায়নি, বা উত্তর শোনানোর চিহ্ন নেই').toEqual([['আজ কত বিক্রি হলো আর কার কাছে বাকি', true]])
        expect(c.listening).toBe(false)
    })

    it('"বলা শেষ" — লেখা ঘরে, দেখে পাঠালে তখনও উত্তর শোনায়', () => {
        const c = copilot()
        c.listen()
        FakeRecognizer.made[0].say('চাল কত আছে')
        c.stopListening()

        expect(c.asked, '⛔ "বলা শেষ"-এ দেখার আগেই চলে গেল').toEqual([])
        expect(c.q, '⛔ বলা কথা লেখার ঘরে আসেনি').toBe('চাল কত আছে')

        c.submit()
        expect(c.asked, '⛔ মুখে বলা প্রশ্ন ঘর থেকে পাঠালে "বলে জিজ্ঞেস করা" নয়').toEqual([['চাল কত আছে', true]])
    })

    it('ভুলে খোলা মাইক ২ মিনিটে নিজে থামে, বলা কথা ঘরে থাকে; "বাতিল" কিছুই রাখে না', () => {
        const c = copilot()
        c.listen()
        FakeRecognizer.made[0].say('বকেয়া')
        vi.advanceTimersByTime(MAX_LISTEN_MS + 10)

        expect(c.listening, '⛔ ভুলে খোলা মাইক থামল না').toBe(false)
        expect(c.q).toBe('বকেয়া')
        expect(c.asked).toEqual([])

        const d = copilot()
        d.q = 'আগের লেখা'
        d.listen()
        FakeRecognizer.made[FakeRecognizer.made.length - 1].say('ভুল কথা')
        d.cancelListening()
        expect(d.q, '⛔ "বাতিল"-এর পরেও বলা কথা ঘরে বসল').toBe('আগের লেখা')
    })
})
