{{--
    ⭐ ব্যাংক ঋণের রিপোর্টের সারি — অর্থ-মডিউলের পরিকল্পনা ৩, ৬ অক্টোবর ২০২৬ ([[BankLoanReports]])।
    ⓘ রিপোর্টের মাথায় আর ঋণের তালিকায় একই সারি ([[FinanceReportController::BANK_LOAN_REPORTS]]); কিস্তির পাতায় অবস্থা বাছার ঘর।
--}}
<nav data-bank-loan-reports aria-label="{{ __('finance::bank_loan_report.reports') }}"
     class="flex w-full flex-wrap items-center gap-1 text-sm">
    @foreach (\App\Modules\Finance\Http\Controllers\FinanceReportController::BANK_LOAN_REPORTS as $reportSlug => $reportLabel)
        <a href="{{ route('finance.report.show', ['slug' => $reportSlug]) }}"
           @if (($slug ?? null) === $reportSlug) aria-current="page" @endif
           class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border px-2.5
                  {{ ($slug ?? null) === $reportSlug
                      ? 'border-(--color-brand-500) bg-(--color-surface-selected) font-semibold text-(--color-ink)'
                      : 'border-(--color-border) text-(--color-ink-muted) hover:text-(--color-ink)' }}">
            {{ __($reportLabel) }}
        </a>
    @endforeach
</nav>

@if (($slug ?? null) === 'bank-loan-instalments')
    <label>
        <span class="sr-only">{{ __('finance::bank_loan_report.state') }}</span>
        <select name="state" data-instalment-state
                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                       bg-(--color-surface-app) px-2 text-sm">
            <option value="">{{ __('finance::bank_loan_report.all_states') }}</option>
            @foreach (['overdue', 'today', 'upcoming'] as $state)
                <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ __('finance::bank_loan_report.state_'.$state) }}</option>
            @endforeach
        </select>
    </label>
@endif
