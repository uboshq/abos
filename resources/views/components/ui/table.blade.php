{{--
    ডেস্কটপে সত্যিকারের টেবিল, মোবাইলে প্রতিটা সারি একটা card।
    একই HTML — শুধু CSS display বদলায় (সেকশন ২০.৩)।
--}}
@php
    $items = is_array($rows) ? $rows : iterator_to_array($rows);
@endphp

@if (count($items) === 0)
    <x-ui.empty-state :message="$empty" />
@else
    <div class="table-responsive">
        <table @class([
            'ui-list table-cards w-full border-collapse text-sm',
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
                            <td data-label="{{ $column['label'] }}"
                                @class(['num' => $column['numeric']])>
                                {{-- $loop->parent — ভেতরের লুপটা কলামের,
                                     বাইরেরটা সারির, আর ক্রম নম্বর চাই
                                     সারিরটাই --}}
                                {{ $cell($row, $column, $loop->parent->index) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>

            {{-- পাতার যোগফল — যে পর্দা দেয় শুধু সে-ই দেখায়।

                 আঠালো, তাই লম্বা তালিকাতেও সংখ্যাটা চোখের সামনে থাকে;
                 কোন কলামের যোগ তা বোঝা যায় কারণ ওটা ঠিক নিজের কলামেই
                 বসে। --}}
            @if ($totals !== [])
                <tfoot class="sticky bottom-0 z-[1]">
                    <tr>
                        @foreach ($normalised as $i => $column)
                            <td @class(['num' => $column['numeric']])>
                                @if ($i === 0)
                                    <span class="text-(--color-ink-muted)">{{ $totalsLabel ?? __('core.table.page_total') }}</span>
                                @else
                                    {{ $totals[$column['key']] ?? '' }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endif
