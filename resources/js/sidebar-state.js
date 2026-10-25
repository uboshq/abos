/*
 * ⭐ বাঁ মেনুর অবস্থা — দুই-কলামের টাকার ফর্মে জায়গা বাঁচানো (মালিক আর fe, ২৪ অক্টোবর ২০২৬; আদায়ের ছবি ১২ আর ১৩)।
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * মালিকের নকশায় টাকার ফর্ম দুই কলামে: বাঁয়ে ঘর আর বিলের টেবিল, ডানে মাথার তিন অঙ্ক, সারাংশ, খাতা আর নিয়ন্ত্রণ। মালিকের
 * ল্যাপটপ ১৩৬৬ চওড়া; খোলা মেনু (২২০–২৬০px) সেখানে বিলের টেবিল ৫৪px কাটে, আর ১৪৪০-এও ৩৪px (আসল ব্রাউজারে মাপা)। মেনু
 * আইকন-রেলে (৫৮px) থাকলে দুই কলাম ঠিক ১৩৬৬ থেকে ধরে।
 *
 * ── নিয়ম ──────────────────────────────────────────────────────────────
 *   · কেবল যে পাতা নিজেকে দুই-কলামের টাকার ফর্ম বলে (`<html data-two-column-form>`), আর কেবল FLOATS_BELOW-এর নিচে;
 *   · মানুষের নিজের বাছাই (localStorage) থাকলে সেটাই — কেবল প্রথমবারের ডিফল্ট বদলায়: তখন রেলে;
 *   · ঐ পাতায় ঐ চওড়ায় মেনু খোলা থাকলে পাতার **উপরে ভাসে**, পাতা ঠেলে না — তাই দুই কলাম কখনো ভাঙে না;
 *   · ভাসমান মেনুর বাইরে চাপ দিলে বন্ধ হয়, কিন্তু মানুষের বাছাই বদলায় না (পরের পাতায় তাঁর বাছাইটাই)।
 * ⓘ অন্য সব পাতা আর FLOATS_BELOW-এর উপরে আজকের মতোই: খোলা মেনু জায়গা নেয়, গুটানো রেলে।
 */

/** এর নিচে দুই-কলামের টাকার ফর্মে মেনু রেলে বা ভাসমান — মাপা: ১৫৩৬-এ খোলা মেনুর সাথে বিলের টেবিল পুরো ধরে, ১৪৪০-এ ৩৪px কম */
export const FLOATS_BELOW = 1536

/**
 * শুরুর অবস্থা।
 *
 * @param {{ stored: string|null, twoColumnForm: boolean, width: number }} at
 * @returns {{ collapsed: boolean, floats: boolean }}
 */
export function sidebarStart ({ stored, twoColumnForm, width }) {
    const floats = twoColumnForm && width < FLOATS_BELOW

    if (stored === 'collapsed' || stored === 'open') {
        return { collapsed: stored === 'collapsed', floats }
    }

    return { collapsed: floats, floats }
}

/** Alpine store — app.js বসায়; `doc`/`win` পরীক্ষায় বদলানো যায় */
export function sidebarStore ({ doc = document, win = window, storage = window.localStorage } = {}) {
    const read = () => { try { return storage.getItem('abos.sidebar') } catch (e) { return null } }
    const twoColumnForm = doc.documentElement.hasAttribute('data-two-column-form')
    const start = sidebarStart({ stored: read(), twoColumnForm, width: win.innerWidth })

    return {
        collapsed: start.collapsed,
        floats: start.floats,

        /** রেলের চওড়া — গুটানো, বা ভাসমান (খোলা থাকলেও জায়গা রেলেরই) */
        get rail () {
            return this.collapsed || this.floats
        },

        /** মেনু পাতার উপরে ভাসছে */
        get overlay () {
            return this.floats && ! this.collapsed
        },

        toggle () {
            this.collapsed = ! this.collapsed
            try { storage.setItem('abos.sidebar', this.collapsed ? 'collapsed' : 'open') } catch (e) {}
        },

        /**
         * ভাসমান মেনুর বাইরে চাপ — বন্ধ, কিন্তু মানুষের বাছাই যেমন ছিল।
         * ⛔ ≡ বোতামের চাপ নয়: সেটা নিজেই toggle() ডাকে — এখানে বন্ধ করলে toggle() আবার খুলে দিত
         */
        closeOverlay (event = null) {
            if (event?.target?.closest?.('[data-sidebar-toggle]')) {
                return
            }
            if (this.overlay) {
                this.collapsed = true
            }
        },

        /** জানালার চওড়া বদলালে (ঘোরানো, পাশে টানা) ভাসা-না-ভাসা আবার মাপা */
        resized (width) {
            this.floats = twoColumnForm && width < FLOATS_BELOW
        },
    }
}
