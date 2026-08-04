{{--
    স্ট্যাটাস বার — সেকশন ১৫.১।

    Status │ Company │ Branch │ FY │ Database │ Sync │ Jobs │ Version │ User

    "Queue" নয়, "Jobs" — ABOS-এ Laravel Queue নেই, cron আছে (সেকশন ২)।
    যে নামটা বাস্তবে যা চলছে তার সাথে মেলে না, সেটা পরে কাউকে ভুল জায়গায়
    খুঁজতে পাঠায়।

    মোবাইলে লুকানো: ৩২০px-এ নয়টা তথ্য ধরে না, আর কেটে দেখানোর চেয়ে না
    দেখানো ভালো।
--}}
@php
    $user = auth()->user();
    $year = $user?->currentCompany?->currentFinancialYear();
@endphp

<footer class="hidden shrink-0 items-center gap-4 border-t border-(--color-border)
               bg-(--color-surface-card) px-5 py-1.5 text-2xs text-(--color-ink-muted) lg:flex">

    <span class="flex items-center gap-1.5">
        <span class="size-2 rounded-full bg-(--color-success)" aria-hidden="true"></span>
        {{ __('core.status_bar.operational') }}
    </span>

    @if ($user?->currentCompany)
        <span>{{ $user->currentCompany->name() }}</span>
    @endif

    @if ($user?->currentBranch)
        <span>{{ __('core.company.branch') }}: {{ $user->currentBranch->name() }}</span>
    @endif

    @if ($year)
        <span>{{ __('core.company.financial_year') }}: {{ $year->name }}</span>
    @endif

    <span class="ms-auto">{{ $user?->name }}</span>
    <span>v{{ config('app.version', '0.1.0') }}</span>
</footer>
