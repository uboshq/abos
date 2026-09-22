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
      <tbody>
        <tr>
          <td><input type="checkbox" name="permissions[]" value="sales.order.view" data-permission-cell="view" checked></td>
          <td><input type="checkbox" name="permissions[]" value="sales.order.create" data-permission-cell="create"></td>
        </tr>
        <tr>
          <td><input type="checkbox" name="permissions[]" value="sales.invoice.view" data-permission-cell="view"></td>
          <td><input type="checkbox" name="permissions[]" value="sales.invoice.create" data-permission-cell="create"></td>
        </tr>
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

beforeEach(() => {
    document.body.innerHTML = MODULE
    root = document.body
    wireActions(root)
})

describe('অনুমতির "সব" টিক', () => {
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
})
