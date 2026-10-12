/*
 * দামের ভেতরের ভ্যাট পর্দার মোটে দুইবার নয় — Sales অডিট, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ সার্ভার ঠিক করা হয়েছিল (d1401d93): ভেতরের হারে মোট = ছাড়ের পরের টাকা, ভ্যাট আলাদা খাতায়। ⚠️ পর্দা তবু
 * net + ভ্যাট দেখাত — বিল খুলে আবার দেখলে মোট সার্ভারের চেয়ে বেশি। ⓘ PHP-র দিকটা (তালিকা পর্দায় পৌঁছায় কিনা)
 * [[TheLineEditorKnowsWhichVatIsInsideTest]] মাপে।
 */
import { describe, expect, it } from 'vitest'

import { purchaseLineEditor, salesLineEditor } from './documents.js'

// ⓘ হাতে গোনা: ৫ × ২৩০ = ১,১৫০ − ছাড় ১১৫ = ১,০৩৫; ভ্যাট ১৩৫ ভেতরে → মোট ১,০৩৫
const inside = { product_id: '7', qty: '5', rate: '230', discount: '115', tax: '135' }
// ⓘ বাইরের হার: ২ × ১০০ = ২০০ + ভ্যাট ৩০ → ২৩০
const outside = { product_id: '9', qty: '2', rate: '100', discount: '', tax: '30' }

describe.each([
    ['salesLineEditor', salesLineEditor],
    ['purchaseLineEditor', purchaseLineEditor],
])('%s — ভেতরের ভ্যাট', (_, make) => {
    const editor = (inclusive) => make({ rows: [{ ...inside }, { ...outside }], packs: {}, inclusive })

    it('ভেতরের হারের সারিতে ভ্যাট মোটে আবার যোগ হয় না', () => {
        expect(editor(['7']).amount(inside)).toBe(1035)
    })

    it('বাইরের হারের সারিতে ভ্যাট যোগ হয় — আগের মতোই', () => {
        expect(editor(['7']).amount(outside)).toBe(230)
        expect(editor(['7']).total).toBe(1265)
    })

    // ⓘ সংখ্যায় এলেও মেলে — Blade-এর তালিকা স্ট্রিং, সারির product_id কখনো সংখ্যা
    it('পণ্যের id সংখ্যা হলেও চেনে', () => {
        expect(editor([7]).amount({ ...inside, product_id: 7 })).toBe(1035)
    })

    it('তালিকা না পাঠালে আচরণ আগের মতো — net + ভ্যাট', () => {
        expect(make({ rows: [{ ...inside }], packs: {} }).amount(inside)).toBe(1170)
    })
})
