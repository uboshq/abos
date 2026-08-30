{{-- স্কিমের অবস্থা — আর মেয়াদ পেরোলে সেটাও।

     ── কেন দুইটা আলাদা কথা ────────────────────────────────────────────
     "চালু" মানে কেউ স্কিমটা চালু করেছেন। "মেয়াদ পেরিয়েছে" মানে তারিখটা
     পেরিয়ে গেছে। দুইটা একসাথে হতে পারে, আর তখন স্কিমটা **কিছুই দেয় না**
     ([[Scheme::isLiveOn()]] তারিখ দেখে) — কিন্তু সারিটা কেবল "চালু"
     লেখা থাকলে কেউ ধরে নেন এটা চলছে, তারপর গ্রাহককে সেই কথা দিয়ে বসেন।
--}}
<span class="inline-flex flex-col gap-0.5">
    <x-ui.badge :tone="match ($scheme->status) {
        \App\Modules\Sales\Models\Scheme::ACTIVE => 'success',
        \App\Modules\Sales\Models\Scheme::CANCELLED => 'danger',
        default => 'draft',
    }">
        {{ __('sales::scheme_state.' . $scheme->status) }}
    </x-ui.badge>

    @if ($scheme->hasLapsed())
        <span class="whitespace-nowrap text-2xs text-(--color-badge-warning-ink)">
            {{ __('sales::scheme_state.lapsed') }}
        </span>
    @endif

    {{-- ধাপ ছাড়া চালু স্কিম কিছুই দেয় না, অথচ দেখতে স্বাভাবিক। --}}
    @if ($scheme->status === \App\Modules\Sales\Models\Scheme::ACTIVE && (int) ($scheme->rules_count ?? 0) === 0)
        <span class="whitespace-nowrap text-2xs text-(--color-badge-danger-ink)">
            {{ __('sales::scheme_state.no_band') }}
        </span>
    @endif
</span>
