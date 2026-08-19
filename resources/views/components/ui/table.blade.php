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
        <table @class(['table-cards w-full border-collapse text-sm', 'as-cards' => $grid])>
            <thead>
                <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
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
                                'px-3 py-2 font-medium text-(--color-ink-muted) whitespace-nowrap',
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
                    <tr class="border-b border-(--color-border) transition-colors hover:bg-(--color-surface-hover)">
                        @foreach ($normalised as $column)
                            {{-- data-label ছাড়া মোবাইলে এই ঘরটা অর্থহীন হয়ে যায় --}}
                            <td data-label="{{ $column['label'] }}"
                                @class([
                                    'px-3 align-middle',
                                    'py-1.5' => $compact,
                                    'py-2.5' => ! $compact,
                                    'num' => $column['numeric'],
                                ])>
                                {{-- $loop->parent — ভেতরের লুপটা কলামের,
                                     বাইরেরটা সারির, আর ক্রম নম্বর চাই
                                     সারিরটাই --}}
                                {{ $cell($row, $column, $loop->parent->index) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
