/*
 * ⭐ টাকা নেওয়ার পর্দার যোগ-বিয়োগ — [[collection.js]]-এর সহায়ক, আলাদা ফাইলে।
 *
 * ⚠️ কেন আলাদা: [[index.js]] নিবন্ধিত মডিউলের প্রতিটা রপ্তানি করা ফাংশন Alpine.data হিসেবে বসায়; এগুলো সেখানে থাকায় cb-র
 * money-method.test.js-এর চার্জের লাইন আর দেখা যাচ্ছিল না (a6, ২১ অক্টোবর ২০২৬)। পর্দার কম্পোনেন্ট কেবল `collectionForm`।
 */
import { taka } from './money.js'

const CREDIT_KINDS = ['credit_note', 'credit']

/** "1,234.50" বা "" → সংখ্যা; ভুল লেখা ০ */
export function amountOf (value) {
    const n = Number(String(value ?? '').replace(/,/g, '').trim())

    return Number.isFinite(n) ? n : 0
}

/** পয়সায় গোল — ভাসমান বিন্দুর ০.০০০০১ ভুল যেন "মেলেনি" না বলে */
export function cents (n) {
    return Math.round(n * 100) / 100
}

/** "2026-10-02" → "02-10" (নকশার তারিখের ঘর); না থাকলে "—" */
export function shortDate (iso) {
    const text = String(iso ?? '')

    return text.length >= 10 ? text.slice(8, 10) + '-' + text.slice(5, 7) : '—'
}

/** সার্ভারের খোলা কাগজ → টেবিলের সারি */
export function rowOf (item, words = {}) {
    const open = amountOf(item.open)
    const late = item.overdue_days === null || item.overdue_days === undefined ? null : Number(item.overdue_days)

    return {
        id: String(item.id),
        kind: String(item.kind),
        no: String(item.no ?? ''),
        url: String(item.url ?? '#'),
        date: shortDate(item.date),
        dueDate: shortDate(item.due_date),
        late: late === null || late <= 0 ? '—' : late + ' ' + (words.days ?? ''),
        lateOn: late !== null && late > 0,
        amountText: taka(amountOf(item.amount)),
        openText: taka(open),
        open,
        credit: CREDIT_KINDS.includes(String(item.kind)),
        chip: (words.chips ?? {})[item.kind] ?? '',
        on: false,
        apply: '',
    }
}

/**
 * ⭐ পুরনো আগে — দফা ৩। ক্রেডিট (ঋণাত্মক) বিল ঢাকে, বাকিটা টাকা থেকে; বিলের মোটের বেশি ক্রেডিট বসে না (সার্ভারও ফেরায়)।
 *
 * @param rows   টেবিলের সারি, পুরনো আগে
 * @param gross  গ্রাহকের পাওনা থেকে যা কমবে — পাওয়া টাকা + VDS + AIT + ছাড়/রাউন্ডিং
 */
export function fillOldestFirst (rows, gross) {
    const credits = rows.filter(r => r.credit)
    const owed = rows.filter(r => !r.credit)
    let pool = cents(Math.max(0, gross) + credits.reduce((s, r) => s + Math.abs(r.open), 0))
    let bills = 0

    for (const row of owed) {
        const take = cents(Math.min(pool, Math.max(0, row.open)))

        row.on = take > 0
        row.apply = take > 0 ? take.toFixed(2) : ''
        pool = cents(pool - take)
        bills = cents(bills + (row.kind === 'bill' ? take : 0))
    }

    for (const row of credits) {
        const take = cents(Math.min(bills, Math.abs(row.open)))

        row.on = take > 0
        row.apply = take > 0 ? (-take).toFixed(2) : ''
        bills = cents(bills - take)
    }

    return rows
}

/**
 * সারাংশের হিসাব — দফা ৪। প্রয়োগ = বাছা সারির যোগ (ক্রেডিট ঋণাত্মক), অপ্রয়োগকৃত = পাওনা থেকে যা কমবে − প্রয়োগ।
 */
export function balanceOf ({ amount, vds, ait, discount, rounding }, rows) {
    const gross = cents(amountOf(amount) + amountOf(vds) + amountOf(ait) + amountOf(discount) + amountOf(rounding))
    const applied = cents(rows.filter(r => r.on).reduce((s, r) => s + (r.credit ? -Math.abs(amountOf(r.apply)) : amountOf(r.apply)), 0))
    const both = amountOf(discount) > 0 && amountOf(rounding) > 0

    return {
        gross,
        applied,
        unapplied: cents(gross - applied),
        openTotal: cents(rows.reduce((s, r) => s + r.open, 0)),
        both,
        balanced: !both && cents(gross - applied) >= 0 && gross > 0,
    }
}

