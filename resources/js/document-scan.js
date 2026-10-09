/*
 * স্ক্যান ও OCR — ব্যবহারকারীর ব্রাউজারে, আমাদের নিজের সার্ভারের ফাইলে (ডকুমেন্ট পরিকল্পনা §৭; ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ মালিকের প্রথম বাঁধন ──────────────────────────────────────────────
 * কোনো ছবি বা লেখা বাইরের সার্ভারে যায় না। tesseract.js (WebAssembly) চলে ব্রাউজারের একটা worker-এ, আর তার
 * চারটা জিনিস — worker, core (wasm), বাংলা আর ইংরেজির ভাষা-ফাইল — আসে `/vendor/tesseract/` থেকে, মানে আমাদের
 * সার্ভার থেকে। ⚠️ tesseract.js নিজে থেকে CDN (jsdelivr) থেকে নামাতে চায়; তাই প্রতিটা পথ এখানে হাতে বলা, আর
 * কোনো পথ অন্য ঠিকানার হলে কাজটা শুরুই হয় না ([[sameOrigin()]])।
 *
 * ⓘ `workerBlobURL: false` — পাতার CSP `blob:` worker মানে না; worker সরাসরি নিজের ঠিকানা থেকে চলে।
 * ⓘ লেখা পড়া কেবল ছাপা লেখা; হাতের লেখা AI দিয়ে নয় (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⓘ পর্দার দিক ─────────────────────────────────────────────────────────
 * পাতা যোগ (ক্যামেরা বা ফাইল, বারবার) → "লেখা পড়ুন" → লেখা আর তথ্যের ঘর ভরে → মানুষ দেখে ঠিক করেন → জমা।
 * তথ্যের প্রস্তাব (বিল নম্বর, তারিখ, পক্ষ, অঙ্ক) আসে আমাদের সার্ভারের নিয়ম থেকে ([[DocumentFieldExtractor]])।
 */

/** WebAssembly SIMD আছে কি না — থাকলে দ্রুত core, নয়তো সাধারণটা (দুইটাই আমাদের সার্ভারে) */
const SIMD_PROBE = new Uint8Array([0, 97, 115, 109, 1, 0, 0, 0, 1, 5, 1, 96, 0, 1, 123, 3, 2, 1, 0, 10, 10, 1, 8, 0, 65, 0, 253, 15, 253, 98, 11])

export function hasSimd (wasm = globalThis.WebAssembly) {
    try {
        return Boolean(wasm && wasm.validate(SIMD_PROBE))
    } catch {
        return false
    }
}

/** ⛔ কেবল এই সাইটের ঠিকানা — অন্য কোনো হোস্ট হলে false */
export function sameOrigin (path, origin) {
    try {
        return new URL(path, origin).origin === origin
    } catch {
        return false
    }
}

/**
 * tesseract.js-এর পথ — সবগুলো নিজের সার্ভারের।
 *
 * @param {string} base   যেমন `/vendor/tesseract`
 * @param {string} origin যেমন `https://abos.example`
 * @param {boolean} simd
 */
export function ocrPaths (base, origin, simd) {
    const root = base.replace(/\/+$/, '')
    const paths = {
        workerPath: `${root}/worker.min.js`,
        corePath: `${root}/core/${simd ? 'tesseract-core-simd-lstm.wasm.js' : 'tesseract-core-lstm.wasm.js'}`,
        langPath: `${root}/lang`,
    }

    for (const value of Object.values(paths)) {
        if (!sameOrigin(value, origin)) {
            throw new Error('OCR files must come from this server')
        }
    }

    return { ...paths, workerBlobURL: false, gzip: true, cacheMethod: 'none' }
}

/** "ben+eng" → ['ben', 'eng'] — কেবল চেনা দুইটা ভাষা */
export function languages (value) {
    const list = String(value || 'ben+eng').split('+').filter(l => l === 'ben' || l === 'eng')

    return list.length ? list : ['ben', 'eng']
}

export default function documentScan () {
    return {
        pages: [],
        busy: false,
        progress: 0,
        status: '',
        confidence: '',
        failed: false,

        init () {
            this.$watch('pages', () => this.syncInput())
        },

        /** ক্যামেরা বা ফাইল থেকে পাতা — বারবার যোগ করা যায় */
        addPages (event) {
            const max = Number(this.$el.dataset.maxPages || 10)

            for (const file of Array.from(event.target.files || [])) {
                if (this.pages.length >= max) {
                    break
                }

                this.pages.push({ file, url: URL.createObjectURL(file), name: file.name })
            }

            event.target.value = ''
        },

        removePage (index) {
            const [gone] = this.pages.splice(index, 1)

            if (gone) {
                URL.revokeObjectURL(gone.url)
            }
        },

        /** জমার ঘরে সব পাতা — ব্রাউজারের ফাইল-ঘর একবারে একটা বাছাই রাখে, তাই হাতে জোড়া */
        syncInput () {
            const input = this.$refs.pages

            if (!input || typeof DataTransfer === 'undefined') {
                return
            }

            const bag = new DataTransfer()
            this.pages.forEach(page => bag.items.add(page.file))
            input.files = bag.files
        },

        /**
         * আগে তোলা ছবির কাগজ — ফাইলটা আমাদের নিজের প্রিভিউর দরজা থেকে নামিয়ে পড়া (`data-image-url`)।
         * ⓘ দরজাটা কাগজের নিজের দেয়াল আর অডিট মানে; ছবি কোথাও বাইরে যায় না।
         */
        async readExisting () {
            const url = this.$el.dataset.imageUrl

            if (!url || !sameOrigin(url, globalThis.location.origin) || this.busy) {
                return
            }

            const response = await fetch(url, { credentials: 'same-origin' })

            if (!response.ok) {
                this.failed = true
                this.status = this.$el.dataset.wordFailed

                return
            }

            const blob = await response.blob()
            this.pages = [{ file: blob, url: '', name: 'page' }]
            await this.readText()
        },

        async readText () {
            const engine = globalThis.Tesseract

            if (!engine || !this.pages.length || this.busy) {
                return
            }

            const data = this.$el.dataset
            this.busy = true
            this.failed = false
            this.progress = 0
            this.status = data.wordLoading

            let worker = null

            try {
                const paths = ocrPaths(data.ocrBase, globalThis.location.origin, hasSimd())

                worker = await engine.createWorker(languages(data.ocrLanguages), 1, {
                    ...paths,
                    logger: message => {
                        if (message && typeof message.progress === 'number') {
                            this.progress = Math.round(message.progress * 100)
                        }
                    },
                })

                const texts = []
                const scores = []

                for (const [i, page] of this.pages.entries()) {
                    this.status = data.wordReading.replace(':n', String(i + 1)).replace(':total', String(this.pages.length))
                    const result = await worker.recognize(page.file)
                    texts.push(result.data.text.trim())
                    scores.push(result.data.confidence)
                }

                this.$refs.text.value = texts.join('\n\n')
                this.confidence = scores.length ? (scores.reduce((a, b) => a + b, 0) / scores.length).toFixed(2) : ''
                this.status = data.wordDone

                await this.suggestFields()
            } catch (error) {
                this.failed = true
                this.status = data.wordFailed
            } finally {
                if (worker) {
                    await worker.terminate()
                }

                this.busy = false
            }
        },

        /** তথ্যের প্রস্তাব — আমাদের সার্ভারের নিয়ম; ঘর ফাঁকা থাকলেই ভরে, মানুষের লেখা মোছে না */
        async suggestFields () {
            const data = this.$el.dataset
            const text = this.$refs.text.value

            if (!text.trim()) {
                return
            }

            const response = await fetch(data.fieldsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': data.csrf,
                },
                body: JSON.stringify({ text }),
                credentials: 'same-origin',
            })

            if (!response.ok) {
                return
            }

            const fields = await response.json()

            for (const [key, value] of Object.entries(fields)) {
                const input = this.$el.querySelector(`[data-ocr-field="${key}"]`)

                if (input && !input.value && value) {
                    input.value = value
                }
            }
        },
    }
}
