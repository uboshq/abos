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

{{-- ⭐ মাস শেষের অর্জিত মুনাফা খাতায় — অর্থ-মডিউলের পরিকল্পনা ৪.২ ([[DepositAccrualService]]); কেবল জমা সুদের পাতায় --}}
@if (($slug ?? null) === 'accrued')
    @can('finance.deposit.move')
        <form method="POST" action="{{ route('finance.deposit.accrue') }}" data-deposit-accrual
              class="mt-2 flex w-full flex-wrap items-end gap-2 text-sm">
            @csrf
            <label class="grid gap-0.5">
                <span class="block text-2xs text-(--color-ink-muted)">{{ __('finance::deposit_report.accrual_month') }}</span>
                <input type="month" name="month" required value="{{ now()->subMonthNoOverflow()->format('Y-m') }}"
                       max="{{ now()->subMonthNoOverflow()->format('Y-m') }}"
                       class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
            </label>
            <x-ui.button type="submit" tone="secondary">{{ __('finance::deposit_report.accrual_run') }}</x-ui.button>
            <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::deposit_report.accrual_note') }}</span>
        </form>
    @endcan
@endif
