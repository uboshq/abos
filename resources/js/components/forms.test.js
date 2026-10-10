// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { filePick, imagePreview } from './forms.js'

/*
 * বিলের লোগো বাছলেই দেখা — মালিক, ৩ অক্টোবর ২০২৬: *"upload hole ekhane dekhar bebosta koro"*।
 * ⓘ সার্ভারে কিছু যায় না; ব্রাউজারের অস্থায়ী ঠিকানা, আর বাছাই মুছলে আগের সংরক্ষিত ছবিতে ফেরে।
 */
describe('imagePreview', () => {
    afterEach(() => vi.restoreAllMocks())

    const change = (files) => ({ target: { files } })

    it('shows the chosen picture before saving, and goes back when the choice is cleared', () => {
        const made = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:one')
        const freed = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
        const box = imagePreview({ current: '/storage/invoice-logos/old.png' })

        expect(box.picked).toBe(false)
        expect(box.url).toBe('/storage/invoice-logos/old.png')

        box.pick(change([new File(['x'], 'logo.png', { type: 'image/png' })]))
        expect(made).toHaveBeenCalledOnce()
        expect(box.picked).toBe(true)
        expect(box.url).toBe('blob:one')

        box.pick(change([]))
        expect(freed).toHaveBeenCalledWith('blob:one')
        expect(box.picked).toBe(false)
        expect(box.url).toBe('/storage/invoice-logos/old.png')
    })
})

/*
 * ⭐ ফাইল তোলার বাংলা বোতাম — পাতা সাজানো ধাপ ১, ১০ অক্টোবর ২০২৬। ⓘ ব্রাউজারের "No file chosen"-এর জায়গায় নিজের লেখা,
 * একটা ফাইলে তার নাম, কয়েকটায় সংখ্যা, মুছলে আবার "কিছু বাছা হয়নি"।
 */
describe('filePick', () => {
    const change = (files) => ({ target: { files } })

    it('says nothing is chosen, then the file name, then how many, then nothing again', () => {
        const box = filePick({ none: 'কোনো ফাইল বাছা হয়নি', many: ':count টা ফাইল' })

        expect(box.label).toBe('কোনো ফাইল বাছা হয়নি')
        expect(box.picked).toBe(false)

        box.pick(change([new File(['x'], 'চালান.pdf')]))
        expect(box.label).toBe('চালান.pdf')
        expect(box.picked).toBe(true)

        box.pick(change([new File(['x'], 'a.png'), new File(['y'], 'b.png'), new File(['z'], 'c.png')]))
        expect(box.label).toBe('3 টা ফাইল')

        box.pick(change([]))
        expect(box.label).toBe('কোনো ফাইল বাছা হয়নি')
        expect(box.picked).toBe(false)
    })
})
