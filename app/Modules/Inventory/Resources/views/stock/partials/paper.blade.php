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
            </summary>

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
                        <th class="text-end">{{ __('core.table.actions') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($paper['lines'] as $i => $line)
                        @php $name = "lines[{$i}]"; @endphp

                        {{--
                            ⓘ সারির নিজের অবস্থা — চারটা ঘরের নির্বাচন।
                            `rows`-এ নিজেকে লিখিয়ে রাখে, তাই উপরের **Set**
                            বোতামটা তাকে খুঁজে পায়। ⚠️ `w` গুদামের আইডি: Set
                            কেবল **একই গুদামের** সারিতে বসে, নাহলে এক গুদামের
                            র‍্যাক অন্য গুদামের সারিতে বসে যেত।
                        --}}
                        <tr class="border-t border-(--color-border)"
                            x-data="{ w: {{ (int) $line['warehouse_id'] }}, block: '', rack: '', shelf: '' }"
                            x-init="rows.push($data)">
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
                                </td>
                            @endforeach

                            {{-- ⭐ সার্ভারে যায় একটাই — সবচেয়ে গভীরটা। উপরের
                                 ধাপগুলো `parent` বেয়ে ফেরত পাওয়া যায়, তাই তিনটা
                                 পাঠালে একই সত্যের তিনটা কপি যেত, আর তিন কপি
                                 একদিন আলাদা হয়ই। --}}
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
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button type="submit"
                class="mt-4 h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-600)
                       px-4 text-sm font-medium text-white transition-opacity hover:opacity-90">
            {{ __('inventory::action.place') }}
        </button>
        </details>
    </form>
</section>
