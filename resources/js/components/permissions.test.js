// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { wireActions } from './actions.js'

/*
 * অনুমতির পর্দার "সব" টিক — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ jsdom, happy-dom নয়: `indeterminate` আর `<details>`-এর আচরণ এখানে
 * আসল ব্রাউজারের কাছাকাছি।
 */

const MODULE = `
<form>
  <details data-permission-module="sales">
    <summary>
      <label><input type="checkbox" data-permission-all></label>
      <span data-permission-count data-permission-total="5"
            data-permission-label=":on / :all">1 / 5</span>
    </summary>
    <table>
      <thead>
        <tr>
          <th><input type="checkbox" data-permission-column="view"></th>
          <th><input type="checkbox" data-permission-column="create"></th>
        </tr>
      </thead>
      <tbody data-permission-section="transactions">
        <tr><th><label><input type="checkbox" data-permission-section-all></label></th></tr>
        <tr>
          <td><input type="checkbox" name="permissions[]" value="sales.order.view" data-permission-cell="view" checked></td>
          <td><input type="checkbox" name="permissions[]" value="sales.order.create" data-permission-cell="create"></td>
        </tr>
        <tr>
          <td><input type="checkbox" name="permissions[]" value="sales.invoice.view" data-permission-cell="view"></td>
          <td><input type="checkbox" name="permissions[]" value="sales.invoice.create" data-permission-cell="create"></td>
        </tr>
      </tbody>
      <tbody data-permission-section="reports">
        <tr><th><label><input type="checkbox" data-permission-section-all></label></th></tr>
        <tr>
          <td colspan="2"><input type="checkbox" name="permissions[]" value="sales.scheme.manage" data-permission-cell="manage"></td>
        </tr>
      </tbody>
    </table>
  </details>

  <details data-permission-module="purchase">
    <summary><label><input type="checkbox" data-permission-all></label></summary>
    <input type="checkbox" name="permissions[]" value="purchase.bill.view" data-permission-cell="view">
  </details>
</form>`

let root

const boxes = (selector) => [...root.querySelectorAll(selector)]
const checked = (module) => boxes(`[data-permission-module="${module}"] input[name="permissions[]"]:checked`).length

function fire (element) {
    element.dispatchEvent(new window.Event('change', { bubbles: true }))
}

function mount (html) {
    document.body.innerHTML = html
    root = document.body
    wireActions(root)
}

describe('অনুমতির "সব" টিক', () => {
    beforeEach(() => mount(MODULE))

    it('মডিউলের টিকে ঐ মডিউলের সবকিছু বসে — manage সহ', () => {
        const all = root.querySelector('[data-permission-module="sales"] [data-permission-all]')

        all.checked = true
        fire(all)

        expect(checked('sales')).toBe(5)
    })

    /*
     * ⛔ আর **অন্য মডিউল ছোঁয়া হয় না** — এটাই আসল দাবি।
     *
     * ⚠️ বাছাইটা `document`-এ বসালে একটা টিকে গোটা পর্দার দুইশো ঘর বসত,
     * আর মানুষ টেরও পেতেন না — সংরক্ষণের পর।
     */
    it('পাশের মডিউল অক্ষত থাকে', () => {
        const all = root.querySelector('[data-permission-module="sales"] [data-permission-all]')

        all.checked = true
        fire(all)

        expect(checked('purchase')).toBe(0)
    })

    it('তুলে নিলে সব তুলে যায়', () => {
        const all = root.querySelector('[data-permission-module="sales"] [data-permission-all]')

        all.checked = true
        fire(all)
        all.checked = false
        fire(all)

        expect(checked('sales')).toBe(0)
    })

    /*
     * ⭐ কলামের টিক কেবল নিজের কলাম — আর `manage` ঘরটা ছোঁয় না।
     *
     * ⓘ `manage` তিন কলাম জুড়ে বসে (যোগ · সম্পাদনা · মুছে)। ⛔ "সব
     * দেখা" চেপে কেউ যেন নীরবে **মোছার** ক্ষমতা না পেয়ে যান।
     */
    it('কলামের টিকে কেবল ঐ কলাম বসে, manage নয়', () => {
        const view = root.querySelector('[data-permission-column="view"]')

        view.checked = true
        fire(view)

        expect(boxes('input[data-permission-cell="view"]:checked')).toHaveLength(2)
        expect(boxes('input[data-permission-cell="create"]:checked')).toHaveLength(0)
        expect(root.querySelector('[data-permission-cell="manage"]').checked).toBe(false)
    })

    /*
     * ⭐ আর গোনাটা জীবন্ত — এই জোড়াটা ভুলে যাওয়া সবচেয়ে সহজ।
     *
     * ⛔ ব্যাজটা সার্ভারের পুরনো সংখ্যা ধরে বসে থাকলে মানুষ ভাবতেন টিক
     * কাজ করেনি, আবার চাপতেন, আর **সব তুলে** নিতেন।
     */
    it('ব্যাজের গোনা সাথে সাথে বদলায়', () => {
        const all = root.querySelector('[data-permission-module="sales"] [data-permission-all]')
        const badge = root.querySelector('[data-permission-count]')

        expect(badge.textContent.trim()).toBe('1 / 5')

        all.checked = true
        fire(all)

        expect(badge.textContent.trim()).toBe('5 / 5')

        all.checked = false
        fire(all)

        expect(badge.textContent.trim()).toBe('0 / 5')
    })

    /*
     * ⓘ একটা ঘর হাতে টিক দিলেও গোনা বদলায় — শুধু "সব" বোতামে নয়।
     * ⚠️ নাহলে ব্যাজটা কেবল bulk টিকে ঠিক থাকত, আর রোজকার এক-এক
     * করে টিক দেওয়ায় মিথ্যা বলত।
     */
    it('একটা ঘর হাতে টিক দিলেও গোনা বদলায়', () => {
        const cell = root.querySelector('[value="sales.order.create"]')
        const badge = root.querySelector('[data-permission-count]')

        cell.checked = true
        fire(cell)

        expect(badge.textContent.trim()).toBe('2 / 5')
    })

    /*
     * ⭐ আর মডিউলের টিকটা নিজেই অবস্থা দেখায় — আংশিক হলে অর্ধেক।
     *
     * ⓘ নাহলে অর্ধেক ভরা মডিউলে টিকটা খালি দেখাত, আর একবার চাপলেই
     * সব বসে যেত — মানুষ যা চাননি।
     */
    it('আংশিক হলে মডিউলের টিক অর্ধেক অবস্থায় থাকে', () => {
        const all = root.querySelector('[data-permission-module="sales"] [data-permission-all]')
        const cell = root.querySelector('[value="sales.order.create"]')

        cell.checked = true
        fire(cell)

        expect(all.indeterminate).toBe(true)
        expect(all.checked).toBe(false)
    })

    /*
     * ⭐ ভাগের টিক — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ এই দুইটা দাবি ছাড়া নতুন কোডটার কোনো পাহারা নেই: ১১২টা পুরনো
     * দাবি সবুজ ছিল, আর তাদের একটাও এই লাইনগুলো ছোঁয়নি।
     */
    it('ভাগের টিক ঐ ভাগের সব ঘর বসায়', () => {
        const section = root.querySelector('[data-permission-section="transactions"]')
        const all = section.querySelector('[data-permission-section-all]')

        all.checked = true
        fire(all)

        for (const cell of section.querySelectorAll('input[name="permissions[]"]')) {
            expect(cell.checked).toBe(true)
        }
    })

    /*
     * ⛔ আর এটাই দামি দাবিটা — ভাগের টিক **নিজের ভাগেই** থামে।
     *
     * ⚠️ সীমাটা না থাকলে "রিপোর্টের সব" চাপলে গোটা মডিউলের সব টিক পড়ত।
     * ⓘ অর্থাৎ বোতামটা যা লেখা আছে তার চেয়ে অনেক বেশি করত — আর
     * অনুমতির পর্দায় ওটাই সবচেয়ে বিপজ্জনক ভুল, কারণ ফলটা নীরব:
     * মানুষ পায় দরকারের চেয়ে বেশি অধিকার, আর পর্দা ঠিকই দেখায়।
     */
    it('ভাগের টিক অন্য ভাগে ছড়ায় না', () => {
        const reports = root.querySelector('[data-permission-section="reports"]')
        const other = root.querySelector('[value="sales.invoice.create"]')
        const all = reports.querySelector('[data-permission-section-all]')

        all.checked = true
        fire(all)

        expect(root.querySelector('[value="sales.scheme.manage"]').checked).toBe(true)
        expect(other.checked).toBe(false)
    })
})

/*
 * ⭐ গোটা পর্দার বোতাম আর ছকে খোঁজা — মালিকের স্পেক §২.৫,
 * ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ আলাদা একটা ছাঁচ, উপরেরটার সাথে মেশানো নয়: এখানে সারিগুলোর
 * `data-permission-text` লাগে আর দুইটা মডিউলেই `view` ঘর লাগে —
 * উপরের ছাঁচটা বদলালে ওখানকার আটটা দাবির মানে ঘুরে যেত।
 */
const SCREEN = `
<form>
  <button type="button" data-permission-bulk="all">সব</button>
  <button type="button" data-permission-bulk="none">কিছুই না</button>
  <button type="button" data-permission-bulk="view">শুধু দেখা</button>
  <input type="search" data-permission-search>
  <p data-permission-empty hidden>কিছু মেলেনি</p>

  <details data-permission-module="sales">
    <summary>
      <span data-permission-count data-permission-total="3" data-permission-label=":on / :all">0 / 3</span>
    </summary>
    <table>
      <tbody data-permission-section="transactions">
        <tr data-permission-section-head><th>লেনদেন</th></tr>
        <tr data-permission-row data-permission-text="বিক্রয় বিল sales.invoice.view sales.invoice.approve">
          <td><input type="checkbox" name="permissions[]" value="sales.invoice.view" data-permission-cell="view"></td>
          <td><input type="checkbox" name="permissions[]" value="sales.invoice.approve"></td>
        </tr>
      </tbody>
      <tbody data-permission-section="reports">
        <tr data-permission-section-head><th>রিপোর্ট</th></tr>
        <tr data-permission-row data-permission-text="বিক্রয় স্কিম sales.scheme.manage">
          <td><input type="checkbox" name="permissions[]" value="sales.scheme.manage" data-permission-cell="manage"></td>
        </tr>
      </tbody>
    </table>
  </details>

  <details data-permission-module="purchase">
    <summary><span data-permission-count data-permission-total="1" data-permission-label=":on / :all">0 / 1</span></summary>
    <table>
      <tbody data-permission-section="transactions">
        <tr data-permission-section-head><th>লেনদেন</th></tr>
        <tr data-permission-row data-permission-text="ক্রয় বিল purchase.bill.view">
          <td><input type="checkbox" name="permissions[]" value="purchase.bill.view" data-permission-cell="view"></td>
        </tr>
      </tbody>
    </table>
  </details>
</form>`

const press = (mode) => root.querySelector(`[data-permission-bulk="${mode}"]`)
    .dispatchEvent(new window.Event('click', { bubbles: true }))

function type (term) {
    const field = root.querySelector('[data-permission-search]')

    field.value = term
    field.dispatchEvent(new window.Event('input', { bubbles: true }))
}

describe('গোটা পর্দার বোতাম', () => {
    beforeEach(() => mount(SCREEN))

    it('"সব" চাপলে প্রতিটা মডিউলের প্রতিটা ঘর বসে', () => {
        press('all')

        expect(boxes('input[name="permissions[]"]:checked')).toHaveLength(4)
    })

    it('"কিছুই না" চাপলে সব তুলে যায়', () => {
        press('all')
        press('none')

        expect(boxes('input[name="permissions[]"]:checked')).toHaveLength(0)
    })

    /*
     * ⛔ আর এটাই দামি দাবিটা।
     *
     * ⚠️ "শুধু দেখা" যদি `manage` ঘরটাও বসাত, বোতামটা তার নামের
     * **উল্টো** কাজ করত: ⓘ `manage` মানে তৈরি · সম্পাদনা · **মোছা**।
     * ⛔ ব্যবহারকারী "শুধু দেখা" পড়ে ক্লিক করতেন আর নীরবে মোছার
     * অধিকার দিয়ে ফেলতেন — আর পর্দা ঠিকই দেখাত।
     */
    it('"শুধু দেখা" কেবল দেখার ঘর বসায় — manage বা অনুমোদন নয়', () => {
        press('view')

        expect(root.querySelector('[value="sales.invoice.view"]').checked).toBe(true)
        expect(root.querySelector('[value="purchase.bill.view"]').checked).toBe(true)
        expect(root.querySelector('[value="sales.scheme.manage"]').checked).toBe(false)
        expect(root.querySelector('[value="sales.invoice.approve"]').checked).toBe(false)
    })

    /*
     * ⓘ ভাঁজ করা মডিউলের ব্যাজও নতুন করে লেখা হয়।
     *
     * ⛔ নাহলে "সব" চাপার পর ব্যাজে `0 / 1` বসে থাকত, আর ব্যবহারকারী
     * ধরে নিতেন ঐ মডিউলে কিছুই বসেনি।
     */
    it('ভাঁজ করা মডিউলের গোনাও বদলায়', () => {
        press('all')

        const badges = boxes('[data-permission-count]').map((b) => b.textContent.trim())

        expect(badges).toEqual(['3 / 3', '1 / 1'])
    })
})

describe('ছকে খোঁজা', () => {
    beforeEach(() => mount(SCREEN))

    /*
     * ⚠️ খোঁজার শব্দটা `purchase.bill`, "ক্রয়" নয় — আর কারণটা মনে
     * রাখার মতো: ⛔ **"ক্রয়" শব্দটা "বিক্রয়"-এর ভিতরেই আছে**, তাই
     * ওটা দিয়ে খুঁজলে দুইটা সারিই সত্যি সত্যিই মেলে।
     *
     * ⓘ প্রথম খসড়ায় দাবিটা ঐ শব্দেই লেখা ছিল আর লাল হয়েছিল — কোডের
     * দোষে নয়, দাবিটার দোষে। ⭐ একটা দাবি যা ভুল কারণে লাল হয়, সে
     * একদিন ভুল কারণে সবুজও হবে।
     */
    it('যে সারি মেলে না সেটা আড়ালে যায়', () => {
        type('purchase.bill')

        expect(root.querySelector('[data-permission-text*="purchase.bill"]').hidden).toBe(false)
        expect(root.querySelector('[data-permission-text*="sales.invoice"]').hidden).toBe(true)
    })

    /*
     * ⭐ কাঁচা নামেও মেলে — সাপোর্টের কেউ ঐ নামটাই জানেন।
     *
     * ⚠️ পর্দায় `sales.invoice.approve` লেখাটা কোথাও দেখা যায় না, তাই
     * কেবল চোখে দেখা লেখায় খুঁজলে এই খোঁজাটা কিছুই ফেরাত না।
     */
    it('কাঁচা অনুমতির নাম লিখেও মেলে', () => {
        type('sales.invoice.approve')

        expect(root.querySelector('[data-permission-text*="বিক্রয় বিল"]').hidden).toBe(false)
        expect(root.querySelector('[data-permission-module="purchase"]').hidden).toBe(true)
    })

    /*
     * ⛔ খালি ভাগের মাথাও আড়ালে যায়।
     *
     * ⚠️ নাহলে খোঁজার পর ছকে কেবল "লেনদেন" · "রিপোর্ট" মাথাগুলো পড়ে
     * থাকত, আর দেখে মনে হত কিছু একটা মিলেছে।
     */
    it('যে ভাগে কিছু মেলেনি তার মাথাও আড়ালে যায়', () => {
        type('স্কিম')

        const sales = root.querySelector('[data-permission-module="sales"]')
        const heads = [...sales.querySelectorAll('[data-permission-section-head]')]

        expect(heads.map((h) => h.hidden)).toEqual([true, false])
    })

    /*
     * ⚠️ খোঁজা একটা চশমা, কাঁচি নয় — আড়ালে যাওয়া সারির টিক থাকে।
     *
     * ⛔ এটা না মাপলে একদিন কেউ "আড়াল" মানে "বাদ" ধরে নিয়ে কোডটা
     * বদলাত, আর খোঁজার পর সংরক্ষণ করলে **বাকি সব অনুমতি নীরবে চলে
     * যেত** — ঠিক যে ভুলটা পর্দায় দেখা যায় না।
     */
    it('আড়াল করা সারির টিক অক্ষত থাকে', () => {
        press('all')
        type('ক্রয়')

        expect(root.querySelector('[value="sales.invoice.view"]').checked).toBe(true)
        expect(boxes('input[name="permissions[]"]:checked')).toHaveLength(4)
    })

    it('খোঁজা মুছে দিলে সব আবার ফেরে', () => {
        type('ক্রয়')
        type('')

        expect(boxes('[data-permission-row][hidden]')).toHaveLength(0)
        expect(boxes('[data-permission-module][hidden]')).toHaveLength(0)
    })

    it('কিছুই না মিললে বার্তাটা দেখা যায়', () => {
        const empty = root.querySelector('[data-permission-empty]')

        expect(empty.hidden).toBe(true)

        type('এমন কিছু নেই')

        expect(empty.hidden).toBe(false)
    })
})
