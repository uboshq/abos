{{--
    ডেস্কটপে সত্যিকারের টেবিল, মোবাইলে প্রতিটা সারি একটা card।
    একই HTML — শুধু CSS display বদলায় (সেকশন ২০.৩)।
--}}
@php
    $items = is_array($rows) ? $rows : iterator_to_array($rows);
@endphp

{{-- ⓘ "অ্যাকশন ▾ → গ্রাহককে পাঠান" ফিরে আসে এই তালিকাতেই — লিংকটা এখানে, কপি করার বোতামসহ (মালিক, ৮ নভেম্বর ২০২৬) --}}
@if (($rowMenu ?? null) || ($viewUrl ?? null))
    <x-ui.shared-link />
@endif

@if (count($items) === 0)
    @php
        /*
         * ⭐ খালি কেন — খোঁজা বা ছাঁকনির জন্য হলে পরের কাজ "ছাঁকনি মুছে সব দেখুন" (পাতা সাজানো ধাপ ১, ১০ অক্টোবর ২০২৬)।
         * ⓘ সব তালিকায় একসাথে, কোনো পাতাকে কিছু লিখতে হয় না। ⓘ দেখার পছন্দগুলো (পাতা, সাজানো, ঘনত্ব, দৃশ্য, কলাম, ট্যাব)
         * ছাঁকনি নয় — ওগুলো থাকলে "কিছু মেলেনি" বলা মিথ্যা হত।
         */
        $looks = ['page', 'sort', 'dir', 'direction', 'compact', 'view', 'show', 'per_page', 'tab', 'print', 'peek'];
        $filtered = collect(request()->query())->except($looks)->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->isNotEmpty();
    @endphp
    <x-ui.empty-state :message="$empty"
                      :hint="$filtered ? __('core.empty.filtered_hint') : null"
                      :action="$filtered ? ['label' => __('core.empty.clear_filters'), 'url' => url()->current()] : null" />
@else
    <div class="table-responsive">
        <table @class([
            'ui-list table-cards w-full border-collapse',
            'as-cards' => $grid,
            'is-dense' => $compact,
        ])>
            {{--
                আঠালো হেডার — সারি স্ক্রল করলেও কলামের নাম থাকে।

                পঞ্চাশ সারির তালিকায় নিচে নামলে কলামের নাম উপরে হারিয়ে
                যেত, আর তখন তৃতীয় সংখ্যাটা "বাকি" না "৯০+ দিন" তা মনে
                করে নিতে হত। D365, Fiori, Oracle — তিনটাতেই হেডার আটকানো।
            --}}
            <thead class="sticky top-0 z-[2]">
                <tr class="border-b border-(--color-border)">
                    @foreach ($normalised as $column)
                        {{--
                            সংখ্যার কলামে শিরোনামও ডানে।

                            ── কী আঁকাবাঁকা লাগছিল ─────────────────────────
                            এখানে সব শিরোনামে `text-start` লেখা ছিল, অথচ
                            সংখ্যাগুলো ডানে বসে। ফলে "তাকে" শিরোনামটা
                            কলামের বাঁ ধারে, আর তার সংখ্যাগুলো ডান ধারে —
                            দুইটা আলাদা খাড়া রেখা, প্রতিটা কলামে। চারটা
                            সংখ্যার কলাম মানে আটটা রেখা, আর পুরো ছকটা
                            আঁকাবাঁকা দেখায়।

                            ── কেন CSS-এর নিয়মটা কাজ করছিল না ────────────
                            `app.css`-এ `th.num { text-align: right }` আগে
                            থেকেই ছিল, কিন্তু ওটা **base স্তরে**। Tailwind-এর
                            `text-start` utility স্তরে, আর utility সবসময়
                            base-কে হারায়। নিয়মটা লেখা ছিল, কোনোদিন
                            খাটেনি — শ্রেণীটা ভুল জায়গায় বসানোর কারণে।

                            তাই সিদ্ধান্তটা এখানেই: সংখ্যা হলে `text-end`,
                            নাহলে `text-start`। দুইটা নিয়ম আর একে অন্যের
                            সাথে লড়ে না।
                        --}}
                        <th @class([
                                'text-end num' => $column['numeric'],
                                'text-start' => ! $column['numeric'],
                            ])
                            @if ($column['width']) style="width: {{ $column['width'] }}" @endif
                            scope="col">
                            {{ $column['label'] }}
                        </th>
                    @endforeach
                    {{-- ⭐ শুধু চোখ দেওয়া তালিকাও এখন "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro") --}}
                    @if (($rowMenu ?? null) || ($viewUrl ?? null))
                        <th class="text-end" style="width: 7.5rem" scope="col" data-row-menu>{{ __('core.table.action_menu') }}</th>
                    @endif
                </tr>
            </thead>

            <tbody>
                @foreach ($items as $row)
                    <tr class="border-b border-(--color-border)">
                        @foreach ($normalised as $column)
                            {{-- data-label ছাড়া মোবাইলে এই ঘরটা অর্থহীন হয়ে যায় --}}
                            {{-- উচ্চতা, প্যাডিং ও রং এখানে নেই — সবটা
                                 `.table-cards`-এর নিয়মে, app.css-এ।

                                 এখানে utility হিসেবে লেখা থাকলে কোনো
                                 থিম ওগুলো ছুঁতে পারত না: Tailwind-এর
                                 utility সব @layer-কে হারায়। তখন থিম
                                 বদলে রং বদলাত, আর ঘনত্ব ও ধার আগের
                                 মতোই বসে থাকত — অর্ধেক বদল, যেটা কোনো
                                 বদল না হওয়ার চেয়ে খারাপ দেখায়। --}}
                            {{--
                                ⭐ যে কলাম বাক্য ধরে রাখে, সে ভাঙতে পারে।

                                ⓘ ডিফল্টে কোনো ঘর ভাঙে না (CSS-এ কারণটা
                                লেখা — নাম ভেঙে দুই লাইনে যেত)। ⚠️ কিন্তু
                                বিবরণ, মন্তব্য বা ঠিকানা এক লাইনে রাখলে
                                ছকটা পর্দার বাইরে চলে যেত।

                                ⓘ কলামটা নিজে বলে (`'wrap' => true`), আর
                                না বললে ভাঙে না — নিরাপদ দিকটা "নাম
                                অক্ষত", কারণ ওটা দিয়েই সারিটা চেনা যায়।
                            --}}
                            {{-- $loop->parent — ভেতরের লুপটা কলামের,
                                 বাইরেরটা সারির, আর ক্রম নম্বর চাই
                                 সারিরটাই --}}
                            @php $value = $cell($row, $column, $loop->parent->index); @endphp
                            {{-- ⓘ ফোনের কার্ডে ফাঁকা ঘর আঁকা হয় না — কেবল "পক্ষ", "ক্রেডিট" নাম আর পাশে কিছু নেই, প্রতিটা কার্ডে দুই-তিন লাইন
                                 বাজে জায়গা (জাবেদার লাইন, ৩০ অক্টোবর ২০২৬, দফা ২১)। টেবিলে (md+) ঘরটা থাকে, নাহলে কলাম সরে যেত। --}}
                            <td data-label="{{ $column['label'] }}"
                                @if ($column['wrap'] ?? false) data-wrap @endif
                                @if (trim((string) $value) === '') data-empty @endif
                                @class(['num' => $column['numeric']])>
                                {{ $value }}
                            </td>
                        @endforeach
                        {{-- ⭐ "দেখুন" — সাধারণ লিংক, তাই মালিকের নিয়মে তালিকার উপর পপআপে খোলে ([[peek]]);
                             লেবেলে কাগজের নম্বর, যাতে স্ক্রিন-রিডার বলে কোনটা খুলবে। --}}
                        @if ($rowMenu ?? null)
                            <td data-label="{{ __('core.table.action_menu') }}" class="text-end" data-row-menu>{{ ($rowMenu)($row) }}</td>
                        @elseif ($viewUrl ?? null)
                            {{--
                                ⭐ সাধারণ "অ্যাকশন ▾" — যে তালিকা কেবল দেখার ঠিকানা দেয় (আগে একটা 👁), তারও এখন কাগজের তালিকার একই মেনু
                                (মালিক, ৯ নভেম্বর ২০২৬: "Abos er sokol list sob skin dekhe deke update koro"): মাথায় নম্বর/কোড, তারপর দেখা —
                                দেখুন (পুরো পাতা), প্রিভিউ (পাশে), আর অডিটের চাবি থাকলে ইতিহাস। ⓘ নিজের কাজ (সম্পাদনা, বাতিল …) যে তালিকার আছে
                                সে `:row-menu` দেয় ([[x-ui.paper-row-menu]]); এখানে কেবল যা সব তালিকায় সত্য।
                            --}}
                            @php
                                $viewHref = ($viewUrl)($row);
                                $rowNo = (string) (data_get($row, 'document_no') ?? data_get($row, 'code') ?? data_get($row, 'no') ?? '');
                                $menu = [];
                                if ($viewHref) {
                                    $menu[] = \App\Core\Support\RowMenu::head($rowNo !== '' ? $rowNo : (string) (data_get($row, 'name') ?? ''), '');
                                    $menu[] = \App\Core\Support\RowMenu::group('look');
                                    $menu[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => $viewHref, 'peek' => false, 'key' => 'Enter', 'opens' => true];
                                    $menu[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => $viewHref];
                                    if ($rowNo !== '' && auth()->user()?->can('governance.audit.view') && \Illuminate\Support\Facades\Route::has('governance.audit.index')) {
                                        $menu[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock', 'url' => route('governance.audit.index', ['q' => $rowNo]), 'peek' => false];
                                    }
                                }
                            @endphp
                            <td data-label="{{ __('core.table.action_menu') }}" class="text-end" data-row-menu data-row-menu-generic>
                                @if ($menu !== [])
                                    <x-ui.row-actions :items="$menu" :label="__('core.table.action_menu')" />
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>

            {{-- পাতার যোগফল — যে পর্দা দেয় শুধু সে-ই দেখায়।

                 আঠালো, তাই লম্বা তালিকাতেও সংখ্যাটা চোখের সামনে থাকে;
                 কোন কলামের যোগ তা বোঝা যায় কারণ ওটা ঠিক নিজের কলামেই
                 বসে। --}}
            {{-- ⭐ সর্বমোট — গোটা ছাঁকা তালিকার (মালিক, ১ অক্টোবর ২০২৬); কয়েক পাতা হলে তার উপরে "এই পাতা"।
                 ⓘ সংখ্যা সারির মতোই সাজানো (টাকা টাকার মতো, পরিমাণ পরিমাণের মতো), ঋণাত্মক লাল। --}}
            {{-- ⓘ একটাই ফুটার: পর্দার নিজের যোগের সারি (`:totals`, যেমন হেফাজতের "পথে থাকা") আগে, সর্বমোট শেষে —
                 দুইটা <tfoot> হলে সর্বমোট মাঝে বসত, অথচ সেটা উপরের সব কিছুর যোগ। --}}
            @php $withGrand = isset($hasGrand) && $hasGrand(); @endphp
            @if ($withGrand || $totals !== [])
                <tfoot class="sticky bottom-0 z-[1]" @if ($withGrand) data-grand-total @endif>
                    @if ($totals !== [])
                        <tr>
                            @foreach ($normalised as $i => $column)
                                {{-- ⓘ ফোনের কার্ডে নাম ছাড়া যোগের সারি কেবল দুইটা সংখ্যা — কোনটা ডেবিট বোঝা যেত না (দফা ২১) --}}
                                @php $sum = $i > 0 ? (string) ($totals[$column['key']] ?? '') : null; @endphp
                                <td @if ($sum !== null && $sum !== '') data-label="{{ $column['label'] }}" @endif
                                    @if ($sum === '') data-empty @endif
                                    @class(['num' => $column['numeric']])>
                                    @if ($i === 0)
                                        <span class="text-(--color-ink-muted)">{{ $totalsLabel ?? __('core.table.page_total') }}</span>
                                    @else
                                        {{ $totals[$column['key']] ?? '' }}
                                    @endif
                                </td>
                            @endforeach
                            @if (($viewUrl ?? null) || ($rowMenu ?? null))
                                <td></td>
                            @endif
                        </tr>
                    @endif
                    @foreach ($withGrand ? array_filter([$showsPageTotal() ? $pageLine() : null, $grandLine()]) : [] as $k => $line)
                        <tr @class(['font-semibold' => $loop->last]) @if ($loop->last) data-grand-total-row @endif>
                            @foreach ($normalised as $i => $column)
                                <td data-label="{{ $column['label'] }}" @class(['num' => $column['numeric'],
                                    'text-(--color-danger)' => $column['total'] !== null && \App\View\Components\Ui\Table::negative($line[$i])])>
                                    @if ($i === 0 && $column['total'] === null)
                                        <span class="text-(--color-ink-muted)">{{ $line[$i] }}</span>
                                    @else
                                        {{ $line[$i] }}
                                    @endif
                                </td>
                            @endforeach
                            @if (($viewUrl ?? null) || ($rowMenu ?? null))
                                <td></td>
                            @endif
                        </tr>
                    @endforeach
                </tfoot>
            @endif
        </table>
    </div>
@endif
