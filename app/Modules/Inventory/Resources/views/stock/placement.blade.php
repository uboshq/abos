{{--
    মাল বুঝে নেওয়া — কাগজ ধরে ধরে।

    ── কেন কাগজ, পণ্য নয় ────────────────────────────────────────────────
    গুদামের লোক একটা চালান হাতে নিয়ে দাঁড়ান। তাঁর প্রশ্ন "এই কাগজটার
    মাল বুঝে নেওয়া হয়েছে কি না", "কোন পণ্যের কত বাকি" নয়। পণ্য ধরে
    সাজালে একই চালানের ছয়টা লাইন ছয় জায়গায় ছড়িয়ে যেত।

    ── প্রতিটা সারিতে পরিমাণ, আর ডিফল্টে পুরোটা ───────────────────────
    ⚠️ "সব বসিয়ে দিন" বোতাম নেই, ইচ্ছাকৃতভাবে। দশ কার্টনের দুইটা ভাঙা
    হলে এক চাপে সবটা বসানোর সুযোগ থাকলে ভাঙা মালও বসে যেত। ঘরে পুরোটা
    আগে থেকে বসানো থাকে — **কমাতে হলে ইচ্ছে করে কমাতে হয়।**

    ── ⭐ দুই ভাগ — মালিকের ছবি, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────
    ক্রয়ের কাগজ আর ফেরতের কাগজ আলাদা দুই ভাগে। ⓘ এক তালিকায় মিশলে
    গুদামের লোককে প্রতিটা সারি পড়ে বুঝতে হত মালটা গাড়ি থেকে নামল না
    গ্রাহকের কাছ থেকে ফিরল — আর দুইটার পরীক্ষা এক নয়: ফেরত মাল ভাঙা
    কি না সেটা দেখে নিতে হয়, নতুন চালানে গোনা মিলিয়ে নিলেই হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.placement') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.placement')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($papers === [])
        {{-- খালি অবস্থাটা সুখবর, ব্যর্থতা নয় — বাক্যটাও সেটাই বলে --}}
        <x-ui.empty-state :message="__('inventory::message.nothing_to_place')" />
    @else
        {{--
            ⓘ Alpine-এর ঘরটা এখন সব ফর্মের **উপরে**, ভেতরে নয়। ⭐ কারণ
            প্রতিটা কাগজের নিজের ফর্ম (দেখুন [[stock/partials/paper]]), অথচ
            উপরের "সবার জন্য" বারটা সব কাগজের সারিতেই বসাতে পারা চাই।
        --}}
        <div x-data="stockPlacement(@js($places))">
            {{--
                ⭐ "একবার বেছে, সবগুলোয় বসাও" — মালিকের ছবির উপরের সারি
                ("Combine Selection For All Listed Products")।

                ── কেন এটা আগের "সব বসিয়ে দিন নেই" নিয়মটা ভাঙে না ───────
                ⚠️ এই বারটা **পরিমাণ ছোঁয় না** — কেবল জায়গাটা বসায়।
                পরিমাণ প্রতিটা সারিতেই আলাদা থাকে, আর ভাঙা কার্টনের
                সংখ্যাটা এখনো হাতেই কমাতে হয়। ⓘ আসল নিয়মটা ছিল "ভাঙা
                মাল যেন এক চাপে বসে না যায়", আর সেটা অক্ষত।

                ⓘ গুদামে কোনো তাক বসানো না থাকলে বারটা আসেই না — তখন
                ছোট দোকানের জন্য প্রতিটা কাগজের নিচের বোতামই যথেষ্ট।
            --}}
            <template x-if="anyPlaces">
                <section data-boxed
                         class="mb-4 rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 text-2xs font-semibold uppercase tracking-wide
                               text-(--color-ink-muted)">
                        {{ __('inventory::label.combine_selection') }}
                    </h2>

                    <div class="flex flex-wrap items-end gap-3">
                        @foreach (['warehouse' => 'warehouse', 'block' => 'depth_1', 'rack' => 'depth_2', 'shelf' => 'depth_3'] as $slot => $label)
                            <label class="grid gap-1">
                                <span class="text-2xs text-(--color-ink-muted)">
                                    {{ __('inventory::field.'.$label) }}
                                </span>
                                <select x-model="all.{{ $slot }}"
                                        x-on:change="allChanged('{{ $slot }}')"
                                        class="h-(--spacing-field) w-44 rounded-(--radius-field)
                                               border border-(--color-border)
                                               bg-(--color-surface-card) px-2">
                                    <option value="">—</option>
                                    <template x-for="o in allOptions('{{ $slot }}')" :key="o.id">
                                        <option :value="o.id" x-text="o.name"></option>
                                    </template>
                                </select>
                            </label>
                        @endforeach

                        <x-ui.button type="button" tone="secondary" x-on:click="applyToAll()">
                            {{ __('inventory::action.set') }}
                        </x-ui.button>
                    </div>
                </section>
            </template>

            {{--
                ⚠️ ভাগ দুইটা সবসময়ই দেখা যায়, খালি হলেও — আর সেটা
                ইচ্ছাকৃত। ⓘ "ফেরতের কিছু বসানোর নেই" লেখাটা একটা উত্তর;
                ভাগটা না থাকলে গুদামের লোক ভাবতেন ফেরতের মাল বুঝি অন্য
                কোথাও বসাতে হয়, আর খুঁজতে বেরোতেন।
            --}}
            @foreach ([
                'purchase' => 'no_purchases_to_place',
                'return' => 'no_returns_to_place',
            ] as $group => $emptyWord)
                <h2 class="mb-2 mt-6 font-semibold first:mt-0">
                    {{ __('inventory::label.'.$group.'_placement') }}
                </h2>

                @if ($groups[$group] === [])
                    <p class="rounded-(--radius-card) border border-(--color-border)
                              bg-(--color-surface-card) px-4 py-3 text-sm text-(--color-ink-muted)">
                        {{ __('inventory::message.'.$emptyWord) }}
                    </p>
                @else
                    <div class="grid gap-4">
                        @foreach ($groups[$group] as $paper)
                            @include('inventory::stock.partials.paper', ['paper' => $paper])
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</x-layouts.app>
