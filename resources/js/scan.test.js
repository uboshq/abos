import { describe, expect, it } from 'vitest'
import { detectCorners, orderCorners, solvePerspective, suggestSize, warp } from './scan.js'

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
