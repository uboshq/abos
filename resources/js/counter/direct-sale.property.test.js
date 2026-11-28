import { describe, expect, it } from 'vitest'
import directSale from './direct-sale.js'
import { magics } from '../components/index.js'

/*
 * ⭐ কাউন্টারের অঙ্কের property test (owner's quality rule #4, 28 Nov 2026): one property per formula.
 *
 * Each property draws 1,000 random bills from a fixed seed, so a red run can be repeated exactly,
 * and checks the formula against a plain hand calculation written here, not against the code itself.
 *   1. line total     = Σ qty × rate × (1 − line discount %)
 *   2. bill discount  = taka, or % of the subtotal; never below 0, never above the subtotal
 *   3. net payable    = subtotal − bill discount + cost + rounding (no VAT: distributor, owner 28 Nov)
 *   4. credit left    = limit − (previous due + held + (net payable − deposit))
 *   5. fare tile      = vehicle fare + loading (only when loading is cash or due)
 */

const CASES = 1000

/** mulberry32: small, fast, fixed-seed random numbers */
const rng = (seed) => () => {
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
}
const int = (r, lo, hi) => lo + Math.floor(r() * (hi - lo + 1))
const money = (r, lo, hi) => Math.round((lo + r() * (hi - lo)) * 100) / 100

const counter = (texts = {}) => Object.assign(directSale({
    catalogue: [{ id: 1, name: 'চাল', rate: '50', vatRate: 0, vatInclusive: false }],
    customers: [], walkinId: 1, vatEnabled: false, packs: [], paymentTermDefault: 'cash',
    carriers: [], depositMethods: [], moneyAccounts: [], draftKey: 'test.property', hasErrors: false,
    texts,
}), { $num: magics.num, $nextTick: (fn) => fn(), $refs: { search: { focus: () => {} } } })

/** a random cart; returns the hand-calculated subtotal next to it */
const randomCart = (r) => {
    const lines = []
    let sub = 0
    for (let k = 1, n = int(r, 1, 8); k <= n; k++) {
        const qty = int(r, 1, 500)
        const rate = money(r, 0.5, 5000)
        const pct = r() < 0.4 ? int(r, 1, 30) : 0
        lines.push({ key: k, id: 1, qty: String(qty), rate: String(rate), freeQty: '', discountPercent: pct ? String(pct) : '', vatRate: 0, vatInclusive: false, gifts: [] })
        sub += qty * rate * (1 - pct / 100)
    }
    return { lines, sub }
}

const near = (actual, expected) => expect(Math.abs(actual - expected)).toBeLessThanOrEqual(1e-6 * Math.max(1, Math.abs(expected)))

describe('counter arithmetic holds for 1,000 random bills each', () => {
    it('1. line total = Σ qty × rate × (1 − line discount %)', () => {
        const r = rng(20261128)
        for (let i = 0; i < CASES; i++) {
            const c = counter()
            const { lines, sub } = randomCart(r)
            c.lines = lines
            near(c.subTotal, sub)
            near(c.grossTotal, sub)
        }
    })

    it('2. bill discount is taka or % of the subtotal, never below 0 or above the subtotal', () => {
        const r = rng(11281)
        for (let i = 0; i < CASES; i++) {
            const c = counter()
            const { lines, sub } = randomCart(r)
            c.lines = lines
            const percent = r() < 0.5
            const n = percent ? int(r, 0, 120) : money(r, 0, sub * 1.3)
            c.discountInput = percent ? n + '%' : String(n)
            const expected = Math.min(percent ? sub * n / 100 : n, sub)
            near(c.discountValue, Math.max(0, expected))
            expect(c.discountValue).toBeGreaterThanOrEqual(0)
            expect(c.discountValue).toBeLessThanOrEqual(c.subTotal + 1e-9)
        }
    })

    it('3. net payable = subtotal − bill discount + cost + rounding', () => {
        const r = rng(3301)
        for (let i = 0; i < CASES; i++) {
            const c = counter()
            const { lines, sub } = randomCart(r)
            c.lines = lines
            const disc = money(r, 0, sub)
            const cost = r() < 0.5 ? money(r, 0, 2000) : 0
            const round = money(r, 0, 0.99)
            const minus = r() < 0.5
            c.discountInput = String(disc)
            c.expenseInput = String(cost)
            c.roundingInput = String(round)
            c.roundingSign = minus ? '-' : '+'
            near(c.netPayable, sub - disc + cost + (minus ? -round : round))
        }
    })

    it('4. credit left = limit − (previous due + held + (net payable − deposit))', () => {
        const r = rng(4404)
        for (let i = 0; i < CASES; i++) {
            const c = counter()
            const { lines, sub } = randomCart(r)
            c.lines = lines
            const limit = money(r, 0, 2_000_000)
            const due = money(r, 0, 1_500_000)
            const held = r() < 0.3 ? money(r, 0, 200_000) : 0
            const paid = [money(r, 0, sub), r() < 0.3 ? money(r, 0, 50_000) : 0]
            c.customers = { 7: { limit: String(limit), due: String(due), held: String(held), days: 0, name: 'X' } }
            c.customerId = 7
            c.deposits = paid.map((amount) => ({ amount: String(amount) }))
            near(c.creditLeft, limit - (due + held + (sub - (paid[0] + paid[1]))))
        }
    })

    it('5. fare tile = vehicle fare + loading, loading only when cash or due', () => {
        const r = rng(5505)
        const modes = ['cash', 'due', '', 'none']
        for (let i = 0; i < CASES; i++) {
            const c = counter({ fareLoadingSplit: ':fare+:loading', cardNothing: '-' })
            const fare = r() < 0.8 ? money(r, 0, 20_000) : 0
            const loading = money(r, 0, 5_000)
            const mode = modes[int(r, 0, 3)]
            const fields = { 'input[name="loading_mode"]': { value: mode }, 'input[name="loading_amount"]': { value: String(loading) } }
            c.$root = { querySelector: (s) => fields[s] ?? null }
            c.transportCost = String(fare)
            c.noteLoading()
            const counted = ['cash', 'due'].includes(mode) ? loading : 0
            near(c.loadingAmount, counted)
            expect(c.fareTotalText).toBe(fare + counted > 0 ? '৳' + c.money(fare + counted) : '—')
        }
    })
})
