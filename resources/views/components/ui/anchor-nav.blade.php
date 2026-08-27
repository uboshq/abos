{{--
    ফিওরির নোঙর-পটি — লম্বা রেকর্ডের ভেতরে চলাচল।

    ── কী জিনিস ─────────────────────────────────────────────────────────
    SAP Fiori-র object page-এর মাথায় একটা সরু পটি বসে যাতে পাতার প্রতিটা
    অংশের নাম থাকে। নামে ক্লিক করলে ওই অংশে চলে যায়, পাতা না ছেড়েই।

    একটা বিশ-হাত লম্বা চালানের পাতায় এটা সাজসজ্জা নয় — "কাগজপত্র কোথায়"
    খুঁজতে গিয়ে পাঁচবার স্ক্রল করার বদলে একটা ক্লিক।

    ── কেন অংশগুলো পাতা থেকে চাওয়া হয়নি ────────────────────────────────
    প্রতিটা রেকর্ড-পাতাকে নিজের অংশের তালিকা পাঠাতে বলা যেত। কিন্তু ওতে
    একত্রিশটা পাতায় হাত দিতে হত, আর **একটা পাতায় একটা অংশ যোগ করে তালিকায়
    যোগ করতে ভুলে গেলে পটিটা চুপচাপ অসম্পূর্ণ হত** — পাতায় ছয়টা অংশ,
    পটিতে পাঁচটা, আর কেউ টের পেত না।

    তাই পটিটা পাতাকে জিজ্ঞেস করে না, **পাতাটাই পড়ে**: রেন্ডার হওয়ার পর যে
    `<section>`-গুলোর নিজের একটা `<h2>` আছে, সেগুলোই অংশ। নতুন অংশ যোগ
    করলে পটিতে সে নিজে থেকেই আসে।

    ── কেন `id` এখানে বসানো হয় ──────────────────────────────────────────
    নোঙরের জন্য প্রতিটা অংশের একটা `id` লাগে, আর পাতাগুলোয় ওটা নেই।
    শিরোনামের লেখা থেকে বানালে ভাষা বদলালে `id`-ও বদলাত — কিন্তু সেটা
    ক্ষতি করে না, কারণ লিংকগুলো একই সময়ে একই নিয়মে তৈরি হয়। বাইরের কেউ
    এই `id`-তে লিংক করে না।

    ── দুইটার কম অংশ হলে পটিটাই থাকে না ─────────────────────────────────
    একটা অংশের পাতায় নোঙর অর্থহীন — যেখানে আছি সেখানেই নিয়ে যাবে। তালিকার
    পর্দাগুলোও তাই বাদ পড়ে, কারণ ওদের একটাই অংশ।
--}}
@php
    $anchors = \App\Core\Support\Ui::sections(
        \App\Core\Support\LookRegistry::lookFor(
            \App\Core\Support\LookPreview::orChosen(auth()->user()?->ui)
        )
    ) === 'anchors';
@endphp

@if ($anchors)
    <div x-data="anchorNav()" x-init="collect()" x-show="items.length > 1" x-cloak
         data-anchor-nav
         class="sticky top-(--spacing-header) z-20 -mx-3 mb-3 flex gap-1 overflow-x-auto
                border-b border-(--color-border) bg-(--color-surface-app) px-3 py-1.5
                md:-mx-5 md:px-5">
        <template x-for="item in items" :key="item.id">
            <a :href="'#' + item.id"
               @click.prevent="go(item.id)"
               :class="item.id === active
                   ? 'border-(--color-brand-500) text-(--color-ink) font-semibold'
                   : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)'"
               class="shrink-0 border-b-2 px-2 py-1 text-sm transition-colors"
               x-text="item.label"></a>
        </template>
    </div>

    @once
        @push('scripts')
            <script>
                function anchorNav() {
                    return {
                        items: [],
                        active: null,

                        /* পাতার অংশগুলো — যাদের নিজের একটা <h2> আছে। */
                        collect() {
                            const main = document.querySelector('main') || document.body;
                            const found = [];

                            main.querySelectorAll('section').forEach((section, i) => {
                                const heading = section.querySelector('h2');
                                if (!heading) return;

                                const label = heading.textContent.trim();
                                if (label === '') return;

                                /* নিজের id না থাকলে একটা বসানো হয়। ক্রমটাও
                                   নামের সাথে রাখা হয়, কারণ দুইটা অংশের নাম
                                   এক হলে দুইটা এক id পেত আর দ্বিতীয়টায়
                                   কোনোদিন যাওয়া যেত না। */
                                if (!section.id) section.id = 'sec-' + i;

                                found.push({ id: section.id, label });
                            });

                            this.items = found;
                            this.active = found.length ? found[0].id : null;

                            if (found.length > 1) this.watch(found);
                        },

                        /* কোন অংশটা এখন চোখের সামনে। */
                        watch(found) {
                            if (!('IntersectionObserver' in window)) return;

                            const seen = new IntersectionObserver((entries) => {
                                entries
                                    .filter((e) => e.isIntersecting)
                                    .forEach((e) => { this.active = e.target.id; });
                            }, { rootMargin: '-20% 0px -70% 0px' });

                            found.forEach((f) => {
                                const el = document.getElementById(f.id);
                                if (el) seen.observe(el);
                            });
                        },

                        go(id) {
                            const el = document.getElementById(id);
                            if (!el) return;

                            /* `scrollIntoView` পটিটার নিচে অংশটাকে লুকিয়ে
                               ফেলত — পটিটা sticky, তাই তার উচ্চতাটা বাদ
                               দিয়ে নামতে হয়। */
                            const bar = document.querySelector('[data-anchor-nav]');
                            const gap = (bar ? bar.getBoundingClientRect().height : 0) + 8;

                            window.scrollTo({
                                top: el.getBoundingClientRect().top + window.scrollY - gap,
                                behavior: 'smooth',
                            });

                            this.active = id;
                        },
                    };
                }
            </script>
        @endpush
    @endonce
@endif
