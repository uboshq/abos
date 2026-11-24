{{--
    ⭐ পাশ ফেরানো রিপোর্ট — সারিগুলো কলাম, অঙ্কের ঘরগুলো সারি ([[ReportDefinition::$transpose]]; মালিকের অনুমোদিত নমুনা, ২৩ নভেম্বর ২০২৬:
    "শাখা ধরে বিক্রয়" — শাখাগুলো পাশাপাশি, প্রতিটা হিসাব এক সারিতে, শেষে "সব")।

    ⓘ টেবিলটা [[ReportExport::transposed()]] বানায় — Excel আর কাগজও সেটাই পড়ে, তাই তিনটা কখনো আলাদা কথা বলে না।
    ⓘ প্রতিটা ঘর রিপোর্টের নিজের ঘরের ছাঁচে ([[accounts::report.partials.cell]]) — অঙ্কে চাপলে আগের মতোই উৎস খোলে; "সব"-এর ঘর মোটের দরজা।
    ⓘ নিট বিক্রির মতো যে সারিগুলো রিপোর্ট আলাদা করে দেখাতে চায় সেগুলো মোটা (রিপোর্ট নিজের ঘরে `rankBy` দিয়েছে)।
--}}
@php
    $table = \App\Core\Engines\Report\ReportExport::transposed($result, $columns);
    $money = \App\Core\Engines\Report\ReportColumn::MONEY;
@endphp

<div class="overflow-x-auto" data-transposed>
    <table class="ui-grid">
        <thead>
            <tr>
                @foreach ($table['head'] as $i => $name)
                    <th scope="col" @class(['num' => $i > 0, 'text-start' => $i === 0])>{{ $name }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($table['rows'] as $line)
                @php($column = $line['__column'])
                <tr @class(['transition-colors hover:bg-(--color-surface-hover)', 'font-semibold' => $column->key === $report->rankBy]) data-report-row="{{ $column->key }}">
                    <th scope="row" class="text-start font-medium">{{ $line['__cells'][0] }}</th>
                    @foreach ($line['__rows'] as $row)
                        <td class="num align-middle">
                            @include('accounts::report.partials.cell', ['column' => $column, 'row' => $row, 'filters' => $filters])
                        </td>
                    @endforeach
                    <td class="num font-semibold">
                        @php($all = end($line['__cells']))
                        @if ($all !== null && $all !== false)
                            @php
                                $allShown = \App\Core\Engines\Report\ReportResult::number($all, $column);
                                $allHref = $column->drillTotalHref($filters);
                            @endphp
                            @if ($allHref !== null)
                                <a href="{{ $allHref }}" class="underline decoration-dotted underline-offset-2 hover:text-(--color-brand-700)"
                                   aria-label="{{ $column->drillLabel($allShown) }}" data-report-drill-total="{{ $column->key }}">{{ $allShown }}</a>
                            @else
                                {{ $allShown }}
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
