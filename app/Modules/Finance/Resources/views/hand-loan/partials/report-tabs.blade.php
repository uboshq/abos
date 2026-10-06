{{--
    ⭐ হাতধারের রিপোর্টের সারি — অর্থ-মডিউলের পরিকল্পনা ৩–৭, ৫ অক্টোবর ২০২৬ ([[HandLoanReports]])।
    ⓘ রিপোর্টের মাথায় আর হাতধারের পাতায় একই সারি, একই ক্রম ([[FinanceReportController::HAND_LOAN_REPORTS]]);
    এখন যেটা খোলা সেটা চিহ্নিত।
--}}
<nav data-hand-loan-reports aria-label="{{ __('finance::hand_loan_report.reports') }}"
     class="flex w-full flex-wrap items-center gap-1 text-sm">
    @foreach (\App\Modules\Finance\Http\Controllers\FinanceReportController::HAND_LOAN_REPORTS as $reportSlug => $reportLabel)
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

{{-- ⭐ সময়সূচির অবস্থা — দিন পার / আজ / সামনে / তারিখ নেই (পরিকল্পনা ১.৮); কেবল সময়সূচির পাতায় --}}
@if (($slug ?? null) === 'hand-loan-schedule')
    <label>
        <span class="sr-only">{{ __('finance::hand_loan_report.state') }}</span>
        <select name="state" data-schedule-state
                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                       bg-(--color-surface-app) px-2 text-sm">
            <option value="">{{ __('finance::hand_loan_report.all_states') }}</option>
            @foreach (\App\Modules\Finance\Reports\HandLoanReports::STATES as $state)
                <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ __('finance::hand_loan_report.state_'.$state) }}</option>
            @endforeach
        </select>
    </label>
@endif
