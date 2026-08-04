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
                        <th @class([
                                'px-3 py-2 text-start font-medium text-(--color-ink-muted) whitespace-nowrap',
                                'num' => $column['numeric'],
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
                                {{ $cell($row, $column) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
