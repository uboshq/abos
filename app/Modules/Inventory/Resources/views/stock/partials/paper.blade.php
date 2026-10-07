{{--
    একটা কাগজ — মাথায় চারটা কথা, নিচে তার সারিগুলো।

    ── ⭐ মালিকের ছবি, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
    মাথায় থাকে কার কাগজ, কোন চালান, কবে, আর কে প্রক্রিয়া করলেন। ⓘ
    গুদামের লোক হাতে একটা কাগজ নিয়ে দাঁড়ান — পর্দায় ঠিক ঐ কাগজটাই
    খুঁজে নিতে পারা চাই, আর নম্বর মিলিয়ে দেখতে পারা চাই।

    ── ⚠️ প্রতিটা কাগজের নিজের ফর্ম, ইচ্ছাকৃতভাবে ────────────────────
    একটা ফর্মে সব কাগজ থাকলে একটা চাপে সব কাগজের মাল বসে যেত, অথচ
    গুদামের লোক তখন হাতে একটাই কাগজ ধরে আছেন। ⓘ আলাদা ফর্ম মানে
    "Set Stock" কেবল এই কাগজটাই বসায়। ⭐ আর সারির তিরটা কেবল ঐ একটা
    সারি — দশ কার্টনের আটটা বসানোর পথ সেটাই।
--}}
<section data-boxed
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <form method="POST" action="{{ route('inventory.stock.placement.store') }}">
        @csrf

        {{-- ── মাথার চারটা কথা ───────────────────────────────────────── --}}
        <header class="mb-3 grid gap-x-6 gap-y-1 border-b border-(--color-border) pb-3
                       text-sm sm:grid-cols-3">
            <div>
                <span class="text-(--color-ink-muted)">{{ __('inventory::field.party') }}:</span>
                <span class="font-semibold">{{ $paper['party'] ?? $paper['source_type'] }}</span>
            </div>

            <div>
                <span class="text-(--color-ink-muted)">{{ __('inventory::field.paper_no') }}:</span>
                {{-- ⓘ কাগজে যাওয়ার পথ — যে মডিউল কাগজটা বানায়, সে-ই ঠিকানা দেয় --}}
                @if (($paper['route'] ?? null) === null)
                    <span class="font-semibold">{{ $paper['document_no'] ?: '—' }}</span>
                @else
                    <a href="{{ route($paper['route'][0], $paper['route'][1] ?? []) }}"
                       class="font-semibold text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ $paper['document_no'] ?: '—' }}
                    </a>
                @endif
            </div>

            <div>
                <span class="text-(--color-ink-muted)">{{ __('inventory::field.date') }}:</span>
                <span>{{ \App\Core\Support\DateFormat::format($paper['trx_date']) }}</span>
            </div>

            <div class="sm:col-span-2">
                <span class="text-(--color-ink-muted)">{{ __('inventory::field.paper_id') }}:</span>
                <span class="num text-2xs">{{ $paper['source_type'] }}:{{ $paper['source_id'] }}</span>
            </div>

            <div>
                <span class="text-(--color-ink-muted)">{{ __('inventory::field.processed_by') }}:</span>
                <span>{{ $paper['by'] ?? '—' }}</span>
            </div>
        </header>

        {{-- ⭐ সারিগুলো গোটানো থাকে — মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬।

             ⛔ আগে প্রতিটা কাগজের সব সারি একসাথে খোলা থাকত। ⚠️ দশটা কাগজ
             জমলে পাতাটা এত লম্বা হত যে গুদামের লোক হাতের কাগজটা খুঁজেই
             পেতেন না — অথচ তিনি একবারে **একটাই** কাগজ বসান।

             ⓘ মাথার চারটা কথা (কার · কোন চালান · কবে · কে) গোটানো
             অবস্থাতেও দেখা যায়, কারণ ঐটুকু দিয়েই কাগজ চেনা হয়।

             ⚠️ `<details>` ইচ্ছাকৃত, Alpine নয়: সারিগুলো বন্ধ অবস্থাতেও
             DOM-এ থাকে, তাই উপরের "সবার জন্য" বারটা ওদের পায়, আর
             JavaScript বন্ধ থাকলেও কাগজটা খোলা-বন্ধ করা যায়। --}}
        <details class="group" @if ($first ?? false) open @endif>
            <summary class="-mx-1 flex cursor-pointer items-center gap-2 rounded-(--radius-field)
                            px-1 py-2 text-sm text-(--color-ink-muted)
                            hover:bg-(--color-surface-muted)">
                <x-ui.icon name="chevron_down"
                           class="size-4 transition-transform group-open:rotate-180" />

                {{ trans_choice('inventory::message.lines_waiting', count($paper['lines']),
                    ['count' => count($paper['lines'])]) }}
                {{-- ⭐ মোট — গোটানো অবস্থাতেও (মালিক, ৭ অক্টোবর ২০২৬: *"total qty, grand total দেখায় না; value দেখালেও ভালো"*) --}}
                <span class="ms-auto flex flex-wrap gap-x-4 text-(--color-ink)" data-paper-total>
                    <span>{{ __('inventory::field.total_qty') }}: <b class="num">{{ \App\Core\Support\Money::quantity($paper['total_qty'] ?? '0') }}</b></span>
                    @if (bccomp((string) ($paper['total_free'] ?? '0'), '0', 4) > 0)
                        <span>{{ __('inventory::field.unplaced_free') }}: <b class="num">{{ \App\Core\Support\Money::quantity($paper['total_free']) }}</b></span>
                    @endif
                    @if (($paper['total_value'] ?? null) !== null)
                        <span>{{ __('inventory::field.total_value') }}: <b class="num">৳{{ \App\Core\Support\Money::format($paper['total_value']) }}</b></span>
                    @endif
                </span>
            </summary>

        <div class="overflow-x-auto">
            <table class="ui-list w-full">
                <thead>
                    <tr class="text-start text-2xs text-(--color-ink-muted)">
                        <th class="text-start">{{ __('inventory::field.product') }}</th>
                        <th class="text-start">{{ __('inventory::field.warehouse') }}</th>
                        {{-- ⭐ তিনটা ঘর সবসময় — মালিকের ছবি, ২৮ সেপ্টেম্বর ২০২৬: *"eto elo melo keno"*।
                             ⛔ আগে মাথার ঘর তিনটা আসত কেবল তাক থাকলে, অথচ নিচের সারিতে ঘর
                             তিনটা সবসময় — ফলে মাথা আর ঘর তিন কলাম সরে যেত। ⓘ এখন দুই দিকে
                             একই সংখ্যা; তাক না থাকলে ঘরে কেবল "—"। --}}
                        @foreach (['depth_1', 'depth_2', 'depth_3'] as $depth)
                            <th class="text-start">{{ __('inventory::field.'.$depth) }}</th>
                        @endforeach
                        <th class="text-end">{{ __('inventory::field.unplaced') }}</th>
                        <th class="text-end">{{ __('inventory::field.unplaced_free') }}</th>
                        <th class="text-end">{{ __('core.table.actions') }}</th>
                    </tr>
                </thead>

                    @foreach ($paper['lines'] as $i => $line)
                        @php $name = "lines[{$i}]"; @endphp

                        {{--
                            ⓘ সারির নিজের অবস্থা — চারটা ঘরের নির্বাচন।
                            `rows`-এ নিজেকে লিখিয়ে রাখে, তাই উপরের **Set**
                            বোতামটা তাকে খুঁজে পায়। ⚠️ `w` গুদামের আইডি: Set
                            কেবল **একই গুদামের** সারিতে বসে, নাহলে এক গুদামের
                            র‍্যাক অন্য গুদামের সারিতে বসে যেত।
                        --}}
                        {{-- ⓘ প্রতিটা পণ্যের নিজের `<tbody>` — মূল সারি আর তার উপ-সারিগুলো এক অবস্থা
                             ভাগ করে (`splits`), আর উপরের Set কেবল মূল সারিতে বসে। --}}
                        <tbody x-data="{ w: {{ (int) $line['warehouse_id'] }}, block: '', rack: '', shelf: '',
                                         qty: @js((string) $line['waiting']), free: @js((string) $line['waiting_free']),
                                         splits: [] }"
                               x-init="rows.push($data)">
                        <tr class="border-t border-(--color-border)">
                            <td>
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
                                {{-- উৎসটাও যায়: বসানোর সারিটা মূল কাগজের দলেই
                                     লেখা হয়, নাহলে যোগফল কাটাকাটি হত না আর
                                     কাগজটা তালিকায় রয়ে যেত --}}
                                {{-- ⚠️ সারির নিজের উৎস, কার্ডের নয়।

                                     ⓘ এক কাগজে টাকার মাল আর ফ্রি মাল দুই উৎসে
                                     আসে (`…` আর `…:free`), আর বসানোর সারিটা
                                     ঠিক সেই উৎসেই লিখতে হয় — নাহলে যোগফল
                                     কাটাকাটি হয় না আর কাগজটা তালিকা থেকে
                                     কোনোদিন সরে না। --}}
                                <input type="hidden" name="{{ $name }}[source_type]"
                                       value="{{ $line['source_type'] ?? $paper['source_type'] }}">
                                <input type="hidden" name="{{ $name }}[source_id]"
                                       value="{{ $paper['source_id'] }}">
                                {{-- ⓘ টাকা আর ফ্রি এক সারিতে হলে ফ্রি মালের নিজের উৎস --}}
                                @if (($line['free_source_type'] ?? null) !== null)
                                    <input type="hidden" name="{{ $name }}[free_source_type]"
                                           value="{{ $line['free_source_type'] }}">
                                @endif
                            </td>

                            <td>{{ $line['warehouse_name'] }}</td>

                            {{--
                                ব্লক ▸ র‍্যাক ▸ শেলফ — উপরেরটা না বাছলে নিচেরটা
                                খালি, ইচ্ছাকৃতভাবে।

                                ⛔ "বাবা না বাছলে সব দেখাও" লিখলে গুদামের লোক
                                অন্য র‍্যাকের শেলফ বেছে ফেলতে পারতেন, আর
                                কার্টনটা খাতায় এক জায়গায় হাতে আরেক জায়গায়
                                থাকত। যুক্তিটা `placement.js`-এ, তাই তার
                                পরীক্ষা আছে।
                            --}}
                            @foreach (['block' => 1, 'rack' => 2, 'shelf' => 3] as $slot => $depth)
                                <td>
                                    <template x-if="hasPlaces(w)">
                                        <select x-model="{{ $slot }}"
                                                x-on:change="rowChanged($data, '{{ $slot }}')"
                                                class="h-(--spacing-field-compact) w-32 rounded-(--radius-field)
                                                       border border-(--color-border)
                                                       bg-(--color-surface-card) px-2">
                                            <option value="">—</option>
                                            <template x-for="o in optionsFor(w, {{ $depth }}, {{ $slot === 'block' ? 'null' : ($slot === 'rack' ? 'block' : 'rack') }})"
                                                      :key="o.id">
                                                <option :value="o.id" x-text="o.name"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="! hasPlaces(w)">
                                        <span class="text-(--color-ink-muted)">—</span>
                                    </template>
                                </td>
                            @endforeach

                            {{-- ⭐ সার্ভারে যায় একটাই — সবচেয়ে গভীরটা। উপরের
                                 ধাপগুলো `parent` বেয়ে ফেরত পাওয়া যায়, তাই তিনটা
                                 পাঠালে একই সত্যের তিনটা কপি যেত, আর তিন কপি
                                 একদিন আলাদা হয়ই। --}}
                            <input type="hidden" name="{{ $name }}[storage_location_id]"
                                   :value="deepest($data)">

                            {{-- ⓘ বসানোর কিছু না থাকলে ঘর নয়, "—" — শূন্যের ঘর দেখতে কাজ বাকি
                                 মনে হত (মালিকের ছবি, ২৮ সেপ্টেম্বর ২০২৬)। --}}
                            <td class="text-end">
                                @if (bccomp($line['waiting'], '0', 4) > 0)
                                    <input type="number" step="0.0001" min="0"
                                           max="{{ $line['waiting'] }}"
                                           name="{{ $name }}[qty]"
                                           value="{{ $line['waiting'] }}" x-model="qty"
                                           class="num h-(--spacing-field) w-28 rounded-(--radius-field)
                                                  border border-(--color-border)
                                                  bg-(--color-surface-card) px-2 text-end">
                                @else
                                    <input type="hidden" name="{{ $name }}[qty]" value="0">
                                    <span class="text-(--color-ink-muted)">—</span>
                                @endif
                            </td>

                            <td class="text-end">
                                @if (bccomp($line['waiting_free'], '0', 4) > 0)
                                    <input type="number" step="0.0001" min="0"
                                           max="{{ $line['waiting_free'] }}"
                                           name="{{ $name }}[free_qty]"
                                           value="{{ $line['waiting_free'] }}" x-model="free"
                                           class="num h-(--spacing-field) w-28 rounded-(--radius-field)
                                                  border border-(--color-border)
                                                  bg-(--color-surface-card) px-2 text-end">
                                @else
                                    <span class="text-(--color-ink-muted)">—</span>
                                @endif
                            </td>

                            {{-- ⭐ কেবল এই সারিটা — মালিকের ছবির তিরচিহ্ন।
                                 ⓘ বোতামের নিজের নাম-মান ফর্মের সাথে যায়, তাই
                                 কোনো JS লাগে না: সার্ভার `only` দেখে বাকিগুলো
                                 ফেলে দেয়। ⚠️ দশ কার্টনের আটটা আজ, বাকি দুইটা
                                 কাল — এই পথটা না থাকলে পুরো কাগজ আটকে থাকত। --}}
                            <td class="text-end">
                                <button type="submit" name="only" value="{{ $i }}"
                                        title="{{ __('inventory::action.place_row') }}"
                                        class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field)
                                               px-2 text-sm text-(--color-link) transition-colors
                                               hover:bg-(--color-surface-hover)">
                                    {{ __('inventory::action.place_row') }}
                                </button>

                                <button type="button" x-on:click="addSplit($data)"
                                        class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field)
                                               px-2 text-sm text-(--color-brand-500) hover:bg-(--color-surface-hover)">
                                    {{ __('inventory::action.add_split_place') }}
                                </button>
                            </td>
                        </tr>

                        {{-- ⭐ এক পণ্য, একাধিক জায়গা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬। ⓘ প্রতিটা
                             উপ-সারি সার্ভারে আলাদা সারি (`lines[3_1]`), নিজের তাক ও পরিমাণসহ; মোট
                             বসেনি-র বেশি হলে পাতাতেই সতর্কতা, আর সার্ভারও নেয় না ([[StockService::place()]])। --}}
                        <template x-for="(s, n) in splits" :key="n">
                            <tr class="border-t border-dashed border-(--color-border) bg-(--color-surface-muted)">
                                <td class="ps-6 text-sm text-(--color-ink-muted)">
                                    ↳ {{ __('inventory::field.another_place') }}
                                        <input type="hidden" :name="splitName({{ $i }}, n, 'product_id')" value="{{ $line['product_id'] }}">
                                        <input type="hidden" :name="splitName({{ $i }}, n, 'warehouse_id')" value="{{ $line['warehouse_id'] }}">
                                        <input type="hidden" :name="splitName({{ $i }}, n, 'batch_id')" value="{{ $line['batch_id'] }}">
                                        <input type="hidden" :name="splitName({{ $i }}, n, 'source_type')" value="{{ $line['source_type'] ?? $paper['source_type'] }}">
                                        <input type="hidden" :name="splitName({{ $i }}, n, 'source_id')" value="{{ $paper['source_id'] }}">
                                    @if (($line['free_source_type'] ?? null) !== null)
                                        <input type="hidden" :name="splitName({{ $i }}, n, 'free_source_type')"
                                               value="{{ $line['free_source_type'] }}">
                                    @endif
                                    <input type="hidden" :name="splitName({{ $i }}, n, 'storage_location_id')"
                                           :value="deepest(s)">
                                </td>

                                <td>{{ $line['warehouse_name'] }}</td>

                                    <td>
                                        <template x-if="hasPlaces(w)">
                                            <select x-model="s.block"
                                                    x-on:change="rowChanged(s, 'block')"
                                                    class="h-(--spacing-field-compact) w-32 rounded-(--radius-field)
                                                           border border-(--color-border)
                                                           bg-(--color-surface-card) px-2">
                                                <option value="">—</option>
                                                <template x-for="o in optionsFor(w, 1, null)" :key="o.id">
                                                    <option :value="o.id" x-text="o.name"></option>
                                                </template>
                                            </select>
                                        </template>
                                        <template x-if="! hasPlaces(w)">
                                            <span class="text-(--color-ink-muted)">—</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="hasPlaces(w)">
                                            <select x-model="s.rack"
                                                    x-on:change="rowChanged(s, 'rack')"
                                                    class="h-(--spacing-field-compact) w-32 rounded-(--radius-field)
                                                           border border-(--color-border)
                                                           bg-(--color-surface-card) px-2">
                                                <option value="">—</option>
                                                <template x-for="o in optionsFor(w, 2, s.block)" :key="o.id">
                                                    <option :value="o.id" x-text="o.name"></option>
                                                </template>
                                            </select>
                                        </template>
                                        <template x-if="! hasPlaces(w)">
                                            <span class="text-(--color-ink-muted)">—</span>
                                        </template>
                                    </td>
                                    <td>
                                        <template x-if="hasPlaces(w)">
                                            <select x-model="s.shelf"
                                                    x-on:change="rowChanged(s, 'shelf')"
                                                    class="h-(--spacing-field-compact) w-32 rounded-(--radius-field)
                                                           border border-(--color-border)
                                                           bg-(--color-surface-card) px-2">
                                                <option value="">—</option>
                                                <template x-for="o in optionsFor(w, 3, s.rack)" :key="o.id">
                                                    <option :value="o.id" x-text="o.name"></option>
                                                </template>
                                            </select>
                                        </template>
                                        <template x-if="! hasPlaces(w)">
                                            <span class="text-(--color-ink-muted)">—</span>
                                        </template>
                                    </td>

                                <td class="text-end">
                                    @if (bccomp($line['waiting'], '0', 4) > 0)
                                        <input type="number" step="0.0001" min="0" max="{{ $line['waiting'] }}"
                                               :name="splitName({{ $i }}, n, 'qty')" x-model="s.qty"
                                               class="num h-(--spacing-field) w-28 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    @else
                                        <span class="text-(--color-ink-muted)">—</span>
                                    @endif
                                </td>

                                <td class="text-end">
                                    @if (bccomp($line['waiting_free'], '0', 4) > 0)
                                        <input type="number" step="0.0001" min="0" max="{{ $line['waiting_free'] }}"
                                               :name="splitName({{ $i }}, n, 'free_qty')" x-model="s.free"
                                               class="num h-(--spacing-field) w-28 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    @else
                                        <span class="text-(--color-ink-muted)">—</span>
                                    @endif
                                </td>

                                <td class="text-end">
                                    <button type="button" x-on:click="removeSplit($data, n)"
                                            class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field)
                                                   px-2 text-sm text-(--color-danger) hover:bg-(--color-surface-hover)">
                                        {{ __('inventory::action.remove_split_place') }}
                                    </button>
                                </td>
                            </tr>
                        </template>

                        <template x-if="isOver($data, '{{ $line['waiting'] }}', '{{ $line['waiting_free'] }}')">
                            <tr>
                                <td colspan="8" role="alert"
                                    class="bg-(--color-badge-danger-bg) px-3 py-1.5 text-xs text-(--color-badge-danger-ink)">
                                    {{ __('inventory::message.split_over') }}
                                </td>
                            </tr>
                        </template>
                        </tbody>
                    @endforeach
                {{-- ⭐ কাগজের মোট — বসেনি আর বসেনি (ফ্রি)-র ঠিক নিচে --}}
                <tfoot>
                    <tr class="border-t-2 border-(--color-border) font-semibold">
                        <td class="text-end">
                            {{ __('inventory::field.total') }}
                            @if (($paper['total_value'] ?? null) !== null)
                                <span class="ms-3 text-(--color-ink-muted)">{{ __('inventory::field.total_value') }}:</span>
                                <span class="num">৳{{ \App\Core\Support\Money::format($paper['total_value']) }}</span>
                            @endif
                        </td>
                        <td></td><td></td><td></td><td></td>
                        <td class="num text-end">{{ \App\Core\Support\Money::quantity($paper['total_qty'] ?? '0') }}</td>
                        <td class="num text-end">{{ bccomp((string) ($paper['total_free'] ?? '0'), '0', 4) > 0 ? \App\Core\Support\Money::quantity($paper['total_free']) : '—' }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- ⭐ "বুঝে নিলাম" একদম ডানে — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬; সারির "এই সারিটা
             বসাও" বোতামগুলোর একই কলামের নিচে, তাই চোখ এক সারিতেই থাকে। --}}
        <div class="mt-4 flex justify-end">
            <button type="submit"
                    class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-600)
                           px-4 text-sm font-medium text-white transition-opacity hover:opacity-90">
                {{ __('inventory::action.place') }}
            </button>
        </div>
        </details>
    </form>
</section>
