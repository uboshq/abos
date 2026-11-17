import { describe, expect, it } from 'vitest'
import { detectCorners, enhance, LOOKS, orderCorners, solvePerspective, suggestSize, warp } from './scan.js'

/*
 * বাঁকা কাগজ সোজা করার অঙ্ক।
 *
 * ── এই ফাইলটা কেন আছে ───────────────────────────────────────────────
 * এখানকার ভুল কোনো ত্রুটিবার্তা দেয় না। ছবিটা বের হয় — একটু বেঁকে,
 * একটু ঝাপসা, বা আয়নার মতো উল্টো। ⚠️ ব্যবহারকারী ভাবেন ক্যামেরাটা
 * খারাপ, আর মাসখানেক পরে কেউ বলে "স্ক্যানটা কাজ করে না"।
 *
 * ⭐ তাই প্রতিটা দাবি এমনভাবে লেখা যাতে **ভুল হলে লাল হয়**, কেবল
 * "কিছু একটা ফিরেছে" দেখে সবুজ না হয়।
 */

/** একটা বানানো ছবি: সাদা পটভূমি, ভিতরে একটা রঙিন চতুর্ভুজ। */
function canvas(width, height, fill = [255, 255, 255]) {
    const data = new Uint8ClampedArray(width * height * 4)

    for (let i = 0; i < width * height; i++) {
        data[i * 4] = fill[0]
        data[i * 4 + 1] = fill[1]
        data[i * 4 + 2] = fill[2]
        data[i * 4 + 3] = 255
    }

    return { data, width, height }
}

function paint(image, x, y, colour) {
    const at = (Math.round(y) * image.width + Math.round(x)) * 4
    image.data[at] = colour[0]
    image.data[at + 1] = colour[1]
    image.data[at + 2] = colour[2]
    image.data[at + 3] = 255
}

function pixel(image, x, y) {
    const at = (Math.round(y) * image.width + Math.round(x)) * 4

    return [image.data[at], image.data[at + 1], image.data[at + 2]]
}

describe('কোণের ক্রম', () => {
    it('যে ক্রমেই টানা হোক, উপর-বাম আগে আর নিচ-বাম শেষে বসে', () => {
        const scrambled = [
            { x: 90, y: 95 }, // নিচ-ডান
            { x: 12, y: 90 }, // নিচ-বাম
            { x: 95, y: 10 }, // উপর-ডান
            { x: 10, y: 8 }, // উপর-বাম
        ]

        expect(orderCorners(scrambled)).toEqual([
            { x: 10, y: 8 },
            { x: 95, y: 10 },
            { x: 90, y: 95 },
            { x: 12, y: 90 },
        ])
    })

    it('চারটা ছাড়া কিছু দিলে না বলে — তিনটা কোণে কাগজ হয় না', () => {
        expect(orderCorners([{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 0, y: 1 }])).toBeNull()
        expect(orderCorners(null)).toBeNull()
    })
})

describe('ফলাফলের মাপ', () => {
    it('চওড়া দুই বাহুর মধ্যে বড়টা নেয়, ছোটটা নয়', () => {
        // নিচের বাহু ২০০, উপরেরটা ১০০ — ক্যামেরা থেকে দূরের দিকটা ছোট দেখাচ্ছে
        const size = suggestSize([
            { x: 50, y: 0 },
            { x: 150, y: 0 },
            { x: 200, y: 300 },
            { x: 0, y: 300 },
        ])

        expect(size.width).toBe(200)
    })

    it('লম্বা বাহু সীমার বেশি হলে দুইটাই একসাথে নামে — অনুপাত অক্ষত', () => {
        const size = suggestSize(
            [
                { x: 0, y: 0 },
                { x: 4000, y: 0 },
                { x: 4000, y: 2000 },
                { x: 0, y: 2000 },
            ],
            2000,
        )

        expect(size).toEqual({ width: 2000, height: 1000 })
    })
})

describe('সহগ বের করা', () => {
    it('চার কোণ ঠিক আয়তক্ষেত্র হলে রূপান্তরটা নিছক সরানো-টানা', () => {
        const c = solvePerspective(
            [
                { x: 0, y: 0 },
                { x: 100, y: 0 },
                { x: 100, y: 50 },
                { x: 0, y: 50 },
            ],
            100,
            50,
        )

        // বাঁকা নয়, তাই ভাগের অংশ দুইটা শূন্য হওয়া চাই
        expect(c.g).toBeCloseTo(0, 10)
        expect(c.h).toBeCloseTo(0, 10)
        expect(c.a).toBeCloseTo(1, 10)
        expect(c.e).toBeCloseTo(1, 10)
    })

    it('সহগগুলো চার কোণকে সত্যিই চার কোণে পাঠায়', () => {
        const corners = [
            { x: 32, y: 11 },
            { x: 260, y: 40 },
            { x: 240, y: 190 },
            { x: 10, y: 150 },
        ]
        const c = solvePerspective(corners, 200, 100)

        const map = (u, v) => ({
            x: (c.a * u + c.b * v + c.c) / (c.g * u + c.h * v + 1),
            y: (c.d * u + c.e * v + c.f) / (c.g * u + c.h * v + 1),
        })

        const expected = [map(0, 0), map(200, 0), map(200, 100), map(0, 100)]

        expected.forEach((point, i) => {
            expect(point.x).toBeCloseTo(corners[i].x, 6)
            expect(point.y).toBeCloseTo(corners[i].y, 6)
        })
    })

    it('তিন কোণ এক রেখায় পড়লে সমাধান নেই — null, ভাঙা সংখ্যা নয়', () => {
        expect(
            solvePerspective(
                [
                    { x: 0, y: 0 },
                    { x: 50, y: 0 },
                    { x: 100, y: 0 },
                    { x: 0, y: 80 },
                ],
                100,
                80,
            ),
        ).toBeNull()
    })
})

describe('সোজা করা', () => {
    it('বাঁকা চতুর্ভুজের ভিতরের দাগ সোজা ছবির ঠিক জায়গায় পৌঁছায়', () => {
        const image = canvas(200, 200)

        /*
         * ⭐ আসল দাবি: মূল ছবিতে একটা বিন্দু কাগজের ঠিক মাঝখানে বসানো
         * হলো, আর ফলাফলে ওটা মাঝখানেই থাকা চাই।
         *
         * ⚠️ এটাই সেই পরীক্ষা যেটা "ছবি বের হয়েছে" আর "ছবিটা সঠিক"
         * দুইটার পার্থক্য ধরে।
         */
        const corners = [
            { x: 20, y: 30 },
            { x: 170, y: 10 },
            { x: 180, y: 160 },
            { x: 30, y: 180 },
        ]

        const middle = {
            x: (20 + 170 + 180 + 30) / 4,
            y: (30 + 10 + 160 + 180) / 4,
        }

        // মাঝবিন্দুর চারপাশে একটা মোটা লাল ফোঁটা, যাতে নমুনা নিলে ধরা পড়ে
        for (let dy = -3; dy <= 3; dy++) {
            for (let dx = -3; dx <= 3; dx++) {
                paint(image, middle.x + dx, middle.y + dy, [220, 20, 20])
            }
        }

        const out = warp(image, corners, 100, 100)

        expect(out.width).toBe(100)
        expect(out.height).toBe(100)

        const [r, g, b] = pixel(out, 50, 50)

        expect(r).toBeGreaterThan(150)
        expect(g).toBeLessThan(120)
        expect(b).toBeLessThan(120)
    })

    it('কাগজের বাইরের অংশ সাদা হয়, কালো নয়', () => {
        const image = canvas(100, 100, [30, 30, 30])

        // ইচ্ছাকৃতভাবে ছবির সীমার বাইরে টানা কোণ
        const out = warp(
            image,
            [
                { x: -60, y: -60 },
                { x: 40, y: -60 },
                { x: 40, y: 40 },
                { x: -60, y: 40 },
            ],
            50,
            50,
        )

        expect(pixel(out, 2, 2)).toEqual([255, 255, 255])
    })

    it('প্রতিটা ঘর ভরে — একটাও স্বচ্ছ বিন্দু থাকে না', () => {
        const image = canvas(80, 80, [10, 120, 200])
        const out = warp(
            image,
            [
                { x: 5, y: 6 },
                { x: 70, y: 3 },
                { x: 74, y: 66 },
                { x: 8, y: 72 },
            ],
            64,
            64,
        )

        let transparent = 0

        for (let i = 3; i < out.data.length; i += 4) {
            if (out.data[i] !== 255) transparent++
        }

        expect(transparent).toBe(0)
    })

    it('সমাধান না থাকলে null — অর্ধেক আঁকা ছবি নয়', () => {
        const image = canvas(40, 40)

        expect(
            warp(
                image,
                [
                    { x: 0, y: 0 },
                    { x: 10, y: 0 },
                    { x: 20, y: 0 },
                    { x: 0, y: 30 },
                ],
                20,
                20,
            ),
        ).toBeNull()
    })
})

describe('কাগজ খুঁজে বের করা', () => {
    /** ধূসর টেবিলের উপর একটা সাদা কাগজ। */
    function onATable(x0, y0, x1, y1, width = 300, height = 400) {
        const image = canvas(width, height, [110, 112, 116])

        for (let y = y0; y <= y1; y++) {
            for (let x = x0; x <= x1; x++) {
                paint(image, x, y, [246, 244, 240])
            }
        }

        return image
    }

    it('ধূসর টেবিলের উপর সাদা কাগজের চার কোণ পাওয়া যায়', () => {
        const corners = detectCorners(onATable(40, 60, 260, 340))

        expect(corners).not.toBeNull()

        const [tl, , br] = corners

        expect(tl.x).toBeLessThan(55)
        expect(tl.y).toBeLessThan(75)
        expect(br.x).toBeGreaterThan(245)
        expect(br.y).toBeGreaterThan(325)
    })

    it('এক রঙা ছবিতে কিছু খুঁজে পাওয়ার ভান করে না', () => {
        expect(detectCorners(canvas(200, 200, [200, 200, 200]))).toBeNull()
    })

    it('কাগজ প্রায় পুরো ছবি জুড়ে থাকলে না বলে — কাটার কিছু নেই', () => {
        expect(detectCorners(onATable(1, 1, 298, 398))).toBeNull()
    })

    it('সরু ফালি চতুর্ভুজ বলে চালানো হয় না', () => {
        expect(detectCorners(onATable(140, 5, 152, 395))).toBeNull()
    })
})

/*
 * ⭐ কাগজের চেহারা (মালিক, ১৬ নভেম্বর ২০২৬: "ক্যাম স্ক্যানারে যে কাজগুলো হয় … স্পষ্ট করা") — ছায়া যায়, কাগজ সাদা,
 *   লেখা কালো, রঙিন সিল বাঁচে; সাদা-কালোয় কেবল দুই রং।
 */
describe('enhance — কাগজের চেহারা', () => {
    /** বাঁ অর্ধেক আলোয় (240), ডান অর্ধেক ছায়ায় (150); দুই দিকেই একটা কালির দাগ */
    function shadowed() {
        const image = canvas(200, 100, [240, 240, 240])

        for (let y = 0; y < 100; y++) {
            for (let x = 100; x < 200; x++) {
                paint(image, x, y, [150, 150, 150])
            }
        }

        for (let y = 45; y < 50; y++) {
            for (let x = 30; x < 70; x++) paint(image, x, y, [40, 40, 40])
            for (let x = 130; x < 170; x++) paint(image, x, y, [25, 25, 25])
        }

        return image
    }

    const at = (image, x, y) => Array.from(image.data.slice((y * image.width + x) * 4, (y * image.width + x) * 4 + 3))

    it('পরিষ্কার: ছায়ার কাগজও সাদা, দুই দিকের লেখা কালো', () => {
        const out = enhance(shadowed(), 'clean')

        expect(at(out, 10, 10)[0]).toBeGreaterThanOrEqual(245)
        expect(at(out, 190, 10)[0]).toBeGreaterThanOrEqual(245)
        expect(at(out, 50, 47)[0]).toBeLessThanOrEqual(80)
        expect(at(out, 150, 47)[0]).toBeLessThanOrEqual(80)
    })

    it('সাদা-কালো: কেবল 0 আর 255 — ছায়ার কাগজ সাদা, কালি কালো', () => {
        const out = enhance(shadowed(), 'bw')
        const values = new Set()

        for (let i = 0; i < out.data.length; i += 4) values.add(out.data[i])

        expect([...values].sort((a, b) => a - b)).toEqual([0, 255])
        expect(at(out, 190, 10)).toEqual([255, 255, 255])
        expect(at(out, 150, 47)).toEqual([0, 0, 0])
    })

    it('ধূসর: তিন রং সমান', () => {
        const image = shadowed()
        paint(image, 20, 20, [200, 30, 30])
        const [r, g, b] = at(enhance(image, 'grey'), 20, 20)

        expect(r).toBe(g)
        expect(g).toBe(b)
    })

    it('পরিষ্কারে লাল সিল লালই থাকে', () => {
        const image = shadowed()
        for (let y = 20; y < 26; y++) for (let x = 20; x < 26; x++) paint(image, x, y, [200, 30, 30])
        const [r, g] = at(enhance(image, 'clean'), 22, 22)

        expect(r).toBeGreaterThan(g * 2)
    })

    it('মোটা শিরোনাম (পুরো ঘর কালিতে ঢাকা) মুছে সাদা হয় না', () => {
        const image = canvas(400, 400, [235, 235, 235])
        for (let y = 100; y < 160; y++) for (let x = 100; x < 300; x++) paint(image, x, y, [30, 30, 30])

        expect(at(enhance(image, 'clean'), 200, 130)[0]).toBeLessThanOrEqual(60)
    })

    it('আসল রং: কিছুই বদলায় না; অচেনা নাম আসল রঙের মতো', () => {
        const image = shadowed()

        expect(Array.from(enhance(image, 'colour').data)).toEqual(Array.from(image.data))
        expect(Array.from(enhance(image, 'sepia').data)).toEqual(Array.from(image.data))
        expect(LOOKS).toEqual(['clean', 'colour', 'grey', 'bw'])
    })
})

/* ⭐ ১৭ নভেম্বর ২০২৬ — ফোনের ছবিতে ধরা দুই ভুল: কাঠের টেবিলে কোণ মেলেনি, আর ছায়ার ঢালে কাগজ ধূসর থেকে গিয়েছিল */
describe('ফোনের মতো ছবি — রঙিন ডোরাকাটা টেবিল, ছায়ার ঢাল', () => {
    it('কাঠের ডোরাকাটা টেবিলে সাদা কাগজের কোণ পাওয়া যায়, পুরো ছবি নয়', () => {
        const image = canvas(400, 500, [120, 84, 52])

        // কাঠের ডোরা — গাঢ়-হালকা বাদামি সারি
        for (let y = 0; y < 500; y += 6) {
            for (let x = 0; x < 400; x++) paint(image, x, y, [150, 105, 66])
        }

        // কাগজ ১০০..৩০০ × ১০০..৪০০, ডান দিকে ছায়ার ঢাল (কাগজ রংহীনই থাকে)
        for (let y = 100; y < 400; y++) {
            for (let x = 100; x < 300; x++) {
                const v = Math.round(245 - (x - 100) * 0.6)
                paint(image, x, y, [v, v, v - 4])
            }
        }

        const corners = detectCorners(image)

        expect(corners).not.toBeNull()
        const [tl, , br] = corners
        expect(Math.abs(tl.x - 100)).toBeLessThanOrEqual(6)
        expect(Math.abs(tl.y - 100)).toBeLessThanOrEqual(6)
        expect(Math.abs(br.x - 298)).toBeLessThanOrEqual(6)
        expect(Math.abs(br.y - 398)).toBeLessThanOrEqual(6)
    })

    it('ছায়ার ঢালে (ফোনের ছবির মতো খাড়া — ৬০ বিন্দুতে ২৪০ থেকে ১১০) পুরো কাগজ সাদা হয়', () => {
        const image = canvas(600, 300, [240, 240, 240])

        for (let y = 0; y < 300; y++) {
            for (let x = 0; x < 600; x++) {
                const v = Math.round(240 - Math.min(130, Math.max(0, x - 200) * (130 / 60)))
                paint(image, x, y, [v, v, v])
            }
        }

        const out = enhance(image, 'clean')
        let darkest = 255

        for (let i = 0; i < out.data.length; i += 4) darkest = Math.min(darkest, out.data[i])

        expect(darkest).toBeGreaterThanOrEqual(235)
    })
})
