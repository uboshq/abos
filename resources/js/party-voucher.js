/**
 * রসিদ ও পরিশোধের পর্দার যুক্তি — পক্ষ, বকেয়া, আর বিলের ভাগ।
 *
 * ── ⭐ কেন ফাইলে, ব্লেডের ভিতরে নয় ───────────────────────────────────
 * আগে পুরো যুক্তিটা `x-data="{ … }"` অ্যাট্রিবিউটের ভিতরে লেখা ছিল, আর
 * সেখানে দুইটা জিনিস বারবার ভেঙেছে: অনুবাদে একটা অ্যাপস্ট্রফি থাকলেই
 * স্ট্রিং শেষ হয়ে যেত, আর Blade মন্তব্যের ভিতরের `{{ }}`-ও পার্স করত।
 *
 * ⓘ এখানে লেখাগুলো বাইরে থেকে আসে, তাই ঐ দুইটা ফাঁদই বন্ধ।
 */
export default function partyVoucher({ partyType, partyId, parties, dueUrl, picked, texts }) {
    return {
        partyType,
        partyId,
        parties,
        texts,

        /** এই পক্ষের মোট বকেয়া — নামের পাশে দেখানো হয়। */
        due: null,

        /** এই পক্ষের যে বিলগুলো এখনো পুরো শোধ হয়নি। */
        bills: [],

        /**
         * কোন বিলে কত বসল — চাবি বিলের id, মান টাকার অঙ্ক।
         *
         * ⚠️ অ্যারে নয়, বস্তু: সারিগুলো বাছাই-অনুযায়ী আসে-যায়, আর
         * অ্যারের সূচক ধরে রাখলে একটা টিক তুললে বাকিগুলোর অঙ্ক এক ঘর
         * সরে যেত।
         */
        alloc: {},

        get partyOptions() {
            return this.parties.filter((p) => p.type === this.partyType);
        },

        get allocatedTotal() {
            return Object.values(this.alloc).reduce((sum, v) => sum + (Number(v) || 0), 0);
        },

        /**
         * ভাগ করা টাকা গৃহীত টাকার চেয়ে বেশি কি না।
         *
         * ⓘ অঙ্কটা পর্দার নিচের "গৃহীত টাকা" ঘর থেকে পড়া হয় — দুইটা
         * সংখ্যা এক জায়গায় রাখলে একটা বদলালে অন্যটা বাসি হয়ে যেত।
         */
        get overAllocated() {
            const amount = Number(this.$root.closest('form')?.querySelector('[name="amount"]')?.value || 0);

            return amount > 0 && this.allocatedTotal - amount > 0.0001;
        },

        init() {
            for (const row of picked || []) {
                if (row && row.invoice_id) {
                    this.alloc[row.invoice_id] = Number(row.amount) || 0;
                }
            }

            if (this.partyId) {
                this.loadDue();
            }
        },

        resetParty() {
            this.partyId = '';
            this.due = null;
            this.bills = [];
            this.alloc = {};
        },

        async loadDue() {
            if (!this.partyType || !this.partyId) {
                this.due = null;
                this.bills = [];

                return;
            }

            try {
                const url = `${dueUrl}?party_type=${encodeURIComponent(this.partyType)}&party_id=${encodeURIComponent(this.partyId)}`;
                const res = await fetch(url, { headers: { Accept: 'application/json' } });

                if (!res.ok) {
                    return;
                }

                const data = await res.json();

                this.due = data.known ? Number(data.amount).toFixed(2) : null;
                this.bills = data.bills || [];
            } catch (e) {
                /*
                 * ⚠️ নেট গেলে পর্দাটা অচল হয় না — বকেয়া আর বিলের তালিকা
                 * সুবিধা, শর্ত নয়। ⓘ ব্যবহারকারী হাতে অঙ্ক লিখে রসিদটা
                 * ঠিকই সংরক্ষণ করতে পারেন।
                 */
                this.due = null;
            }
        },

        toggle(bill, on) {
            if (on) {
                this.alloc[bill.id] = Number(bill.outstanding);
            } else {
                delete this.alloc[bill.id];
            }
        },

        /**
         * পুরনো বিল আগে — মালিকের নিজের চাওয়া নিয়ম।
         *
         * ⓘ গৃহীত টাকাটা বয়সের ক্রমে বসে, আর প্রতিটা বিলে **যতটুকু
         * বাকি ততটুকুই** — বেশি নয়। ⚠️ টাকা ফুরালে বাকি বিলগুলো খালি
         * থাকে, শূন্য বসে না: শূন্য বসালে সেগুলো "ভাগ করা হয়েছে"
         * দেখাত অথচ কিছুই বসেনি।
         */
        fifo() {
            const form = this.$root.closest('form');
            let left = Number(form?.querySelector('[name="amount"]')?.value || 0);

            this.alloc = {};

            for (const b of [...this.bills].sort((a, b) => b.age - a.age)) {
                if (left <= 0.0001) {
                    break;
                }

                const take = Math.min(left, Number(b.outstanding));

                this.alloc[b.id] = Number(take.toFixed(2));
                left -= take;
            }
        },

        clearAlloc() {
            this.alloc = {};
        },
    };
}
