{{--
    ⭐ আমানতের রিপোর্টের সারি — অর্থ-মডিউলের পরিকল্পনা, অংশ ৪, ৬ অক্টোবর ২০২৬ ([[DepositReports]])।
    ⓘ রিপোর্টের মাথায় আর জমার পাতায় একই সারি, একই ক্রম ([[DepositReportController::TABS]]); এখন যেটা খোলা সেটা চিহ্নিত।
--}}
<nav data-deposit-reports aria-label="{{ __('finance::deposit_report.reports') }}"
     class="flex w-full flex-wrap items-center gap-1 text-sm">
    @foreach (\App\Modules\Finance\Http\Controllers\DepositReportController::TABS as $reportSlug => $reportLabel)
        <a href="{{ route('finance.deposit.report.show', ['slug' => $reportSlug]) }}"
           @if (($slug ?? null) === $reportSlug) aria-current="page" @endif
           class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border px-2.5
                  {{ ($slug ?? null) === $reportSlug
                      ? 'border-(--color-brand-500) bg-(--color-surface-selected) font-semibold text-(--color-ink)'
                      : 'border-(--color-border) text-(--color-ink-muted) hover:text-(--color-ink)' }}">
            {{ __($reportLabel) }}
        </a>
    @endforeach
</nav>
