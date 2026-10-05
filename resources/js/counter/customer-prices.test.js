import { describe, expect, it, vi, afterEach } from 'vitest'
import { applyPrices, fetchPrices } from './customer-prices.js'

/*
 * গ্রাহক বদলালে দর বদলায় — হাতে লেখা দর ছাড়া (দর তালিকা, ৫ অক্টোবর ২০২৬)।
 */
const book = () => [
    { id: 1, rate: '100' },
    { id: 2, rate: '50' },
]

describe('applyPrices', () => {
    it('puts the customer price on the catalogue with its source', () => {
        const catalogue = book()

        applyPrices(catalogue, [], null, null, { 1: { rate: '92.0000', source: 'customer', label: 'গ্রাহকের দাম' } })

        expect(catalogue[0]).toMatchObject({ rate: '92.0000', priceSource: 'customer', priceLabel: 'গ্রাহকের দাম' })
        expect(catalogue[1].rate).toBe('50')
    })

    it('re-prices a cart line that still carries the automatic rate', () => {
        const lines = [{ id: 1, rate: '100' }]

        const moved = applyPrices(book(), lines, null, null, { 1: { rate: '92', source: 'customer', label: '' } })

        expect(lines[0].rate).toBe('92')
        expect(moved).toBe(1)
    })

    it('leaves a hand-typed rate alone', () => {
        const lines = [{ id: 1, rate: '97' }]

        const moved = applyPrices(book(), lines, null, null, { 1: { rate: '92', source: 'customer', label: '' } })

        expect(lines[0].rate).toBe('97')
        expect(moved).toBe(0)
    })

    it('goes back to the product price when the next customer has no list', () => {
        const catalogue = book()
        const lines = [{ id: 1, rate: '100' }]

        applyPrices(catalogue, lines, null, null, { 1: { rate: '92', source: 'customer', label: '' } })
        applyPrices(catalogue, lines, null, null, { 1: { rate: '100', source: 'standard', label: '' } })

        expect(lines[0].rate).toBe('100')
        expect(catalogue[0].priceSource).toBe('standard')
    })

    it('moves the picked product in the entry box on the same terms', () => {
        const catalogue = book()
        const entry = { rate: '100' }
        const typed = { rate: '99' }

        applyPrices(catalogue, [], entry, catalogue[0], { 1: { rate: '92', source: 'customer', label: '' } })
        expect(entry.rate).toBe('92')

        applyPrices(book(), [], typed, { id: 1 }, { 1: { rate: '92', source: 'customer', label: '' } })
        expect(typed.rate).toBe('99')
    })
})

describe('fetchPrices', () => {
    afterEach(() => vi.unstubAllGlobals())

    it('asks for the chosen customer and returns the price map', async () => {
        const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ prices: { 1: { rate: '92' } } }) }))
        vi.stubGlobal('fetch', fetchMock)

        const prices = await fetchPrices('/sales/price-list/quote', '7')

        expect(fetchMock.mock.calls[0][0]).toBe('/sales/price-list/quote?customer=7')
        expect(prices).toEqual({ 1: { rate: '92' } })
    })

    it('asks nothing without a customer and gives null on a failed answer', async () => {
        const fetchMock = vi.fn(async () => ({ ok: false }))
        vi.stubGlobal('fetch', fetchMock)

        expect(await fetchPrices('/q', '')).toBeNull()
        expect(fetchMock).not.toHaveBeenCalled()
        expect(await fetchPrices('/q', '3')).toBeNull()
    })
})
