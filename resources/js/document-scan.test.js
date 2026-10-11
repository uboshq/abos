import { describe, expect, it } from 'vitest'
import { hasSimd, languages, ocrPaths, sameOrigin } from './document-scan.js'

/*
 * স্ক্যানের পথ — tesseract.js-এর প্রতিটা ফাইল আমাদের নিজের সার্ভার থেকে (ডকুমেন্ট পরিকল্পনা §৭; ৯ অক্টোবর ২০২৬)।
 *
 * ⛔ মালিকের প্রথম বাঁধন: কোনো কাগজ বা তার লেখা বাইরের সার্ভারে নয়। tesseract.js নিজে CDN থেকে নামাতে চায়;
 * এই পরীক্ষা নিশ্চিত করে যে আমরা প্রতিটা পথ নিজের ঠিকানায় বাঁধি, আর অন্য হোস্ট দিলে কাজটা থামে।
 */
const ORIGIN = 'https://abos.example'

describe('OCR-এর পথ', () => {
    it('worker, core আর ভাষা — তিনটাই নিজের সার্ভারের', () => {
        const paths = ocrPaths('/vendor/tesseract/', ORIGIN, true)

        expect(paths.workerPath).toBe('/vendor/tesseract/worker.min.js')
        expect(paths.corePath).toBe('/vendor/tesseract/core/tesseract-core-simd-lstm.wasm.js')
        expect(paths.langPath).toBe('/vendor/tesseract/lang')
        expect(paths.workerBlobURL).toBe(false)

        for (const path of [paths.workerPath, paths.corePath, paths.langPath]) {
            expect(sameOrigin(path, ORIGIN)).toBe(true)
        }
    })

    it('SIMD না থাকলে সাধারণ core — সেটাও নিজের সার্ভারের', () => {
        expect(ocrPaths('/vendor/tesseract', ORIGIN, false).corePath).toBe('/vendor/tesseract/core/tesseract-core-lstm.wasm.js')
    })

    it('⛔ অন্য হোস্টের পথ দিলে কাজ শুরুই হয় না', () => {
        expect(() => ocrPaths('https://cdn.jsdelivr.net/npm/tesseract.js', ORIGIN, true)).toThrow()
        expect(() => ocrPaths('//evil.example/t', ORIGIN, true)).toThrow()
    })

    it('ভাষা কেবল বাংলা আর ইংরেজি', () => {
        expect(languages('ben+eng')).toEqual(['ben', 'eng'])
        expect(languages('eng')).toEqual(['eng'])
        expect(languages('hin+xyz')).toEqual(['ben', 'eng'])
    })

    it('SIMD-এর খোঁজে ভুল হলে সাধারণ পথ', () => {
        expect(hasSimd({ validate: () => { throw new Error('x') } })).toBe(false)
        expect(hasSimd({ validate: () => true })).toBe(true)
        expect(hasSimd(null)).toBe(false)
    })
})
