{{-- নাম, নম্বর, আর চুকে গেলে সেটা --}}
<span class="inline-flex flex-col">
    <span class="inline-flex items-center gap-1.5">
        {{-- ⭐ নামটা এখন লিংক — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
             ⓘ নাম দেখে মানুষ ওই হিসাবের পাতাতেই যেতে চান: কত দেওয়া, কত
             ফেরত, কবে। ⚠️ শেষ কলামের বোতামটা রয়ে গেছে, ছোট পর্দার জন্য। --}}
        <a href="{{ route('finance.hand_loan.show', ['handLoan' => $row['account']->id]) }}"
           class="text-(--color-brand-500) underline-offset-2 hover:underline">
            {{ $row['account']->person?->name() ?? '—' }}
        </a>

        @if ($row['account']->isSettled())
            <x-ui.badge tone="draft">{{ __('finance::state.settled') }}</x-ui.badge>
        @endif
    </span>

    {{-- নম্বরটা এখন ব্যক্তির সারি থেকে --}}
    @if ($row['account']->person?->mobile)
        <span class="num text-2xs text-(--color-ink-muted)">{{ $row['account']->person->mobile }}</span>
    @endif

    {{-- ⭐ পক্ষের সাথে জোড়া — মানচিত্র §১৪খ, ২০ সেপ্টেম্বর ২০২৬।
         ⓘ একই মানুষ ডিলারও হলে নামের নিচে সেটা লেখা থাকে, নাহলে দুই
         খাতার হিসাব দুইজন আলাদা মানুষ মনে হত। --}}
    @if ($row['account']->partner_type !== null)
        <span class="text-2xs text-(--color-ink-muted)">
            {{ __('finance::field.party_'.$row['account']->partner_type) }}

            {{-- ⭐ জোড়া পক্ষের নামও লিংক — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
                 ⓘ প্রশ্নটা এখানেই ওঠে: *"এই ডিলারের বাকি কত"* — আর উত্তরটা
                 ওদের নিজের পাতায়। ⚠️ ধরনটা চেনা না হলে লিংক নয়, শুধু নাম:
                 যেখানে যাওয়ার জায়গা নেই সেখানে লিংক দিলে ফাঁকা পাতা খুলত। --}}
            @if (($row['partner_name'] ?? null) !== null)
                @php
                    $partnerHref = match ($row['account']->partner_type) {
                        'customer' => route('customer.show', $row['account']->partner_id),
                        'supplier' => route('supplier.show', $row['account']->partner_id),
                        default => null,
                    };
                @endphp

                @if ($partnerHref === null)
                    — {{ $row['partner_name'] }}
                @else
                    — <a href="{{ $partnerHref }}"
                         class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $row['partner_name'] }}</a>
                @endif
            @endif
        </span>
    @endif
</span>
