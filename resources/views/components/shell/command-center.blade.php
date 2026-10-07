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

    ── ⭐ কীবোর্ড, ২৭ সেপ্টেম্বর ২০২৬ (A-06, A-07) ──────────────────────
    Ctrl+K / ⌘K যেকোনো পাতা থেকে খোলে (`hotkey()`, `window`-এ — রোলের
    পাতায় রোল খোঁজা আগে, কারণটা shell.js-এ)। ঘরের ভিতরে ↓ ↑ বাছাই
    সরায়, ↵ বাছা ফলটা খোলে, Esc বন্ধ করে।

    ⓘ বাছা সারির রং `aria-selected:` দিয়ে — অর্থাৎ **যে অবস্থা স্ক্রিন-
    রিডার পড়ে, চোখও ঠিক সেটাই দেখে**। আলাদা একটা "active" ক্লাস রাখলে
    দুইটা কোনোদিন আলাদা হয়ে যেত।
--}}
{{-- ⓘ যুক্তিটা `resources/js/components/shell.js`-এ (commandCenter) —
     CSP-Alpine অ্যাট্রিবিউটের ভিতরে পদ্ধতি পড়তে পারে না। --}}
<div x-data="commandCenter({ url: '{{ route('search') }}' })"
     @open-command-center.window="show()"
     @keydown.window="hotkey($event)"
     @keydown.escape.window="close()"
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
                   @keydown.down.prevent="next()"
                   @keydown.up.prevent="prev()"
                   @keydown.enter.prevent="choose($event)"
                   role="combobox" aria-autocomplete="list"
                   aria-controls="command-hits" :aria-expanded="items.length > 0"
                   :aria-activedescendant="activeId"
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
            <template x-if="hint">
                <p style="padding:16px;font-size:13px;color:var(--color-ink-muted)">
                    {{ __('core.search.type_to_find') }}
                </p>
            </template>

            <template x-if="nothingFound">
                <p style="padding:16px;font-size:13px;color:var(--color-ink-muted)">
                    {{ __('core.search.nothing_found') }}
                </p>
            </template>

            {{-- ⓘ `role="option"` লিংকেই — ↵ আর ক্লিক একই জিনিস চাপে
                 (shell.js-এর `choose()`)। --}}
            <div id="command-hits" role="listbox">

            {{-- ⭐ খালি বাক্স: সাম্প্রতিক কাগজ, তারপর প্রস্তাবিত কাজ — ২ অক্টোবর ২০২৬।
                 ⓘ ক্রমগুলো একটাই তালিকায় (`items`), তাই ↓ ↑ ↵ দুই দলের
                 সীমানা পেরিয়েও একই রকম চলে। দলের নাম `role="group"`-এ, যাতে
                 স্ক্রিন-রিডারও দলটা শোনে। --}}
            <div x-show="recentList.length > 0" role="group" aria-labelledby="command-recent-label">
                <p id="command-recent-label"
                   style="margin:0;padding:8px 14px 4px;font-size:11px;font-weight:600;color:var(--color-ink-muted)">
                    {{ __('core.search.recent') }}
                </p>

                <template x-for="(hit, i) in recentList" :key="hit.url">
                    <a :href="hit.url" :id="hitId(i)"
                       role="option" :aria-selected="isActive(i) ? 'true' : 'false'"
                       @mousemove="point(i)"
                       class="hover:bg-(--color-surface-muted) aria-selected:bg-(--color-surface-muted)"
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

            <div x-show="actionList.length > 0" role="group" aria-labelledby="command-actions-label">
                <p id="command-actions-label"
                   style="margin:0;padding:8px 14px 4px;font-size:11px;font-weight:600;color:var(--color-ink-muted)">
                    {{ __('core.search.actions') }}
                </p>

                <template x-for="(act, j) in actionList" :key="act.url">
                    <a :href="act.url" :id="actionId(j)"
                       role="option" :aria-selected="isActionActive(j) ? 'true' : 'false'"
                       @mousemove="pointAction(j)"
                       class="hover:bg-(--color-surface-muted) aria-selected:bg-(--color-surface-muted)"
                       style="display:flex;align-items:baseline;gap:10px;padding:10px 14px;
                              border-bottom:1px solid var(--color-border);text-decoration:none">
                        <span style="flex:none;font-size:11px;color:var(--color-ink-muted)"
                              x-text="act.type"></span>

                        <span style="flex:1;font-size:13px;color:var(--color-ink-body)"
                              x-text="act.label"></span>
                    </a>
                </template>
            </div>

            <template x-for="(hit, i) in hitList" :key="hit.url">
                <a :href="hit.url" :id="hitId(i)"
                   role="option" :aria-selected="isActive(i) ? 'true' : 'false'"
                   @mousemove="point(i)"
                   class="hover:bg-(--color-surface-muted) aria-selected:bg-(--color-surface-muted)"
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
</div>
