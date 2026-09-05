/*
 * মাল কোথায় রাখা হলো — গুদাম ▸ ব্লক ▸ র‍্যাক ▸ শেলফ।
 *
 * ── কেন অঙ্কটা এখানে, Blade-এর ভিতরে নয় ─────────────────────────────
 * ইনলাইন `x-data="{...}"` লিখলে এই ছাঁকাছাঁকির কোনো পরীক্ষা লেখা যেত
 * না। ⓘ এই রিপোতে `pricing.js` আর `date.js` ঠিক একই কারণে আলাদা।
 *
 * ── আকৃতি ───────────────────────────────────────────────────────────
 * `places` সমতল, গাছ নয়: প্রতিটা সারিতে `depth` (১=ব্লক, ২=র‍্যাক,
 * ৩=শেলফ) আর `parent`। ⭐ চতুর্থ স্তর এলে এই ফাইলের এক অক্ষরও বদলায়
 * না — কেবল পর্দায় আরেকটা ঘর বসে।
 */

export const BLOCK = 1
export const RACK = 2
export const SHELF = 3

/** কোন ঘরের কোন গভীরতা — পর্দার শব্দ আর অঙ্কের সংখ্যা এক জায়গায় বাঁধা। */
export const DEPTH_OF = { block: BLOCK, rack: RACK, shelf: SHELF }

/**
 * একটা গুদামের একটা গভীরতার সারিগুলো, বাবা ধরে ছেঁকে।
 *
 * ⚠️ বাবা না বাছা থাকলে ফল **খালি**, "সব" নয়।
 *
 * ⓘ প্রথমে এখানে "বাবা না দিলে সব দেখাও" লেখা ছিল। ⛔ তাতে র‍্যাক না
 * বেছেই গোটা গুদামের সব শেলফ দেখাত, আর গুদামের লোক অন্য র‍্যাকের
 * শেলফ বেছে ফেলতে পারতেন — কার্টনটা তখন খাতায় এক জায়গায়, হাতে আরেক
 * জায়গায়। ব্লকের বাবা নেই, তাই সে এর বাইরে।
 */
export function optionsFor(places, warehouseId, depth, parentId) {
    const rows = places[warehouseId] ?? []

    if (depth !== BLOCK && ! parentId) {
        return []
    }

    return rows.filter((row) => {
        if (row.depth !== depth) {
            return false
        }

        return depth === BLOCK
            ? ! row.parent
            : String(row.parent) === String(parentId)
    })
}

/**
 * সারির জন্য যে আইডিটা সার্ভারে যাবে — **সবচেয়ে গভীরটা**।
 *
 * ⓘ শেলফ বাছা থাকলে শেলফ, নাহলে র‍্যাক, নাহলে ব্লক, নাহলে কিছুই না।
 * উপরের ধাপগুলো `parent` বেয়ে ফেরত পাওয়া যায়, তাই তিনটা আলাদা ঘর
 * পাঠালে একই সত্যের তিনটা কপি যেত।
 */
export function deepest(row) {
    return row.shelf || row.rack || row.block || ''
}

/**
 * উপরের বারটা নিচের সব সারিতে বসানো।
 *
 * ⚠️ গুদাম মিললে তবেই — নাহলে এক গুদামের র‍্যাক আরেক গুদামের সারিতে
 * বসে যেত, আর `StockService::place()` তখন প্রতিটা লাইনে থামত। ⓘ বারে
 * গুদাম না বাছা থাকলে সব সারিতেই বসে, কারণ তখন ব্যবহারকারী "যেখানেই
 * হোক" বলছেন।
 */
export function applyAll(rows, all) {
    for (const row of rows) {
        if (all.warehouse && String(row.w) !== String(all.warehouse)) {
            continue
        }

        row.block = all.block
        row.rack = all.rack
        row.shelf = all.shelf
    }

    return rows
}

/** Alpine কম্পোনেন্ট — উপরের বিশুদ্ধ ফাংশনগুলোর পাতলা মোড়ক। */
export function stockPlacement(places) {
    return {
        places,
        rows: [],
        all: { warehouse: '', block: '', rack: '', shelf: '' },

        get anyPlaces() {
            return Object.keys(this.places ?? {}).length > 0
        },

        hasPlaces(warehouseId) {
            return (this.places[warehouseId] ?? []).length > 0
        },

        optionsFor(warehouseId, depth, parentId) {
            return optionsFor(this.places, warehouseId, depth, parentId)
        },

        /**
         * উপরের বারের বিকল্পগুলো — গুদাম বাছা থাকলে তারটা, নাহলে খালি।
         *
         * ⓘ গুদাম না বেছে ব্লক দেখানো যেত না: দুই গুদামে একই নামের ব্লক
         * থাকতে পারে, আর তখন কোনটা কার তা বলার উপায় নেই।
         */
        allOptions(slot) {
            if (slot === 'warehouse') {
                return Object.keys(this.places).map((id) => ({
                    id,
                    name: (this.places[id][0] ?? {}).warehouse_name ?? id,
                }))
            }

            if (! this.all.warehouse) {
                return []
            }

            const parent = slot === 'block' ? null : (slot === 'rack' ? this.all.block : this.all.rack)

            return optionsFor(this.places, this.all.warehouse, DEPTH_OF[slot], parent)
        },

        /** উপরে কিছু বদলালে তার নিচের ধাপগুলো ভুলে যাওয়া। */
        allChanged(slot) {
            if (slot === 'warehouse') {
                this.all.block = this.all.rack = this.all.shelf = ''
            } else if (slot === 'block') {
                this.all.rack = this.all.shelf = ''
            } else if (slot === 'rack') {
                this.all.shelf = ''
            }
        },

        /** একটা সারিতে উপরে কিছু বদলালে — একই নিয়ম। */
        rowChanged(row, slot) {
            if (slot === 'block') {
                row.rack = row.shelf = ''
            } else if (slot === 'rack') {
                row.shelf = ''
            }
        },

        deepest(row) {
            return deepest(row)
        },

        applyToAll() {
            applyAll(this.rows, this.all)
        },
    }
}
