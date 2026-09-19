import { detectCorners, orderCorners, suggestSize, warp } from './scan.js'

/*
 * ছবি তোলার পর্দা — চার কোণ টেনে কাগজ সোজা করা।
 *
 * ── ⭐ কেন একটাই store, প্রতিটা ইনপুটের নিজের x-data নয় ──────────────
 * ছবির ঘর এই রিপোতে চারটা: সংযুক্তি, প্রোফাইল, পণ্যের ছবি, কোম্পানির
 * লোগো। ⓘ প্রতিটাতে নিজের অবস্থা রাখলে পর্দার markup চারবার লিখতে হত,
 * আর চারটা একদিন চার রকম আচরণ করত — ঠিক যে ভুলটা আজ সারাদিন সারানো
 * হয়েছে। ([[resources/js/app.js]]-এর সাইডবার store একই যুক্তিতে।)
 *
 * ⭐ তাই: অঙ্ক [[scan.js]]-এ (পরীক্ষা আছে), অবস্থা এখানে, আর markup
 * শেলে একবার।
 *
 * ── ⚠️ এটা সার্ভারের কাজের **বদলে** নয়, আগে ─────────────────────────
 * ব্রাউজার যা পাঠায় সেটা বিশ্বাস করা যায় না — JS বন্ধ থাকতে পারে,
 * পুরনো ফোন হতে পারে, কেউ সরাসরি অনুরোধও পাঠাতে পারে। ⛔ তাই
 * [[App\Core\Engines\Image\ImageEngine]] সবসময় নিজের কাজটা করে।
 * ⓘ এখানে যা হয় তা বাড়তি: **সোজা করা**, যেটা সার্ভার পারে না।
 */

/** পর্দায় ছবিটা এর চেয়ে বড় দেখানো হয় না — টানাটানি হালকা রাখতে। */
const VIEW_EDGE = 900

export function scannerStore() {
    return {
        open: false,
        busy: false,
        mode: 'paper',

        /** কোন ইনপুটে ফলাফলটা ফেরত যাবে। */
        input: null,

        /** পর্দায় দেখানো (ছোট করা) ছবি ও তার মাপ। */
        view: null,
        viewWidth: 0,
        viewHeight: 0,

        /** মূল ছবি — সোজা করা হয় এটার উপরেই, পর্দারটার উপরে নয়। */
        full: null,

        /** পর্দার স্থানাঙ্কে চার কোণ। */
        corners: [],

        /*
         * ⚠️ শুরুতেই শূন্য মান দিয়ে ঘোষণা করা — ফাঁকা বস্তু নয়।
         * ⓘ পর্দার binding-গুলো (`square.x`) Alpine প্রথমবারেই পড়ে,
         * অর্থাৎ ছবি বাছার **আগেই**। ⛔ ঘরটা না থাকলে ওখানেই একটা
         * নীরব JS ত্রুটি পড়ত আর গোটা পর্দাটা আর আঁকাই হত না।
         */
        square: { x: 0, y: 0, size: 0 },

        /*
         * ⓘ মুখের বর্গটা ছবির কত শতাংশ — আকারের স্লাইডারের মান।
         * ⚠️ পর্দায় লেখা যেত না: CSP-Alpine `Math` দেখে না।
         */
        get squarePercent() {
            const side = Math.min(this.viewWidth, this.viewHeight)

            return side ? Math.round(this.square.size * 100 / side) : 0
        },

        /** পর্দার মাপ, `:style`-এর জন্য — টেমপ্লেট লিটারাল CSP-Alpine পড়ে না */
        get viewBox() {
            return { width: this.viewWidth + 'px', height: this.viewHeight + 'px' }
        },

        get squareBox() {
            const { x, y, size } = this.square

            return { left: x + 'px', top: y + 'px', width: size + 'px', height: size + 'px' }
        },

        dragging: -1,
        detected: false,
        failed: '',

        /**
         * ⛔ পুনঃপ্রবেশের ফাঁদ, আর এটা না থাকলে ব্রাউজার আটকে যেত।
         *
         * ⓘ `handBack()` ইনপুটে একটা `change` ঘটনা ছোঁড়ে (নাহলে প্রিভিউ
         * পুরনো ফাইলটাই দেখাত)। ⚠️ কিন্তু ইনপুটের `x-on:change` তো
         * `begin()`-ই ডাকে — অর্থাৎ পর্দা আবার খুলত, আবার ফেরত দিত,
         * অনন্তকাল।
         *
         * ⭐ ঘটনাটা ছোঁড়ার সময়টুকু এই পতাকা তোলা থাকে, আর `begin()`
         * তখন চুপ করে ফিরে যায়।
         */
        handing: false,

        /** প্রোফাইলের ফর্মটা ছবি বাছলেই নিজে থেকে জমা হয় — তাই এই ঘরটা। */
        autoSubmit: false,

        /**
         * ফাইল বাছার পর — পর্দা খোলা।
         *
         * ⚠️ যা ছবি নয় (PDF, Excel) তাতে হাত পড়ে না: `createImageBitmap`
         * ব্যর্থ হয়, আর ফাইলটা যেমন ছিল তেমনই ফর্মের সাথে যায়।
         */
        async begin(input, mode = 'paper', options = {}) {
            if (this.handing) {
                return
            }

            const file = input?.files?.[0]

            /*
             * ⭐ যা ছবি নয় তাতে হাত পড়ে না — সংযুক্তির ঘরে PDF, Excel,
             * Word সবই আসে, আর ওগুলো যেমন আছে তেমনই যাওয়া উচিত।
             *
             * ⚠️ তবু `autoSubmit` থাকলে ফর্মটা জমা দিতে হয়, নাহলে
             * প্রোফাইলের পাতায় ছবি বাছার পরেও কিছুই হত না।
             */
            if (!file || !file.type.startsWith('image/')) {
                if (options.submit) {
                    input?.form?.requestSubmit()
                }

                return
            }

            this.input = input
            this.mode = mode
            this.autoSubmit = options.submit === true
            this.failed = ''
            this.busy = true

            try {
                /*
                 * ⭐ `imageOrientation: 'from-image'` — এটা না দিলে ফোনে
                 * তোলা ছবি ব্রাউজারেও পাশ ফিরে আসত, আর ব্যবহারকারী
                 * কোণ টানতেন উল্টো ছবির উপর।
                 */
                const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })

                this.full = bitmap
                this.prepareView(bitmap)
                this.propose()
                this.open = true
            } catch {
                // ছবি হিসেবে খোলা গেল না — চুপচাপ ছেড়ে দেওয়া, সার্ভার সামলাবে।
                this.reset()
            } finally {
                this.busy = false
            }
        },

        /** পর্দার জন্য ছোট একটা কপি। */
        prepareView(bitmap) {
            const long = Math.max(bitmap.width, bitmap.height)
            const scale = long > VIEW_EDGE ? VIEW_EDGE / long : 1

            this.viewWidth = Math.round(bitmap.width * scale)
            this.viewHeight = Math.round(bitmap.height * scale)

            const canvas = document.createElement('canvas')
            canvas.width = this.viewWidth
            canvas.height = this.viewHeight

            const context = canvas.getContext('2d', { willReadFrequently: true })
            context.drawImage(bitmap, 0, 0, this.viewWidth, this.viewHeight)

            this.view = canvas
        },

        /**
         * কাগজটা কোথায় — প্রস্তাব।
         *
         * ⓘ পাওয়া না গেলে পুরো ছবিটাই চতুর্ভুজ ধরা হয়। ⭐ তখনও কাজটা
         * নষ্ট হয় না: ব্যবহারকারী কোণ চারটা নিজে টেনে বসাতে পারেন, আর
         * সেটাই ছিল "খ" — হাতে চার কোণ বেছে দেওয়া।
         */
        propose() {
            if (this.mode === 'face') {
                this.corners = []
                this.square = this.centredSquare()

                return
            }

            const context = this.view.getContext('2d', { willReadFrequently: true })
            const found = detectCorners(context.getImageData(0, 0, this.viewWidth, this.viewHeight))

            this.detected = found !== null

            const margin = 0.04
            const x0 = Math.round(this.viewWidth * margin)
            const y0 = Math.round(this.viewHeight * margin)

            this.corners = found ?? [
                { x: x0, y: y0 },
                { x: this.viewWidth - x0, y: y0 },
                { x: this.viewWidth - x0, y: this.viewHeight - y0 },
                { x: x0, y: this.viewHeight - y0 },
            ]
        },

        /** প্রোফাইল ছবির জন্য শুরুর বর্গ — মাঝামাঝি, মুখের দিকে একটু উপরে। */
        centredSquare() {
            const side = Math.round(Math.min(this.viewWidth, this.viewHeight) * 0.8)

            return {
                x: Math.round((this.viewWidth - side) / 2),
                y: Math.round((this.viewHeight - side) * (this.viewHeight > this.viewWidth ? 0.15 : 0.5)),
                size: side,
            }
        },

        /* ── কোণ টানা ────────────────────────────────────────────────── */

        grab(index) {
            this.dragging = index
        },

        /** মুখের বর্গটা ধরা — কোণের সূচকের সাথে যাতে না মেশে, তাই আলাদা চিহ্ন। */
        grabSquare() {
            this.dragging = 'square'
        },

        drop() {
            this.dragging = -1
        },

        /**
         * বর্গের মাপ — ছোট বাহুর শতাংশে।
         *
         * ⚠️ মাপ বদলালে বর্গটা ছবির বাইরে বেরিয়ে যেতে পারে, তাই প্রতিবার
         * ভিতরে টেনে আনা হয়। ⓘ নাহলে বড় করার পর কাটা অংশে সাদা ফালি
         * ঢুকত, আর প্রোফাইল ছবির এক কোণ ফাঁকা থাকত।
         */
        resize(percent) {
            const short = Math.min(this.viewWidth, this.viewHeight)

            this.square.size = Math.round((short * Number(percent)) / 100)
            this.keepSquareInside()
        },

        keepSquareInside() {
            this.square.size = Math.max(24, Math.min(this.square.size, this.viewWidth, this.viewHeight))
            this.square.x = Math.max(0, Math.min(this.square.x, this.viewWidth - this.square.size))
            this.square.y = Math.max(0, Math.min(this.square.y, this.viewHeight - this.square.size))
        },

        /**
         * টেনে সরানো।
         *
         * ⚠️ কোণ ছবির বাইরে যেতে দেওয়া হয় না। ⓘ দিলে সোজা করা ছবিতে
         * সাদা ফালি ঢুকত, আর ব্যবহারকারী ভাবতেন স্ক্যানটা কেটে গেছে।
         */
        move(event) {
            if (this.dragging < 0) {
                return
            }

            const box = event.currentTarget.getBoundingClientRect()
            const touch = event.touches?.[0]
            const x = (touch?.clientX ?? event.clientX) - box.left
            const y = (touch?.clientY ?? event.clientY) - box.top

            if (this.dragging === 'square') {
                // আঙুলটা বর্গের মাঝখানে ধরে রাখা হয়, কোণে নয় — ওটাই স্বাভাবিক।
                this.square.x = Math.round(x - this.square.size / 2)
                this.square.y = Math.round(y - this.square.size / 2)
                this.keepSquareInside()

                return
            }

            this.corners[this.dragging] = {
                x: Math.max(0, Math.min(this.viewWidth, Math.round(x))),
                y: Math.max(0, Math.min(this.viewHeight, Math.round(y))),
            }
        },

        /** SVG-র জন্য চার কোণের পথ। */
        get outline() {
            return this.corners.map((p) => `${p.x},${p.y}`).join(' ')
        },

        /* ── ফলাফল ───────────────────────────────────────────────────── */

        /**
         * ⭐ সোজা করে ফর্মে ফেরত পাঠানো।
         *
         * ── কেন মূল ছবিতে, পর্দারটায় নয় ────────────────────────────
         * পর্দায় ছবিটা ৯০০px-এ নামানো। ⛔ ওটার উপর কাজ করলে ফলাফলও
         * ৯০০px হত, আর ছাপা বিলের ছোট লেখা পড়া যেত না।
         *
         * ⭐ তাই কোণগুলোকে মূল ছবির মাপে ফিরিয়ে নিয়ে তারপর কাজ।
         */
        async accept() {
            this.busy = true

            try {
                const scale = this.full.width / this.viewWidth
                const blob = this.mode === 'face'
                    ? await this.cutSquare(scale)
                    : await this.flatten(scale)

                if (blob !== null) {
                    this.handBack(blob)
                }
            } finally {
                this.busy = false
                this.finish()
            }
        },

        async flatten(scale) {
            const corners = orderCorners(this.corners.map((p) => ({
                x: p.x * scale,
                y: p.y * scale,
            })))

            if (corners === null) {
                return null
            }

            const { width, height } = suggestSize(corners)
            const source = this.pixelsOfFull()
            const flattened = warp(source, corners, width, height)

            if (flattened === null) {
                return null
            }

            const canvas = document.createElement('canvas')
            canvas.width = width
            canvas.height = height
            canvas.getContext('2d').putImageData(new ImageData(flattened.data, width, height), 0, 0)

            return this.toBlob(canvas)
        },

        async cutSquare(scale) {
            const side = Math.round(this.square.size * scale)
            const canvas = document.createElement('canvas')
            canvas.width = side
            canvas.height = side

            canvas.getContext('2d').drawImage(
                this.full,
                Math.round(this.square.x * scale),
                Math.round(this.square.y * scale),
                side,
                side,
                0,
                0,
                side,
                side,
            )

            return this.toBlob(canvas)
        },

        pixelsOfFull() {
            const canvas = document.createElement('canvas')
            canvas.width = this.full.width
            canvas.height = this.full.height

            const context = canvas.getContext('2d', { willReadFrequently: true })
            context.drawImage(this.full, 0, 0)

            return context.getImageData(0, 0, this.full.width, this.full.height)
        },

        toBlob(canvas) {
            return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92))
        },

        /**
         * ফাইলটা ইনপুটে ফেরত বসানো।
         *
         * ⓘ `input.files` সরাসরি লেখা যায় না; `DataTransfer` একমাত্র পথ।
         * ⚠️ `change` ঘটনাটা হাতে ছোঁড়া হয়, নাহলে যে পর্দাগুলো নাম বা
         * প্রিভিউ দেখায় তারা পুরনো ফাইলটাই দেখাত।
         */
        handBack(blob) {
            const original = this.input.files[0]
            const name = original.name.replace(/\.[^.]+$/, '') + '.jpg'
            const transfer = new DataTransfer()

            transfer.items.add(new File([blob], name, { type: 'image/jpeg' }))

            this.input.files = transfer.files

            this.handing = true
            this.input.dispatchEvent(new Event('change', { bubbles: true }))
            this.handing = false
        },

        /**
         * পর্দা বন্ধ, আর দরকার হলে ফর্মটা জমা।
         *
         * ⚠️ ফর্মটা **`close()`-এর আগে** ধরে রাখতে হয়: `reset()`
         * `this.input` মুছে দেয়, তাই পরে খুঁজলে কিছুই পাওয়া যেত না আর
         * প্রোফাইলের ছবি নীরবে জমা হত না।
         */
        finish() {
            const form = this.autoSubmit ? this.input?.form : null

            this.close()

            form?.requestSubmit()
        },

        /**
         * বাদ দেওয়া — আর তখন **মূল ফাইলটাই** ফর্মে থেকে যায়।
         *
         * ⭐ এটাই সঠিক আচরণ: ব্যবহারকারী স্ক্যানের পর্দা না চাইলে
         * আপলোডটা বাতিল হওয়ার কথা নয়। সার্ভার তখনও ঘুরিয়ে, ছোট করে,
         * চেপে নেবে।
         */
        skip() {
            this.finish()
        },

        close() {
            this.open = false
            this.reset()
        },

        reset() {
            this.full?.close?.()
            this.full = null
            this.view = null
            this.input = null
            this.corners = []
            this.dragging = -1
            this.detected = false
        },

        /** পর্দায় আঁকার জন্য — ছোট করা ছবিটার data URL। */
        get preview() {
            return this.view === null ? '' : this.view.toDataURL('image/jpeg', 0.8)
        },
    }
}
