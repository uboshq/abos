{{--
    রিপোর্টের একটা ঘর — ধরন অনুযায়ী।

    ডকুমেন্ট নম্বর ক্লিকযোগ্য (নিয়ম ১): সংজ্ঞায় source_type ও source_id
    কোন কলামে আছে তা বলা থাকে, আর x-ui.drill বাকিটা জানে। এখানে কোনো
    রুট বাছা হয় না, তাই নতুন ডকুমেন্ট টাইপ যোগ হলে এই ফাইলটা ছুঁতে
    হয় না।
--}}
@php
    $value = $row[$column->key] ?? null;
    // ⭐ অঙ্কে চাপলে উৎস ([[ReportColumn::$drill]]; মালিক, ২৪ অক্টোবর ২০২৬) — শূন্যও খোলে (ফাঁকা তালিকা), তাই শূন্যও দেখায়
    $href = $column->hasDrill() ? $column->drillHref($row, $filters ?? []) : null;
@endphp

@switch ($column->type)
    @case (\App\Core\Engines\Report\ReportColumn::MONEY)
        {{-- শূন্য দেখানো হয় না: একটা কলামে অর্ধেক সারিতে "0.00" থাকলে
             চোখ প্রতিটাতে থামে, অথচ ওগুলোর কোনো মানে নেই — ⓘ যে ঘরে চাপলে উৎস খোলে, সেখানে বাদে --}}
        @php
            // ⓘ আগের মতোই Money::format — কেবল ৳ যোগ হয় যে কলাম চায় ([[ReportColumn::$currency]]); অন্য রিপোর্টের ঘর অপরিবর্তিত
            $shown = $value !== null && ($href !== null || bccomp((string) $value, '0', 4) !== 0)
                ? ($column->currency ? '৳' : '').\App\Core\Support\Money::format($value) : '';
        @endphp
        @if ($href !== null && $shown !== '')
            <a href="{{ $href }}" class="underline decoration-dotted underline-offset-2 hover:text-(--color-brand-700)"
               aria-label="{{ $column->drillLabel($shown) }}" data-report-drill="{{ $column->key }}">{{ $shown }}</a>
        @else
            {{ $shown }}
        @endif
        @break

    @case (\App\Core\Engines\Report\ReportColumn::DR_CR)
        {{-- ⓘ জের — "(Dr) 250.79" / "(Cr) 22,958.21", খালি বিয়োগ নয় (মালিক, ৩ অক্টোবর ২০২৬) --}}
        {{-- ⓘ ঘোষিত শব্দ থাকলে "দিতে হবে ৳…" / "কোম্পানির কাছে পাব ৳…" ([[ReportColumn::signed()]]) --}}
        @php
            $shown = $value === null || $value === '' ? '' : $column->signed($value);
        @endphp
        @if ($href !== null && $shown !== '')
            <a href="{{ $href }}" class="underline decoration-dotted underline-offset-2 hover:text-(--color-brand-700)"
               aria-label="{{ $column->drillLabel($shown) }}" data-report-drill="{{ $column->key }}">{{ $shown }}</a>
        @else
            {{ $shown }}
        @endif
        @break

    @case (\App\Core\Engines\Report\ReportColumn::QUANTITY)
        {{-- ⛔ আগে এই ধরনের কোনো ঘর ছিল না — কাঁচা "3796.0000" ছাপা হত (মালিক, ৩০ সেপ্টেম্বর ২০২৬);
             ⭐ এখন ৩,৭৯৬ বা ১২.৫ ([[ReportResult::number()]]) --}}
        {{ $value === null || $value === '' ? '' : \App\Core\Engines\Report\ReportResult::number($value, $column) }}
        @break
    @case (\App\Core\Engines\Report\ReportColumn::PERCENT)
        {{--
            খালি মানে শূন্য নয়।

            আগের সময়ে সারিটাই ছিল না — নতুন একটা ক্রেতা, নতুন একটা পণ্য।
            শতাংশে সেটা অসীম, আর "০%" লিখলে মিথ্যা বলা হত: ০% মানে
            "একই রইল", আর এখানে ব্যাপারটা ঠিক উল্টো।
        --}}
        @if ($value === null || $value === '')
            <span class="text-(--color-ink-muted)">{{ __('core.report.new_in_period') }}</span>
        @else
            {{-- "+" বসানো হয় না: একই ছকে "অবদান %" আর "পরিবর্তন %" দুইটাই
                 বসে, আর অবদানের গায়ে যোগ চিহ্ন অর্থহীন। কমা দেখাতে
                 বিয়োগ চিহ্নটাই যথেষ্ট, আর লেবেলটা বলে দেয় কোনটা কী --}}
            {{ \App\Core\Support\Money::format($value, 2) }}%
        @endif
        @break

    @case (\App\Core\Engines\Report\ReportColumn::DATE)
        {{ \App\Core\Support\DateFormat::format($value) }}
        @break

    @case (\App\Core\Engines\Report\ReportColumn::DOCUMENT)
        <x-ui.drill :source="$column->sourceTypeKey ? ($row[$column->sourceTypeKey] ?? null) : null"
                    :id="$column->sourceIdKey ? ($row[$column->sourceIdKey] ?? null) : null">
            {{ $value }}
        </x-ui.drill>
        @break

    @default
        {{-- ⭐ পক্ষের ঘর — এক লাইনে, কাটা পড়লে পুরো নাম `title`-এ (মালিক, ১৯ অক্টোবর ২০২৬; [[ReportColumn::$partyKey]]) --}}
        @if ($column->isParty() && filled($value))
            <span class="block max-w-56 truncate whitespace-nowrap" title="{{ $value }}" data-party>{{ $value }}</span>
        @else
            {{ $value }}
        @endif
@endswitch
