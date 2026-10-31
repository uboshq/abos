/*
 * ⭐ ফোনের নিচের বার — স্ক্রলে সরে যাওয়া, চালু মডিউলে আবার চাপ, লম্বা চাপের ছোট মেনু (মালিক, ৩১ অক্টোবর ২০২৬; Material 3, iOS)।
 *
 *   · নিচে স্ক্রল (৮px-এর বেশি) করলে বার সরে যায়, উপরে করলেই ফেরে; পাতার মাথায় সবসময় দেখা যায়;
 *   · চালু মডিউলে আবার চাপ: পাতা নিচে থাকলে মাথায় ফেরে (যাওয়া থামিয়ে), মাথায় থাকলে মডিউলের প্রথম পাতায় যায় (সাধারণ লিংক);
 *   · ৫০০ms চেপে ধরলে মডিউলের ছোট মেনু (`sheet`); তার পরের ছেড়ে দেওয়া-জনিত চাপ লিংকে যায় না।
 */

/** একটা স্ক্রল ধাপের পরে বার লুকানো থাকবে কি না */
export function hiddenAfter ({ hidden, last, now }) {
    if (now <= 0) {
        return false
    }
    if (Math.abs(now - last) <= 8) {
        return hidden
    }

    return now > last
}

export function bottomNav ({ win = window, doc = document } = {}) {
    return {
        hidden: false,
        sheet: null,
        last: 0,
        timer: null,
        held: false,

        scrolled () {
            const now = win.scrollY
            this.hidden = this.sheet === null && hiddenAfter({ hidden: this.hidden, last: this.last, now })
            this.last = now
        },

        again (event) {
            if (win.scrollY > 0) {
                event.preventDefault()
                win.scrollTo({ top: 0, behavior: 'smooth' })
            }
        },

        press (code) {
            this.held = false
            clearTimeout(this.timer)
            this.timer = setTimeout(() => {
                this.held = true
                this.sheet = code
            }, 500)
        },

        release () {
            clearTimeout(this.timer)
            if (this.held) {
                // ⓘ চেপে ধরে ছাড়ার পরের "click" লিংকে নিয়ে যেত — একবার থামানো
                doc.addEventListener('click', (e) => e.preventDefault(), { capture: true, once: true })
                this.held = false
            }
        },
    }
}
