{{--
    সইয়ের পাতার ভিতরটা — পাতায় আর "পুরো কাগজ" জানালায় একই অংশ।

    ⚠️ দুই জায়গায় আলাদা করে লিখলে একদিন একটায় একটা ঘর যোগ হত আর অন্যটায়
    নয় — আর সইকারী জানালায় যা দেখে সই দিতেন তা পাতার সাথে মিলত না।
    ⓘ তফাত কেবল কয়টা সারি (`$rows`)।
--}}
@php
    $inBoard ??= false;
    $boardSaid ??= '';
    // ⓘ বোর্ডে — উপরে আগেই বলা কথা (পক্ষ, তারিখ, গুদাম) আবার নয় (মালিক, ২৬ নভেম্বর ২০২৬)
    $fresh = fn ($value) => ! $inBoard || $boardSaid === '' || ! str_contains($boardSaid, trim((string) $value));
@endphp
@if ($inBoard && $sheet['facts'] !== [] && ! ($hideFacts ?? false))
    {{-- ⭐ বোর্ডে এক লাইনে নাম:অঙ্ক — বড় বড় বাক্স নয়, যাতে সব এক পর্দায় ধরে --}}
    <p class="inbox-board-kv">
        @foreach ($sheet['facts'] as $fact)
            @if ($fresh($fact['value']))
                <span><i>{{ $fact['label'] }}</i> {{ $fact['value'] }}</span>
            @endif
        @endforeach
    </p>
@elseif ($sheet['facts'] !== [] && ! ($hideFacts ?? false))
    <dl class="mt-3 grid gap-3 sm:grid-cols-3">
        @foreach ($sheet['facts'] as $fact)
            <div>
                <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ $fact['label'] }}</dt>
                <dd class="min-w-0 text-sm break-words">{{ $fact['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif

{{-- ⓘ যার কাগজ তার খবর — ফোন, ঠিকানা, বকেয়া, বাকির সীমা। ⚠️ বাকিতে বিক্রিতে
     সইকারীর আসল প্রশ্ন "এই মানুষটা কি আরও বাকি পাওয়ার যোগ্য", আর উত্তর
     এখানেই, অন্য পাতায় নয়। --}}
@if ($inBoard && $partyCard !== [])
    <p class="inbox-board-kv">
        @foreach ($partyCard as $fact)
            @if ($fresh($fact->value))
                <span><i>{{ __($fact->label) }}</i> <b class="num">{{ $fact->value }}</b></span>
            @endif
        @endforeach
    </p>
@elseif ($partyCard !== [])
    <div class="mt-3 border-t border-(--color-border) pt-3">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('approval::field.party_card') }}</p>
        <dl class="mt-2 grid gap-3 sm:grid-cols-3">
            @foreach ($partyCard as $fact)
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __($fact->label) }}</dt>
                    <dd class="tabular min-w-0 text-sm break-words">{{ $fact->value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif

{{-- ⓘ চওড়া কলামেও কাগজের অনেক কলাম হলে টেবিল নিজে সরে (overflow-x), পাতার বাইরে কাটা পড়ে না --}}
<div @class(['min-w-0 overflow-x-auto', 'mt-3' => ! $inBoard, 'mt-1' => $inBoard])>
    <x-ui.table compact
                :empty="__('approval::field.sheet')"
                :rows="$rows"
                :columns="$columns"
                :totals="$sheet['totals'] ?? []"
                :totalsLabel="__('core.print.total')" />
</div>
