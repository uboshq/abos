{{--
    টাকার খাতের ঘর — আর খাত না থাকলে কী লেখা থাকবে।

    ── ⛔ কী ভাঙা ছিল, ৫ সেপ্টেম্বর ২০২৬ ───────────────────────────────
    এই ঘরগুলো আগে সরাসরি `<select>` আঁকত। যে কোম্পানিতে একটাও নগদ বা
    ব্যাংক খাত বসানো নেই, সেখানে ড্রপডাউনটা **খালি** খুলত — কোনো
    ব্যাখ্যা ছাড়া।

    ⚠️ আর ফলটা নীরব: খাত না দিলে সার্ভিস ধরে নেয় "টাকাটা আগেই দেওয়া
    হয়েছে" (পুরনো চুক্তি বসানোর জন্য ওটা দরকার), তাই চুক্তি খুলত,
    পর্দায় ১২,০০,০০০ লেখা উঠত, **অথচ খতিয়ানে একটা সারিও যেত না**।
    সংখ্যাটা থাকত, টাকাটা থাকত না।

    ⓘ ধরা পড়েছে ব্রাউজারে হাতে চালিয়ে — টেস্টে নয়, কারণ টেস্টের ডেমো
    কোম্পানিতে খাতগুলো আছে।

    ⭐ এখন খালি হলে ঘরটাই আসে না; বদলে লেখা থাকে কোথায় গিয়ে বসাতে হবে।
--}}
{{--
    ⓘ কম্পোনেন্ট নয়, `@include` — এই রিপোতে মডিউলের ভিউ কেবল
    `loadViewsFrom()` দিয়ে বসে ([[ModuleServiceProvider:88]]), অ্যানোনিমাস
    কম্পোনেন্টের namespace কেউ নিবন্ধন করে না। ⛔ `<x-finance::…>` লিখে
    দেখেছি — "Unable to locate"।
--}}
@php
    $name ??= 'money_account_id';
    $required ??= false;
    $blank ??= null;
@endphp

<label class="grid gap-1">
    <span class="text-2xs text-(--color-ink-muted)">{{ $label }}</span>

    @if ($money->isEmpty())
        <span class="text-2xs text-(--color-badge-danger-ink)">
            {{ __('finance::message.rental_no_money_account') }}
        </span>
    @else
        <select name="{{ $name }}" @if ($required) required @endif
                class="h-(--spacing-field) rounded-(--radius-field) border
                       border-(--color-border) bg-(--color-surface-card) px-2">
            {{-- ⓘ খালি বিকল্পটা কেবল তখনই, যখন "না দেওয়া"-রও একটা অর্থ
                 আছে — যেমন পুরনো চুক্তি, যার টাকা আগেই দেওয়া। --}}
            @if ($blank !== null)
                <option value="">{{ $blank }}</option>
            @endif

            @foreach ($money as $account)
                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name() }}</option>
            @endforeach
        </select>
    @endif
</label>
