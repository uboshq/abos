{{--
    খোঁজার প্যালেট — টপবারের "যেকোনো কিছু খুঁজুন" বোতামটা এটাই খোলে।

    ── কী ছিল না, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
    ⛔ বোতামটা `open-command-center` ঘটনা পাঠাত আর **কেউ শুনত না**।
    দেখতে জীবিত, চাপলে কিছুই হয় না। ⓘ কথাটা টপবারের মন্তব্যে লেখাও
    ছিল — জানা ভুল, তবু পর্দায় বসে ছিল।

    ⭐ `SearchEngine`-ও আগে থেকেই লেখা ছিল, কেবল কেউ ডাকত না। তাই এই
    ফাইলটা নতুন কোনো ক্ষমতা আনে না — **অনুপস্থিত তারটা জোড়া দেয়**।

    ── কেন পাতার উপরে ভাসমান, আলাদা পর্দা নয় ────────────────────────────
    খোঁজা একটা **থেমে-যাওয়া** কাজ: মানুষ কিছু একটা করছিলেন, মাঝপথে
    খুঁজতে এলেন, তারপর ফিরে যাবেন। ⓘ আলাদা পাতায় নিয়ে গেলে ফেরার পথটা
    হারায়, আর `Esc`-এ যা ফিরে আসে সেটাই সবচেয়ে সস্তা ফেরা।
--}}
<div x-data="{
        open: false,
        q: '',
        hits: [],
        busy: false,
        timer: null,

        /*
         * প্রতিটা অক্ষরে অনুরোধ নয় — থেমে যাওয়ার পর।
         *
         * ⓘ ২০০ মিলিসেকেন্ড: টাইপ করার স্বাভাবিক বিরতির চেয়ে বড়, আর
         * মানুষের কাছে তাৎক্ষণিকই মনে হয়। ⚠️ না দিলে 'invoice' লিখতে
         * সাতটা অনুরোধ যেত, আর শেষেরটা আগে ফিরলে তালিকায় ভুল ফল বসত।
         */
        ask() {
            clearTimeout(this.timer);

            if (this.q.trim().length < 2) {
                this.hits = [];
                this.busy = false;

                return;
            }

            this.busy = true;

            this.timer = setTimeout(async () => {
                try {
                    const res = await fetch('{{ route('search') }}?q=' + encodeURIComponent(this.q));
                    const data = await res.json();
                    this.hits = data.hits ?? [];
                } catch (e) {
                    /* ⓘ নীরবে খালি — খোঁজা ব্যর্থ হলে পাতাটা ভাঙার কোনো
                       কারণ নেই; মানুষ আবার লিখবেন। */
                    this.hits = [];
                }

                this.busy = false;
            }, 200);
        },
     }"
     @open-command-center.window="open = true; $nextTick(() => $refs.box?.focus())"
     @keydown.escape.window="open = false"
     x-show="open" x-cloak
     x-transition.opacity.duration.100ms
     style="position:fixed;inset:0;z-index:60;display:flex;justify-content:center;
            align-items:flex-start;padding:10vh 16px 16px;background:rgb(0 0 0 / 0.35)"
     @click.self="open = false"
     role="dialog" aria-modal="true">

    {{-- ⓘ জ্যামিতিটা inline — এই প্যানেলগুলোয় একটা ক্লাস নীরবে কম্পাইল
         না হলে গোটা গড়নটা ভেঙে পড়ে, আর ভুলের কোনো বার্তা থাকে না। --}}
    <div style="width:100%;max-width:640px;border-radius:12px;overflow:hidden;
                border:1px solid var(--color-border);background:var(--color-surface-card);
                box-shadow:0 20px 60px rgb(0 0 0 / 0.25)">

        <div style="display:flex;align-items:center;gap:8px;padding:12px 14px;
                    border-bottom:1px solid var(--color-border)">
            <x-ui.icon name="search" :size="18" class="text-(--color-ink-muted)" />

            <input x-ref="box" x-model="q" @input="ask()" type="text"
                   placeholder="{{ __('core.action.search_anything') }}"
                   style="flex:1;border:0;outline:none;background:transparent;
                          font-size:15px;color:var(--color-ink-body)">

            <span x-show="busy" x-cloak
                  style="font-size:11px;color:var(--color-ink-muted)">…</span>
        </div>

        <div style="max-height:55vh;overflow-y:auto">
            {{-- ⚠️ তিনটা অবস্থা, আর তিনটাই আলাদা কথা বলে। ⓘ একটাতে সব
                 মিলিয়ে দিলে "কিছু পাওয়া যায়নি" আর "এখনো কিছু লেখেননি"
                 এক দেখাত, অথচ দুইটার উত্তর সম্পূর্ণ আলাদা। --}}
            <template x-if="q.trim().length < 2">
                <p style="padding:16px;font-size:13px;color:var(--color-ink-muted)">
                    {{ __('core.search.type_to_find') }}
                </p>
            </template>

            <template x-if="q.trim().length >= 2 && ! busy && hits.length === 0">
                <p style="padding:16px;font-size:13px;color:var(--color-ink-muted)">
                    {{ __('core.search.nothing_found') }}
                </p>
            </template>

            <template x-for="hit in hits" :key="hit.url">
                <a :href="hit.url"
                   class="hover:bg-(--color-surface-muted)"
                   style="display:flex;align-items:baseline;gap:10px;padding:10px 14px;
                          border-bottom:1px solid var(--color-border);text-decoration:none">
                    <span style="flex:none;font-size:11px;color:var(--color-ink-muted)"
                          x-text="hit.type"></span>

                    <span style="flex:none;font-size:12px;color:var(--color-ink-muted)"
                          x-text="hit.no"></span>

                    <span style="flex:1;font-size:13px;color:var(--color-ink-body)"
                          x-text="hit.label"></span>
                </a>
            </template>
        </div>
    </div>
</div>
