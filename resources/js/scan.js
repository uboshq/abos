/*
 * বাঁকা করে তোলা কাগজ সোজা করা — ১৪ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন এই অঙ্কটা ব্রাউজারে, সার্ভারে নয় ─────────────────────────────
 * মালিক চেয়েছেন *"১০০% CamScanner er moto"*। CamScanner-এর আসল কাজটা
 * ছাঁটা নয় — **চার কোণ ধরে টেনে আয়তক্ষেত্র বানানো** (perspective warp)।
 *
 * ⓘ প্রথমে ভেবেছিলাম OpenCV.js লাগবে (~৮MB), আর তার জন্য CSP আলগা করতে
 * হবে। ⛔ দুইটাই ভুল ছিল: অঙ্কটা একটা ৮×৮ রৈখিক সমীকরণ, আর ওটা নিজে
 * লিখলে **কয়েকশো লাইন**। বাইরের কিছু লাগে না, তাই `script-src 'self'`
 * অক্ষত থাকে।
 *
 * ⚠️ আর সার্ভারে না করার কারণ মাপা: PHP-তে প্রতি বিন্দুতে হিসাব করতে
 * হত — ৩০ লক্ষ বিন্দুর জন্য কয়েক কোটি ধাপ। ব্রাউজারের JIT ওটা
 * মিলিসেকেন্ডে করে, আর খরচটা যার ফোন তার, সবার সার্ভারের নয়।
 *
 * ── ⭐ এই ফাইলে কোনো DOM নেই, ইচ্ছাকৃতভাবে ──────────────────────────
 * সব ফাংশন বিশুদ্ধ: সংখ্যা ঢোকে, সংখ্যা বের হয়। ⓘ কারণ এই অঙ্কটাই
 * সবচেয়ে সহজে নীরবে ভুল হয় — এক পিক্সেল সরে গেলে চোখে পড়ে না, কিন্তু
 * লেখা পড়া যায় না। `npm test` দিয়ে প্রমাণ করা যায় বলেই এটা আলাদা।
 * ([[resources/js/pricing.js]]-এর একই ছাঁচ।)
 */

/**
 * চারটা কোণকে সবসময় একই ক্রমে সাজানো: উপর-বাম, উপর-ডান, নিচ-ডান, নিচ-বাম।
 *
 * ⚠️ ছাড়া যায় না: ব্যবহারকারী কোণগুলো যে কোনো ক্রমে টেনে বসাতে পারেন,
 * আর ক্রম উল্টে গেলে সোজা করা ছবিটা **আয়নার মতো উল্টো** বা উপর-নিচ
 * হয়ে বের হত।
 *
 * ⓘ কৌশলটা পুরনো ও নির্ভরযোগ্য: `x+y` সবচেয়ে ছোট মানে উপর-বাম, সবচেয়ে
 * বড় মানে নিচ-ডান; `x−y` দিয়ে বাকি দুইটা।
 */
export function orderCorners(points) {
    if (!Array.isArray(points) || points.length !== 4) {
        return null
    }

    const sum = points.map((p) => p.x + p.y)
    const diff = points.map((p) => p.x - p.y)

    const at = (values, pick) => points[values.indexOf(pick(...values))]

    return [
        at(sum, Math.min),
        at(diff, Math.max),
        at(sum, Math.max),
        at(diff, Math.min),
    ]
}

/**
 * সোজা করা ছবিটা কত বড় হবে — কোণগুলো দেখে।
 *
 * ⓘ চারটা বাহুর দৈর্ঘ্য মাপা হয়; চওড়া = উপর ও নিচের বাহুর মধ্যে বড়টা,
 * উঁচু = বাম ও ডানের মধ্যে বড়টা।
 *
 * ⚠️ ছোটটা নিলে কাগজের যে দিকটা ক্যামেরা থেকে দূরে ছিল সেটার লেখা
 * চেপে যেত — আর ঠিক ঐ দিকটাই সবচেয়ে কম পড়া যায়।
 */
export function suggestSize(corners, maxEdge = 2000) {
    const [tl, tr, br, bl] = corners
    const span = (a, b) => Math.hypot(a.x - b.x, a.y - b.y)

    let width = Math.round(Math.max(span(tl, tr), span(bl, br)))
    let height = Math.round(Math.max(span(tl, bl), span(tr, br)))

    width = Math.max(1, width)
    height = Math.max(1, height)

    const long = Math.max(width, height)

    if (long > maxEdge) {
        const scale = maxEdge / long
        width = Math.max(1, Math.round(width * scale))
        height = Math.max(1, Math.round(height * scale))
    }

    return { width, height }
}

/**
 * সোজা আয়তক্ষেত্র → বাঁকা চতুর্ভুজ, এই রূপান্তরের আটটা সহগ।
 *
 * ── অঙ্কটা কী ────────────────────────────────────────────────────────
 * ফলাফলের প্রতিটা বিন্দু (u,v)-র জন্য মূল ছবির কোন বিন্দু নিতে হবে:
 *
 *     x = (a·u + b·v + c) / (g·u + h·v + 1)
 *     y = (d·u + e·v + f) / (g·u + h·v + 1)
 *
 * ⓘ ভাগটাই এখানে আসল — ওটাই দূরের জিনিস ছোট দেখানোর নিয়ম। ওটা বাদ
 * দিলে যা পাওয়া যেত তা কেবল টানা-ঘোরানো, সোজা করা নয়।
 *
 * ⭐ আটটা অজানা, চার কোণ থেকে আটটা সমীকরণ — গাউসীয় অপনয়নে সমাধান।
 *
 * ⚠️ তিনটা কোণ এক সরলরেখায় পড়লে সমাধান নেই (pivot শূন্য)। তখন `null`
 * ফেরে, আর ডাকনেওয়ালা মূল ছবিটাই রাখে — ভাঙা ছবি বানানোর চেয়ে ভালো।
 */
export function solvePerspective(corners, width, height) {
    if (!isConvexQuad(corners)) {
        return null
    }

    const [tl, tr, br, bl] = corners

    const from = [
        [0, 0],
        [width, 0],
        [width, height],
        [0, height],
    ]
    const to = [tl, tr, br, bl]

    const matrix = []
    const rhs = []

    for (let i = 0; i < 4; i++) {
        const [u, v] = from[i]
        const { x, y } = to[i]

        matrix.push([u, v, 1, 0, 0, 0, -u * x, -v * x])
        rhs.push(x)
        matrix.push([0, 0, 0, u, v, 1, -u * y, -v * y])
        rhs.push(y)
    }

    const solved = solve(matrix, rhs)

    if (solved === null) {
        return null
    }

    const [a, b, c, d, e, f, g, h] = solved

    return { a, b, c, d, e, f, g, h }
}

/**
 * ⛔ চতুর্ভুজটা সত্যিই চতুর্ভুজ কি না — মেপে ধরা একটা ত্রুটি।
 *
 * ── কীভাবে ধরা পড়ল ──────────────────────────────────────────────────
 * পরীক্ষা লেখা হয়েছিল এই দাবিতে: *"তিন কোণ এক রেখায় পড়লে সমাধান নেই"*।
 * ⚠️ পরীক্ষাটা **লাল হলো** — অর্থাৎ অঙ্কটা ঐ অবস্থাতেও একটা উত্তর দিচ্ছিল।
 *
 * ⓘ কারণ: গাউসীয় অপনয়নের pivot তখন শূন্য নয়, কেবল **খুব ছোট** (১e-৯
 * ধরনের)। ⛔ তাই সহগগুলো বের হত, কিন্তু সেগুলো অর্থহীন — ব্যবহারকারী
 * তিনটা কোণ এক রেখায় টেনে ফেললে ফলাফল হত একটা টানা-ছেঁড়া আবর্জনা,
 * কোনো ত্রুটিবার্তা ছাড়া।
 *
 * ⭐ তাই pivot-এর সহনশীলতার উপর ভরসা না করে **আগেই** জিজ্ঞেস করা হয়:
 * চারটা কোণ কি একটা উত্তল চতুর্ভুজ বানায়?
 *
 * ⓘ পরীক্ষা: পরপর দুই বাহুর ক্রস-গুণফল। সব একই চিহ্নের হলে উত্তল;
 * কোনোটা শূন্যের কাছে মানে তিনটা বিন্দু এক রেখায়।
 *
 * ⚠️ ক্রস-গুণফলকে বাহুর দৈর্ঘ্য দিয়ে ভাগ করা হয় (অর্থাৎ কোণের sine),
 * নাহলে সীমাটা ছবির মাপের সাথে বদলাত — ছোট ছবিতে কড়া, বড়তে ঢিলা।
 */
function isConvexQuad(corners) {
    if (!Array.isArray(corners) || corners.length !== 4) {
        return false
    }

    let sign = 0

    for (let i = 0; i < 4; i++) {
        const a = corners[i]
        const b = corners[(i + 1) % 4]
        const c = corners[(i + 2) % 4]

        const ux = b.x - a.x
        const uy = b.y - a.y
        const vx = c.x - b.x
        const vy = c.y - b.y

        const lengths = Math.hypot(ux, uy) * Math.hypot(vx, vy)

        if (lengths === 0) {
            return false
        }

        const turn = (ux * vy - uy * vx) / lengths

        if (Math.abs(turn) < 1e-3) {
            return false
        }

        if (sign === 0) {
            sign = Math.sign(turn)
        } else if (Math.sign(turn) !== sign) {
            return false
        }
    }

    return true
}

/**
 * গাউসীয় অপনয়ন, আংশিক pivoting সহ।
 *
 * ⚠️ pivoting ছাড়া লেখা যেত আর ছোট উদাহরণে কাজও করত। কিন্তু কোণ দুইটা
 * কাছাকাছি হলে ভাগফল বিশাল হয়ে সংখ্যাগুলো ভেসে যেত — লক্ষণ হত
 * "মাঝেমধ্যে ছবিটা অদ্ভুত বেঁকে যায়", আর সেটা ধরা প্রায় অসম্ভব।
 */
function solve(matrix, rhs) {
    const n = rhs.length
    const m = matrix.map((row, i) => [...row, rhs[i]])

    for (let col = 0; col < n; col++) {
        let pivot = col

        for (let row = col + 1; row < n; row++) {
            if (Math.abs(m[row][col]) > Math.abs(m[pivot][col])) {
                pivot = row
            }
        }

        if (Math.abs(m[pivot][col]) < 1e-10) {
            return null
        }

        ;[m[col], m[pivot]] = [m[pivot], m[col]]

        for (let row = 0; row < n; row++) {
            if (row === col) continue

            const factor = m[row][col] / m[col][col]

            for (let k = col; k <= n; k++) {
                m[row][k] -= factor * m[col][k]
            }
        }
    }

    return m.map((row, i) => row[n] / row[i])
}

/**
 * ⭐ আসল কাজটা: বাঁকা কাগজ → সোজা আয়তক্ষেত্র।
 *
 * ── কেন উল্টোদিক থেকে (inverse mapping) ──────────────────────────────
 * সোজা পথ হত মূল ছবির প্রতিটা বিন্দু নিয়ে "এটা কোথায় যাবে" হিসাব করা।
 * ⛔ তাতে ফলাফলে **ফাঁক** পড়ত — কিছু বিন্দুতে কেউ পৌঁছাত না, আর ছবিতে
 * সাদা বিন্দুর ছিট দেখা যেত।
 *
 * ⭐ তাই উল্টোটা: ফলাফলের প্রতিটা বিন্দু নিজে জিজ্ঞেস করে "আমি মূল
 * ছবির কোথা থেকে আসব"। প্রতিটা ঘর ভরে, ফাঁক থাকে না।
 *
 * ── bilinear কেন ─────────────────────────────────────────────────────
 * উত্তরটা প্রায় কখনোই পূর্ণসংখ্যা হয় না (৩১৪.৭, ২০৮.২)। ⚠️ নিকটতম
 * বিন্দু নিলে ছাপা লেখার কিনারা দাঁতের মতো ভাঙা দেখাত। চারটা প্রতিবেশীর
 * ওজনদার গড় নিলে কিনারা মসৃণ থাকে।
 */
export function warp(source, corners, width, height) {
    const coefficients = solvePerspective(corners, width, height)

    if (coefficients === null) {
        return null
    }

    const { a, b, c, d, e, f, g, h } = coefficients
    const out = new Uint8ClampedArray(width * height * 4)
    const sw = source.width
    const sh = source.height
    const data = source.data

    for (let v = 0; v < height; v++) {
        for (let u = 0; u < width; u++) {
            const denominator = g * u + h * v + 1
            const x = (a * u + b * v + c) / denominator
            const y = (d * u + e * v + f) / denominator

            const target = (v * width + u) * 4

            if (x < 0 || y < 0 || x > sw - 1 || y > sh - 1) {
                // ⓘ কাগজের বাইরে পড়লে সাদা — কারণ ফলাফলটা একটা কাগজ।
                out[target] = 255
                out[target + 1] = 255
                out[target + 2] = 255
                out[target + 3] = 255
                continue
            }

            const x0 = Math.floor(x)
            const y0 = Math.floor(y)
            const x1 = Math.min(x0 + 1, sw - 1)
            const y1 = Math.min(y0 + 1, sh - 1)
            const fx = x - x0
            const fy = y - y0

            const p00 = (y0 * sw + x0) * 4
            const p10 = (y0 * sw + x1) * 4
            const p01 = (y1 * sw + x0) * 4
            const p11 = (y1 * sw + x1) * 4

            for (let channel = 0; channel < 3; channel++) {
                const top = data[p00 + channel] * (1 - fx) + data[p10 + channel] * fx
                const bottom = data[p01 + channel] * (1 - fx) + data[p11 + channel] * fx

                out[target + channel] = top * (1 - fy) + bottom * fy
            }

            out[target + 3] = 255
        }
    }

    return { data: out, width, height }
}

/**
 * ⭐ কাগজটা কোথায় — আপনাআপনি খোঁজা (গ)।
 *
 * ── কৌশলটা, আর কেন এটাই ─────────────────────────────────────────────
 * ১. কিনারার বিন্দুগুলো দেখে **পটভূমির রঙ** আন্দাজ করা (টেবিল, মেঝে)।
 * ২. যেসব বিন্দু ঐ রঙ থেকে আলাদা — সেগুলোই সম্ভবত কাগজ।
 * ৩. ঐ বিন্দুগুলোর মধ্যে `x+y` ও `x−y`-এর চরম চারটা = চার কোণ।
 *
 * ⓘ তৃতীয় ধাপটা পুরনো কৌশল আর অদ্ভুত রকম কার্যকর: একটা উত্তল
 * চতুর্ভুজের চার কোণ ঠিক ঐ চারটা চরম বিন্দুতেই থাকে।
 *
 * ── ⚠️ কখন এটা ভুল করে, আর সেটা লুকানো হয়নি ─────────────────────────
 * ⛔ এলোমেলো পটভূমিতে (কাগজের উপর কাগজ, ছাপা টেবিলক্লথ) ভুল করবে।
 * ⛔ কাগজ প্রায় ৪৫° কোণে ঘোরানো থাকলেও চরম-বিন্দুর কৌশল টলে।
 *
 * ⭐ তাই এটার ফলাফল **প্রস্তাব**, সিদ্ধান্ত নয় — পর্দায় চারটা কোণ
 * দেখানো হয় আর ব্যবহারকারী টেনে ঠিক করতে পারেন। CamScanner-ও ঠিক এই
 * কাজটাই করে; ওটার "জাদু" আসলে ভালো প্রস্তাব + সহজ সংশোধন।
 *
 * ⓘ কিছু না পেলে `null` — তখন পুরো ছবিটাই কাগজ ধরা হয়, আর সেটা
 * বর্তমান আচরণেরই সমান, খারাপ কিছু নয়।
 */
export function detectCorners(source, tolerance = 42) {
    const { data, width, height } = source

    if (width < 8 || height < 8) {
        return null
    }

    const background = borderColour(data, width, height)
    const points = []

    /*
     * ⓘ প্রতিটা বিন্দু নয়, একটা ছাঁকনি দিয়ে — বড় ছবিতে দশ লক্ষ বিন্দু
     * ঘাঁটার দরকার নেই, কোণ খুঁজতে কয়েক হাজার নমুনাই যথেষ্ট।
     */
    const step = Math.max(1, Math.floor(Math.min(width, height) / 200))

    for (let y = 0; y < height; y += step) {
        for (let x = 0; x < width; x += step) {
            const at = (y * width + x) * 4
            const distance =
                Math.abs(data[at] - background[0]) +
                Math.abs(data[at + 1] - background[1]) +
                Math.abs(data[at + 2] - background[2])

            if (distance > tolerance) {
                points.push({ x, y })
            }
        }
    }

    /*
     * ⛔ প্রায় কিছুই আলাদা নয় (এক রঙা ছবি), বা প্রায় সবটাই আলাদা
     * (পটভূমি বলে কিছু নেই) — দুই ক্ষেত্রেই খোঁজাটা অর্থহীন।
     */
    const total = Math.ceil(width / step) * Math.ceil(height / step)

    if (points.length < total * 0.05 || points.length > total * 0.98) {
        return null
    }

    let tl = points[0]
    let tr = points[0]
    let br = points[0]
    let bl = points[0]

    for (const p of points) {
        if (p.x + p.y < tl.x + tl.y) tl = p
        if (p.x + p.y > br.x + br.y) br = p
        if (p.x - p.y > tr.x - tr.y) tr = p
        if (p.x - p.y < bl.x - bl.y) bl = p
    }

    const corners = [tl, tr, br, bl]

    return isSaneQuad(corners, width, height) ? corners : null
}

/** কিনারার নমুনা থেকে পটভূমির রঙ — মধ্যমা, গড় নয়। */
function borderColour(data, width, height) {
    const reds = []
    const greens = []
    const blues = []

    const sample = (x, y) => {
        const at = (y * width + x) * 4
        reds.push(data[at])
        greens.push(data[at + 1])
        blues.push(data[at + 2])
    }

    const step = Math.max(1, Math.floor(width / 50))

    for (let x = 0; x < width; x += step) {
        sample(x, 0)
        sample(x, height - 1)
    }

    for (let y = 0; y < height; y += step) {
        sample(0, y)
        sample(width - 1, y)
    }

    /*
     * ⚠️ গড় নয়, মধ্যমা — কারণ কাগজটা যদি কিনারা ছুঁয়ে থাকে, গড় তখন
     * টেবিল ও কাগজের মাঝামাঝি একটা রঙ দিত, যা আসলে কোনোটাই নয়।
     */
    const middle = (values) => values.sort((a, b) => a - b)[Math.floor(values.length / 2)]

    return [middle(reds), middle(greens), middle(blues)]
}

/**
 * পাওয়া চতুর্ভুজটা বিশ্বাসযোগ্য কি না।
 *
 * ⚠️ এই পাহারাটা না থাকলে খোঁজাটা প্রায় **সবসময়** কিছু একটা ফেরত দিত —
 * একটা সরু ফালি বা প্রায় পুরো ছবি — আর ব্যবহারকারী দেখতেন ছবিটা
 * উদ্ভটভাবে টেনে বিকৃত হয়ে গেছে।
 */
function isSaneQuad(corners, width, height) {
    const area = quadArea(corners)
    const whole = width * height

    // খুব ছোট মানে কাগজ পাওয়া যায়নি; প্রায় পুরোটা মানে কিছু কাটার নেই।
    if (area < whole * 0.12 || area > whole * 0.995) {
        return false
    }

    const [tl, tr, br, bl] = corners
    const span = (a, b) => Math.hypot(a.x - b.x, a.y - b.y)
    const sides = [span(tl, tr), span(tr, br), span(br, bl), span(bl, tl)]

    // ⛔ কোনো বাহু প্রায় শূন্য মানে চতুর্ভুজটা আসলে ত্রিভুজ বা রেখা।
    return Math.min(...sides) > Math.max(width, height) * 0.08
}

/** চতুর্ভুজের ক্ষেত্রফল — জুতার ফিতার সূত্র। */
function quadArea(corners) {
    let area = 0

    for (let i = 0; i < 4; i++) {
        const a = corners[i]
        const b = corners[(i + 1) % 4]
        area += a.x * b.y - b.x * a.y
    }

    return Math.abs(area) / 2
}
