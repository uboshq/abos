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
        <form method="POST" action="{{ route('inventory.stock.placement.store') }}"
              x-data="stockPlacement(@js($places))">
            @csrf

            {{--
                ⭐ "একবার বেছে, সবগুলোয় বসাও" — মালিকের ছবির উপরের সারি
                ("Combine Selection For All Listed Products")।

                ── কেন এটা আগের "সব বসিয়ে দিন নেই" নিয়মটা ভাঙে না ───────
                ⚠️ এই বারটা **পরিমাণ ছোঁয় না** — কেবল জায়গাটা বসায়।
                পরিমাণ প্রতিটা সারিতেই আলাদা থাকে, আর ভাঙা কার্টনের
                সংখ্যাটা এখনো হাতেই কমাতে হয়। ⓘ আসল নিয়মটা ছিল "ভাঙা
                মাল যেন এক চাপে বসে না যায়", আর সেটা অক্ষত।

                ⓘ গুদামে কোনো তাক বসানো না থাকলে বারটা আসেই না — তখন
                ছোট দোকানের জন্য নিচের একটাই বোতামই যথেষ্ট।
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

            <div class="grid gap-4">
                @foreach ($papers as $key => $paper)
                    <section data-boxed
                             class="rounded-(--radius-card) border border-(--color-border)
                                    bg-(--color-surface-card) p-4">

                        <header class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="font-semibold">
                                {{-- ⭐ প্রতিটা সারি থেকে উৎসের কাগজে যাওয়া যায় — মালিকের
                                     স্থায়ী নিয়ম। নম্বর না থাকলে অন্তত উৎসটা দেখানো হয়,
                                     যাতে সারিটা কোথা থেকে এল তা কখনো অজানা না থাকে। --}}
                                {{ $paper['document_no'] ?: $paper['source_type'] }}
                            </h2>
                            <span class="text-2xs text-(--color-ink-muted)">{{ $paper['trx_date'] }}</span>
                        </header>

                        <div class="overflow-x-auto">
                            <table class="ui-list w-full">
                                <thead>
                                    <tr class="text-start text-2xs text-(--color-ink-muted)">
                                        <th class="text-start">{{ __('inventory::field.product') }}</th>
                                        <th class="text-start">{{ __('inventory::field.warehouse') }}</th>
                                        {{-- ⓘ তিনটা ঘর কেবল তখনই, যখন ঐ গুদামে তাক বসানো আছে --}}
                                        @foreach (['depth_1', 'depth_2', 'depth_3'] as $depth)
                                            <template x-if="anyPlaces">
                                                <th class="text-start">{{ __('inventory::field.'.$depth) }}</th>
                                            </template>
                                        @endforeach
                                        <th class="text-end">{{ __('inventory::field.unplaced') }}</th>
                                        <th class="text-end">{{ __('inventory::field.unplaced_free') }}</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach ($paper['lines'] as $i => $line)
                                        @php $name = "lines[{$key}-{$i}]"; @endphp

                                        {{--
                                            ⓘ সারির নিজের অবস্থা — চারটা ঘরের নির্বাচন।
                                            `rows`-এ নিজেকে লিখিয়ে রাখে, তাই উপরের
                                            **Set** বোতামটা তাকে খুঁজে পায়। ⚠️ `w`
                                            গুদামের আইডি: Set কেবল **একই গুদামের**
                                            সারিতে বসে, নাহলে এক গুদামের র‍্যাক অন্য
                                            গুদামের সারিতে বসে যেত।
                                        --}}
                                        <tr class="border-t border-(--color-border)"
                                            x-data="{ w: {{ (int) $line['warehouse_id'] }}, block: '', rack: '', shelf: '' }"
                                            x-init="rows.push($data)">
                                            <td class="">
                                                {{ $line['product_code'] }} — {{ $line['product_name'] }}
                                                @if ($line['batch_no'])
                                                    <span class="text-2xs text-(--color-ink-muted)">
                                                        ({{ $line['batch_no'] }})
                                                    </span>
                                                @endif

                                                <input type="hidden" name="{{ $name }}[product_id]"
                                                       value="{{ $line['product_id'] }}">
                                                <input type="hidden" name="{{ $name }}[warehouse_id]"
                                                       value="{{ $line['warehouse_id'] }}">
                                                <input type="hidden" name="{{ $name }}[batch_id]"
                                                       value="{{ $line['batch_id'] }}">
                                                {{-- উৎসটাও যায়: বসানোর সারিটা মূল কাগজের
                                                     দলেই লেখা হয়, নাহলে যোগফল কাটাকাটি হত
                                                     না আর কাগজটা তালিকায় রয়ে যেত --}}
                                                <input type="hidden" name="{{ $name }}[source_type]"
                                                       value="{{ $paper['source_type'] }}">
                                                <input type="hidden" name="{{ $name }}[source_id]"
                                                       value="{{ $paper['source_id'] }}">
                                            </td>

                                            <td class="">{{ $line['warehouse_name'] }}</td>

                                            {{--
                                                ব্লক ▸ র‍্যাক ▸ শেলফ — উপরেরটা না বাছলে
                                                নিচেরটা খালি, ইচ্ছাকৃতভাবে।

                                                ⛔ "বাবা না বাছলে সব দেখাও" লিখলে গুদামের
                                                লোক অন্য র‍্যাকের শেলফ বেছে ফেলতে পারতেন,
                                                আর কার্টনটা খাতায় এক জায়গায় হাতে আরেক
                                                জায়গায় থাকত। যুক্তিটা `placement.js`-এ,
                                                তাই তার পরীক্ষা আছে।
                                            --}}
                                            @foreach (['block' => 1, 'rack' => 2, 'shelf' => 3] as $slot => $depth)
                                                <td>
                                                    <template x-if="hasPlaces(w)">
                                                        <select x-model="{{ $slot }}"
                                                                x-on:change="rowChanged($data, '{{ $slot }}')"
                                                                class="h-(--spacing-field-compact) w-32
                                                                       rounded-(--radius-field) border
                                                                       border-(--color-border)
                                                                       bg-(--color-surface-card) px-2">
                                                            <option value="">—</option>
                                                            <template x-for="o in optionsFor(w, {{ $depth }}, {{ $slot === 'block' ? 'null' : ($slot === 'rack' ? 'block' : 'rack') }})"
                                                                      :key="o.id">
                                                                <option :value="o.id" x-text="o.name"></option>
                                                            </template>
                                                        </select>
                                                    </template>
                                                </td>
                                            @endforeach

                                            {{-- ⭐ সার্ভারে যায় একটাই — সবচেয়ে গভীরটা।
                                                 উপরের ধাপগুলো `parent` বেয়ে ফেরত পাওয়া
                                                 যায়, তাই তিনটা পাঠালে একই সত্যের তিনটা
                                                 কপি যেত, আর তিন কপি একদিন আলাদা হয়ই। --}}
                                            <input type="hidden" name="{{ $name }}[storage_location_id]"
                                                   :value="deepest($data)">

                                            <td class="text-end">
                                                <input type="number" step="0.0001" min="0"
                                                       max="{{ $line['waiting'] }}"
                                                       name="{{ $name }}[qty]"
                                                       value="{{ $line['waiting'] }}"
                                                       class="num h-(--spacing-field) w-28 rounded-(--radius-field)
                                                              border border-(--color-border)
                                                              bg-(--color-surface-card) px-2 text-end">
                                            </td>

                                            <td class="text-end">
                                                @if (bccomp($line['waiting_free'], '0', 4) > 0)
                                                    <input type="number" step="0.0001" min="0"
                                                           max="{{ $line['waiting_free'] }}"
                                                           name="{{ $name }}[free_qty]"
                                                           value="{{ $line['waiting_free'] }}"
                                                           class="num h-(--spacing-field) w-28 rounded-(--radius-field)
                                                                  border border-(--color-border)
                                                                  bg-(--color-surface-card) px-2 text-end">
                                                @else
                                                    <span class="text-(--color-ink-muted)">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endforeach
            </div>

            <button type="submit"
                    class="mt-4 h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-600)
                           px-4 text-sm font-medium text-white transition-opacity hover:opacity-90">
                {{ __('inventory::action.place') }}
            </button>
        </form>
    @endif
</x-layouts.app>
