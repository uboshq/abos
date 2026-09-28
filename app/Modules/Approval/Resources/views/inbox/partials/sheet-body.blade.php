{{--
    সইয়ের পাতার ভিতরটা — পাতায় আর "পুরো কাগজ" জানালায় একই অংশ।

    ⚠️ দুই জায়গায় আলাদা করে লিখলে একদিন একটায় একটা ঘর যোগ হত আর অন্যটায়
    নয় — আর সইকারী জানালায় যা দেখে সই দিতেন তা পাতার সাথে মিলত না।
    ⓘ তফাত কেবল কয়টা সারি (`$rows`)।
--}}
@if ($sheet['facts'] !== [])
    <dl class="mt-3 grid gap-3 sm:grid-cols-3">
        @foreach ($sheet['facts'] as $fact)
            <div>
                <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ $fact['label'] }}</dt>
                <dd class="text-sm">{{ $fact['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif

{{-- ⓘ যার কাগজ তার খবর — ফোন, ঠিকানা, বকেয়া, বাকির সীমা। ⚠️ বাকিতে বিক্রিতে
     সইকারীর আসল প্রশ্ন "এই মানুষটা কি আরও বাকি পাওয়ার যোগ্য", আর উত্তর
     এখানেই, অন্য পাতায় নয়। --}}
@if ($partyCard !== [])
    <div class="mt-3 border-t border-(--color-border) pt-3">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('approval::field.party_card') }}</p>
        <dl class="mt-2 grid gap-3 sm:grid-cols-3">
            @foreach ($partyCard as $fact)
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __($fact->label) }}</dt>
                    <dd class="tabular text-sm">{{ $fact->value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif

<div class="mt-3">
    <x-ui.table compact
                :empty="__('approval::field.sheet')"
                :rows="$rows"
                :columns="$columns"
                :totals="$sheet['totals'] ?? []"
                :totalsLabel="__('core.print.total')" />
</div>
