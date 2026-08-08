{{--
    রিপোর্টের একটা ঘর — ধরন অনুযায়ী।

    ডকুমেন্ট নম্বর ক্লিকযোগ্য (নিয়ম ১): সংজ্ঞায় source_type ও source_id
    কোন কলামে আছে তা বলা থাকে, আর x-ui.drill বাকিটা জানে। এখানে কোনো
    রুট বাছা হয় না, তাই নতুন ডকুমেন্ট টাইপ যোগ হলে এই ফাইলটা ছুঁতে
    হয় না।
--}}
@php
    $value = $row[$column->key] ?? null;
@endphp

@switch ($column->type)
    @case (\App\Core\Engines\Report\ReportColumn::MONEY)
        {{-- শূন্য দেখানো হয় না: একটা কলামে অর্ধেক সারিতে "0.00" থাকলে
             চোখ প্রতিটাতে থামে, অথচ ওগুলোর কোনো মানে নেই --}}
        {{ $value !== null && bccomp((string) $value, '0', 4) !== 0
            ? number_format((float) $value, 2) : '' }}
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
        {{ $value }}
@endswitch
