/*
 * ⭐ কোপাইলটের দ্রুত জিজ্ঞাসা — উপরের বারের মগজ (মালিক, ১২ নভেম্বর ২০২৬: Siri আর Gemini-র মতো; নমুনা 3uJhK92DAMhwyELt63mSmr)।
 *
 * ⓘ চাপলে বা Ctrl+J দিলে পুরো পর্দা জুড়ে কেবল জিজ্ঞাসা — পাতা বদলায় না; Esc বা × দিলে সেই পাতাতেই। প্রশ্ন আর উত্তর চ্যাটের মতো।
 * ⓘ কথা: ব্রাউজারের নিজের কথা-চেনা (Chrome বাংলা বোঝে) আর পড়ে-শোনানো; না থাকলে মাইক ধূসর, লেখা চলে। ⛔ অডিও কোথাও জমা হয় না —
 *   সার্ভারে যায় কেবল লেখা প্রশ্নটা, আর সেটাই খাতায় (মালিকের সিদ্ধান্ত: এখন ব্রাউজার, পরে NEXUS-এর Whisper)।
 * ⓘ সংখ্যা সার্ভারের — মডেল কেবল প্রশ্ন বোঝে; চাবি না থাকলে উত্তর নেই ([[CopilotApiController::ask()]])।
 */

const recognizer = () => (typeof window === 'undefined' ? null : (window.SpeechRecognition || window.webkitSpeechRecognition || null))

/** ⓘ মাইক ভুলে খোলা থাকলে এতক্ষণ পরে নিজে থামে — বলা কথা ঘরে থাকে (মালিক, ১৩ নভেম্বর ২০২৬) */
export const MAX_LISTEN_MS = 120000

/** আগে জমা কথা আর নতুন অংশ — মাঝে একটা ফাঁক, দুই পাশের বাড়তি ফাঁক বাদ */
export function joinSaid (kept, part) {
    return [String(kept ?? '').trim(), String(part ?? '').trim()].filter((s) => s !== '').join(' ')
}

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
        // ⓘ শোনার অবস্থা — জমা কথা, কে থামাতে চাইল (null · keep · send · cancel), কখন শুরু, ২ মিনিটের ঘড়ি
        kept: '',
        wantStop: null,
        listenStarted: 0,
        listenTimer: null,
        voiceTyped: false,
        micBlocked: false,

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

        /*
         * ⭐ যতক্ষণ মানুষ থামান না, ততক্ষণ শোনা (মালিক, ১৩ নভেম্বর ২০২৬: "Claude অ্যাপের মতো — যতক্ষণ মন চায় কথা বলা, শেষ হলে
         *   সেন্ড")। ⓘ ব্রাউজারের কথা-চেনা চুপ থাকলে বা নিজের সময় শেষে থেমে যায় — তখন জমা লেখা রেখে আবার শুরু, মানুষ টের পান না।
         *   শেষ করেন মানুষ: "বলা শেষ" — লেখা ঘরে, দেখে পাঠানো; "পাঠান" — সঙ্গে সঙ্গে। ⛔ ভুলে খোলা থাকলে [[MAX_LISTEN_MS]]-এ নিজে থামে,
         *   যা বলা হয়েছে ঘরে থাকে।
         */
        listen () {
            const Rec = recognizer()
            if (Rec === null || this.busy) return
            this.stopSpeaking()
            this.kept = this.q.trim()
            this.heard = this.kept
            this.listening = true
            this.wantStop = null
            this.listenStarted = Date.now()
            clearTimeout(this.listenTimer)
            this.listenTimer = setTimeout(() => this.finishListening(false), MAX_LISTEN_MS)
            this.startRecognizer()
        },
        startRecognizer () {
            const Rec = recognizer()
            if (Rec === null) return
            const rec = new Rec()
            rec.lang = locale === 'bn' ? 'bn-BD' : 'en-US'
            rec.interimResults = true
            rec.continuous = true
            let finalPart = ''
            rec.onresult = (e) => {
                let interim = ''
                finalPart = ''
                for (let i = 0; i < e.results.length; i++) {
                    if (e.results[i].isFinal) finalPart += e.results[i][0].transcript
                    else interim += e.results[i][0].transcript
                }
                this.heard = joinSaid(this.kept, finalPart + interim)
            }
            rec.onerror = (e) => {
                // ⓘ চুপ থাকা বা মাঝপথে কাটা ভুল নয় — আবার শুরু হবে; অনুমতি না পেলে থামা
                if (e && (e.error === 'not-allowed' || e.error === 'service-not-allowed')) {
                    this.micBlocked = true
                    this.wantStop = 'keep'
                }
            }
            rec.onend = () => {
                this.kept = joinSaid(this.kept, finalPart)
                if (this.wantStop === null && this.listening && Date.now() - this.listenStarted < MAX_LISTEN_MS) {
                    this.startRecognizer()
                    return
                }
                this.endListening()
            }
            this.rec = rec
            try { rec.start() } catch (_) { this.endListening() }
        },
        endListening () {
            clearTimeout(this.listenTimer)
            const said = this.heard.trim()
            const send = this.wantStop === 'send'
            this.listening = false
            this.rec = null
            this.heard = ''
            if (this.wantStop === 'cancel') return
            this.q = said
            if (send && said !== '') {
                this.byVoice = true
                this.ask(said)
                return
            }
            // ⓘ বলা কথা ঘরে — দেখে পাঠান; পাঠালে উত্তরও শোনায় (বলে জিজ্ঞেস করা)
            this.voiceTyped = said !== ''
            this.$nextTick(() => this.$refs.box?.focus())
        },
        finishListening (send) {
            this.wantStop = send ? 'send' : 'keep'
            if (this.rec !== null) this.rec.stop()
            else this.endListening()
        },
        stopListening () { this.finishListening(false) },
        sendListening () { this.finishListening(true) },
        cancelListening () {
            this.wantStop = 'cancel'
            this.q = this.kept
            if (this.rec !== null) this.rec.abort()
            else this.endListening()
        },

        pick (text) {
            this.byVoice = false
            this.ask(text)
        },
        submit () {
            // ⓘ মাইকে বলা কথা ঘর থেকে পাঠালেও "বলে জিজ্ঞেস করা" — উত্তর পড়ে শোনায়
            this.byVoice = this.voiceTyped
            this.voiceTyped = false
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
