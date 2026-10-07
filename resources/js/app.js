import Alpine from '@alpinejs/csp'
import { wireActions } from './components/actions.js'
import { reprice } from './pricing.js'
import { abosDate } from './date.js'
import { listKeys } from './list-keys.js'
import { searchAsYouType } from './search-as-you-type.js'
import { guardOneSubmit } from './one-submit.js'
import { guardConfirmOverview } from './confirm-overview.js'
import { stockPlacement } from './placement.js'
import { scannerStore } from './scanner.js'
import partyVoucher from './party-voucher.js'
import partySearch from './party-search.js'
import directSale from './counter/direct-sale.js'
import directPurchase from './counter/direct-purchase.js'
import openingCart from './counter/opening-cart.js'
import { registerComponents } from './components/index.js'
import { listenForColumnChoice } from './columns.js'

/*
 * Alpine শুধু ছোট UI ইন্টারঅ্যাকশনে — ড্রপডাউন, পাসওয়ার্ড দেখানো, ট্যাব
 * (প্ল্যান সেকশন ২)। পুরো পেজের লজিক Livewire-এ, নাহলে দুই জায়গায় দুই
 * state তৈরি হয় আর সেটাই বাগের উৎস।
 *
 * সেকশন ২০.৭: JS দিয়ে লেআউট বদলানো হয় না। CSS যা পারে তার জন্য JS নয়।
 */
/*
 * বিক্রয়মূল্যের অঙ্কটা এখান থেকে Blade-এ পৌঁছায়।
 *
 * ইনলাইন লিখলে ওটার কোনো পরীক্ষা লেখা যেত না, আর ওই অঙ্কই একটা গোটা
 * ডিপোর প্রতিটা পণ্যের দাম ঠিক করে। এখন বিশুদ্ধ ফাংশন, পরীক্ষা আছে
 * (`npm test`), আর Blade শুধু ডাকে।
 */
window.abos = { reprice }

/*
 * সাইডবার খোলা না গুটানো — একটাই জায়গায়।
 *
 * ── কেন store, প্রতিটা কম্পোনেন্টের নিজের x-data নয় ──────────────────
 * সুইচটা টপবারে (☰), আর যেটা নড়ে সেটা সাইডবার — দুইটা আলাদা DOM
 * শাখা। প্রতিটা নিজের x-data রাখলে দুইজনের দুই রকম উত্তর থাকত, আর
 * বোতাম চেপে কিছুই হত না।
 *
 * ── কেন সুইচটা টপবারে ────────────────────────────────────────────────
 * আগে গুটানোর বোতামটা সাইডবারের ভেতরেই ছিল (`«`)। কিন্তু সে যা গুটায়
 * তার ভেতরেই বসে, তাই গুটিয়ে ফেললে সে নিজেই উধাও — আর খোলার কোনো
 * পথ থাকত না। টপবারের ☰ দুই অবস্থাতেই একই জায়গায় থাকে, আর সেটাই
 * একমাত্র রূপ যেটা মানুষ শিখতে পারে।
 *
 * পছন্দটা localStorage-এ: এটা এই ব্রাউজারের দেখার পছন্দ, কোম্পানির
 * সেটিং নয়। সার্ভারে রাখলে প্রতিটা ক্লিকে একটা রিকোয়েস্ট যেত।
 */
document.addEventListener('alpine:init', () => {
    /*
     * তারিখের ঘর — দিন-মাস-বছর, সব কম্পিউটারে এক।
     *
     * ব্রাউজারের নিজের `type="date"` তার লোকেল ধরে আঁকে, আর সেটা বদলানোর
     * কোনো API নেই। এই কম্পিউটারে ১৯ আগস্ট দেখাত `08/19/2026`। `05/06`
     * পড়া যায় দুইভাবে — আর দুইটাই বৈধ তারিখ, তাই ভুলটা খাতা থেকে ধরাই
     * যায় না। অঙ্কটা `date.js`-এ, তাই তার পরীক্ষা আছে।
     */
    Alpine.data('abosDate', abosDate)

    /*
     * মাল কোথায় রাখা হলো — গুদাম ▸ ব্লক ▸ র‍্যাক ▸ শেলফ।
     *
     * ছাঁকাছাঁকিটা `placement.js`-এ, ঠিক `date.js`-এর কারণেই: ইনলাইন
     * `x-data` লিখলে ঐ নিয়মগুলোর কোনো পরীক্ষা লেখা যেত না, আর ভুল
     * শেলফে বসানো কার্টন খুঁজে না পাওয়া পর্যন্ত কেউ টের পেত না।
     */
    Alpine.data('stockPlacement', stockPlacement)

    /*
     * রসিদ ও পরিশোধের পর্দা — পক্ষ, বকেয়া, আর বিলের ভাগ।
     *
     * ⓘ যুক্তিটা ফাইলে, ব্লেডের অ্যাট্রিবিউটে নয়: ওখানে অনুবাদের
     * অ্যাপস্ট্রফি আর Blade-এর পার্সিং দুইবার ভেঙেছে।
     */
    Alpine.data('partyVoucher', partyVoucher)

    /*
     * ⭐ খোঁজা যায় এমন পক্ষের তালিকা — ৩ অক্টোবর ২০২৬, মালিক: *"ডেবিট নোট
     * পার্টি সার্চ দেয়ার অপশন নাই"*। ⓘ ব্লেডের দিক [[x-ui.party-search]]।
     */
    Alpine.data('partySearch', partySearch)

    /*
     * ⭐ কাউন্টারের বিক্রয় — ১৮ সেপ্টেম্বর ২০২৬, নিরীক্ষার ধাপ ৪.১।
     *
     * ⛔ যুক্তিটা ব্লেডের ভিতরে ১,৪৫৩ লাইন ছিল, আর তাতে একটাও পরীক্ষা
     * লেখা যেত না — অথচ ওখানেই দর, ছাড় ও খসড়ার হিসাব।
     */
    Alpine.data('directSale', directSale)
    Alpine.data('directPurchase', directPurchase)
    // ⭐ খোলা মজুদের কার্ট — সার্চ, ফ্রি, দাম, Enter = পরের ঘর (মালিক, ৬ অক্টোবর ২০২৬)
    Alpine.data('openingCart', openingCart)

    /*
     * ⭐ শেল, টাকার ঘর ও বাকি পর্দার ছোট কম্পোনেন্ট — ১৯ সেপ্টেম্বর ২০২৬।
     * ⓘ ব্লেডের অ্যাট্রিবিউটে লেখা যুক্তি CSP-Alpine পড়তে পারে না; তাই
     * প্রতিটা এখন একটা নাম, আর যুক্তিটা `components/`-এ।
     */
    registerComponents(Alpine)

    /*
     * ছবি তোলার পর্দা — চার কোণ টেনে কাগজ সোজা করা।
     *
     * ── কেন store, `Alpine.data` নয় ─────────────────────────────────
     * ছবির ঘর চারটা (সংযুক্তি, প্রোফাইল, পণ্য, লোগো) কিন্তু পর্দাটা
     * একটাই — শেলে বসানো। ⓘ ইনপুট আর পর্দা দুইটা আলাদা DOM শাখায়, তাই
     * সাইডবারের মতোই এখানে একটা ভাগ করা অবস্থা লাগে।
     *
     * ⚠️ অঙ্কটা এখানে নেই: perspective warp আর কোণ খোঁজা `scan.js`-এ,
     * আর তার পরীক্ষা `scan.test.js`-এ (`npm test`)। এখানে কেবল হাতের
     * কাজ — টানা, আঁকা, ফর্মে ফেরত দেওয়া।
     */
    Alpine.store('scanner', scannerStore())

    Alpine.store('sidebar', {
        collapsed: localStorage.getItem('abos.sidebar') === 'collapsed',

        toggle() {
            this.collapsed = ! this.collapsed
            localStorage.setItem('abos.sidebar', this.collapsed ? 'collapsed' : 'open')
        },
    })
})

/*
 * তালিকার কীবোর্ড — কেবল যে রূপ চায়।
 *
 * ⓘ ফাইলটা নিজেই দেখে নেয় খোলস `data-look-hints` এঁকেছে কি না; না
 * আঁকলে একটা শ্রোতাও বসে না। ⚠️ তাই অন্য ন'টা রূপে এটা যোগ করা আর না
 * করা এক — আর সেটা মেপে দেখা যায়, বিশ্বাস করতে হয় না।
 */
listKeys()

// তালিকার খোঁজ — টাইপ করতেই, Enter ছাড়া; নিয়ম `search-as-you-type.js`-এ
searchAsYouType()

/*
 * Columns মেনু — টিক-না-দেওয়া কলামগুলো `?hide=`-এ।
 * ⓘ এটা না থাকায় মেনুটা কোনো তালিকাতেই কিছু লুকাত না; কারণ `columns.js`-এ।
 */
listenForColumnChoice()

/*
 * ⛔ ছাপা ও "বদলালেই জমা" — CSP ইনলাইন হ্যান্ডলার চালাতে দেয় না।
 * ⓘ কারণটা [[actions.js]]-এ; মালিকের "print buton kaj kore na"।
 */
wireActions()

/*
 * ⛔ একটা ফর্ম একবারই জমা — পাহারা আর তার পুরো ইতিহাস [[one-submit.js]]-এ।
 */
// ⭐ নিশ্চিতের আগে সারাংশের পপ-আপ — একবার-জমার পাহারার আগে (capture), মালিক ৪ অক্টোবর ২০২৬ ([[confirm-overview.js]])
guardConfirmOverview()
guardOneSubmit()

window.Alpine = Alpine
Alpine.start()
