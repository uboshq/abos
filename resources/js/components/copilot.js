/*
 * ⭐ কোপাইলটের দ্রুত জিজ্ঞাসা — উপরের বারের মগজ (মালিক, ১২ নভেম্বর ২০২৬: Siri আর Gemini-র মতো; নমুনা 3uJhK92DAMhwyELt63mSmr)।
 *
 * ⓘ চাপলে বা Ctrl+J দিলে পুরো পর্দা জুড়ে কেবল জিজ্ঞাসা — পাতা বদলায় না; Esc বা × দিলে সেই পাতাতেই। প্রশ্ন আর উত্তর চ্যাটের মতো।
 * ⓘ কথা: ব্রাউজারের নিজের কথা-চেনা (Chrome বাংলা বোঝে) আর পড়ে-শোনানো; না থাকলে মাইক ধূসর, লেখা চলে। ⛔ অডিও কোথাও জমা হয় না —
 *   সার্ভারে যায় কেবল লেখা প্রশ্নটা, আর সেটাই খাতায় (মালিকের সিদ্ধান্ত: এখন ব্রাউজার, পরে NEXUS-এর Whisper)।
 * ⓘ সংখ্যা সার্ভারের — মডেল কেবল প্রশ্ন বোঝে; চাবি না থাকলে উত্তর নেই ([[CopilotApiController::ask()]])।
 */

const recognizer = () => (typeof window === 'undefined' ? null : (window.SpeechRecognition || window.webkitSpeechRecognition || null))

/** উত্তরের সারিগুলো জোড়ায় — "কী · কত" (বিক্রির সারাংশ), নাহলে প্রথম ঘর আর বাকিগুলো একসাথে (বকেয়ার তালিকা) */
export function pairsOf (answer) {
    const rows = Array.isArray(answer?.rows) ? answer.rows : []

    return rows.slice(0, 8).map((row) => {
        if (row && typeof row === 'object' && 'what' in row) return [String(row.what), String(row.value ?? '')]
        const values = Object.values(row ?? {}).map((v) => String(v ?? ''))

        return [values[0] ?? '', values.slice(1).join(' · ')]
    })
}

/** যা পড়ে শোনানো হয় — সারাংশ, আর প্রথম তিন সারি */
export function sayOf (answer) {
    const head = String(answer?.summary ?? '')
    const rows = pairsOf(answer).slice(0, 3).map(([k, v]) => `${k} ${v}`)

    return [head, ...rows].filter((s) => s.trim() !== '').join('। ')
}

export function copilotQuick ({ url, feedbackUrl, csrf, locale, page, failed, noKey, ask, reply, micOn, micOff }) {
    return {
        open: false,
        listening: false,
        busy: false,
        q: '',
        heard: '',
        thread: [],
        speaking: null,
        byVoice: false,
        rec: null,

        init () {
            window.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 'j') {
                    e.preventDefault()
                    this.toggle()
                } else if (e.key === 'Escape' && this.open) {
                    this.close()
                }
            }, true)
            window.addEventListener('copilot-quick-open', () => this.show())
        },

        canListen () { return recognizer() !== null },
        canSpeak () { return typeof window !== 'undefined' && 'speechSynthesis' in window },
        glow () { return this.listening || this.speaking !== null ? '1' : '0' },
        // ⓘ মগজের অবস্থা — শুনলে দ্রুত, উত্তর খুঁজলে ঝিকিমিকি (মালিকের "ঘ", ১২ নভেম্বর ২০২৬)
        markState () { return this.listening ? 'cq-fast' : (this.busy ? 'cq-think' : '') },
        idle () { return ! this.listening && this.thread.length === 0 },
        chatting () { return ! this.listening && this.thread.length > 0 },
        boxHint () { return this.thread.length > 0 ? reply : ask },
        micTitle () { return this.canListen() ? micOn : micOff },

        show () {
            this.open = true
            this.$nextTick(() => this.$refs.box?.focus())
        },
        close () {
            this.stopListening()
            this.stopSpeaking()
            this.open = false
        },
        toggle () { return this.open ? this.close() : this.show() },

        listen () {
            const Rec = recognizer()
            if (Rec === null || this.busy) return
            this.stopSpeaking()
            const rec = new Rec()
            rec.lang = locale === 'bn' ? 'bn-BD' : 'en-US'
            rec.interimResults = true
            rec.continuous = false
            rec.onresult = (e) => {
                let text = ''
                for (let i = 0; i < e.results.length; i++) text += e.results[i][0].transcript
                this.heard = text
            }
            rec.onerror = () => { this.listening = false }
            rec.onend = () => {
                this.listening = false
                const said = this.heard.trim()
                this.heard = ''
                if (said !== '') {
                    this.byVoice = true
                    this.ask(said)
                }
            }
            this.rec = rec
            this.heard = ''
            this.listening = true
            rec.start()
        },
        stopListening () {
            if (this.rec !== null) this.rec.stop()
        },
        cancelListening () {
            this.heard = ''
            if (this.rec !== null) this.rec.abort()
            this.listening = false
        },

        pick (text) {
            this.byVoice = false
            this.ask(text)
        },
        submit () {
            this.byVoice = false
            this.ask(this.q)
        },

        async ask (text) {
            const question = String(text ?? '').trim()
            if (question === '' || this.busy) return
            this.q = ''
            this.thread.push({ id: `me-${Date.now()}`, me: true, text: question })
            this.busy = true
            this.scroll()
            let item
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ q: question, page }),
                })
                const d = r.status === 403 ? { state: 'blocked', message: noKey } : await r.json()
                item = {
                    id: `bot-${Date.now()}`,
                    me: false,
                    head: d.answer?.summary || d.message || failed,
                    pairs: pairsOf(d.answer),
                    source: [d.understood_by, d.check].filter(Boolean).join(' · '),
                    answerId: d.answer_id ?? null,
                    voted: 0,
                    say: d.answer ? sayOf(d.answer) : String(d.message ?? ''),
                }
            } catch (e) {
                item = { id: `bot-${Date.now()}`, me: false, head: failed, pairs: [], source: '', answerId: null, voted: 0, say: failed }
            }
            this.thread.push(item)
            this.busy = false
            this.scroll()
            if (this.byVoice) this.speak(item)
            this.$nextTick(() => this.$refs.box?.focus())
        },

        speak (item) {
            if (! this.canSpeak() || ! item?.say) return
            window.speechSynthesis.cancel()
            const u = new window.SpeechSynthesisUtterance(item.say)
            u.lang = locale === 'bn' ? 'bn-BD' : 'en-US'
            u.onend = () => { this.speaking = null }
            this.speaking = item.id
            window.speechSynthesis.speak(u)
        },
        stopSpeaking () {
            if (this.canSpeak()) window.speechSynthesis.cancel()
            this.speaking = null
        },
        toggleSpeak (item) { return this.speaking === item.id ? this.stopSpeaking() : this.speak(item) },
        isSpeaking (item) { return this.speaking === item.id },

        vote (item, value) {
            if (! item.answerId || item.voted !== 0) return
            item.voted = value
            fetch(feedbackUrl.replace('__ID__', String(item.answerId)), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ value }),
            })
        },
        votedUp (item) { return item.voted === 1 },
        votedDown (item) { return item.voted === -1 },

        scroll () {
            this.$nextTick(() => {
                const t = this.$refs.thread
                if (t) t.scrollTop = t.scrollHeight
            })
        },
    }
}
