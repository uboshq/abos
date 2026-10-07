@php
    $days = $deposit->daysToMaturity();
@endphp

{{-- মেয়াদ, আর আর কত দিন বাকি।

     ── কেন দিনের সংখ্যাটা তারিখের পাশে ──────────────────────────────
     "২০২৭-০৩-১৪" পড়ে কেউ মাথায় হিসাব করে না। মেয়াদোত্তীর্ণ FD ব্যাংকে
     পড়ে থাকে আর সাধারণ সঞ্চয়ী হারে সুদ পায় — প্রতিদিন টাকা হারায়।
     সংখ্যাটা লাল হলে চোখ নিজেই থামে। --}}
@if ($deposit->isCancelled())
    {{-- বাতিল হলে মেয়াদের তারিখটা অর্থহীন — ওটা এমন একটা ভবিষ্যৎ যা
         কোনোদিন আসবে না। দেখালে তালিকায় সারিটা চালু বলে ভুল হত। --}}
    <span class="text-2xs text-(--color-badge-danger-ink)">{{ __('finance::state.cancelled') }}</span>
@elseif ($deposit->status === \App\Modules\Finance\Models\Deposit::CLOSED)
    <span class="text-2xs text-(--color-ink-muted)">
        {{ __('finance::state.closed') }}
        @if ($deposit->closed_on) · {{ \App\Core\Support\DateFormat::format($deposit->closed_on) }} @endif
    </span>
@elseif ($deposit->matures_on === null)
    <span class="text-(--color-ink-muted)">—</span>
@else
    <span class="inline-flex flex-col">
        <span>{{ \App\Core\Support\DateFormat::format($deposit->matures_on) }}</span>

        <span @class([
            'text-2xs',
            'text-(--color-badge-danger-ink)' => $days <= 30,
            'text-(--color-ink-muted)' => $days > 30,
        ])>
            @if ($days < 0)
                {{ __('finance::state.overdue') }}
            @else
                {{ __('finance::field.days_left') }} {{ $days }}
            @endif
        </span>
    </span>
@endif
