{{--
    ⭐ ভাড়ার রিপোর্টের সারি — অর্থ-মডিউলের পরিকল্পনা, অংশ ৫, ৬ অক্টোবর ২০২৬ ([[RentalReports]])।
    ⓘ রিপোর্টের মাথায় আর ভাড়ার পাতায় একই সারি, একই ক্রম ([[RentalReportController::TABS]]); এখন যেটা খোলা সেটা চিহ্নিত।
--}}
<nav data-rental-reports aria-label="{{ __('finance::rental_report.reports') }}"
     class="flex w-full flex-wrap items-center gap-1 text-sm">
    @foreach (\App\Modules\Finance\Http\Controllers\RentalReportController::TABS as $reportSlug => $reportLabel)
        <a href="{{ route('finance.rental.report.show', ['slug' => $reportSlug]) }}"
           @if (($slug ?? null) === $reportSlug) aria-current="page" @endif
           class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border px-2.5
                  {{ ($slug ?? null) === $reportSlug
                      ? 'border-(--color-brand-500) bg-(--color-surface-selected) font-semibold text-(--color-ink)'
                      : 'border-(--color-border) text-(--color-ink-muted) hover:text-(--color-ink)' }}">
            {{ __($reportLabel) }}
        </a>
    @endforeach
</nav>
