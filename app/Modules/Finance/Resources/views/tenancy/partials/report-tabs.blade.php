{{--
    ⭐ ভাড়াটের রিপোর্টের সারি — মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬ ([[TenancyReports]])।
    ⓘ রিপোর্টের মাথায় আর ভাড়াটের তালিকায় একই সারি; যেটা খোলা সেটা চিহ্নিত।
--}}
<nav data-tenancy-reports aria-label="{{ __('finance::tenancy.reports') }}"
     class="flex w-full flex-wrap items-center gap-1 text-sm">
    @foreach (['collections' => 'finance::tenancy.collections_short', 'arrears' => 'finance::tenancy.arrears_short'] as $reportSlug => $reportLabel)
        <a href="{{ route('finance.tenancy.report.show', ['slug' => $reportSlug]) }}"
           @if (($slug ?? null) === $reportSlug) aria-current="page" @endif
           class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border px-2.5
                  {{ ($slug ?? null) === $reportSlug
                      ? 'border-(--color-brand-500) bg-(--color-surface-selected) font-semibold text-(--color-ink)'
                      : 'border-(--color-border) text-(--color-ink-muted) hover:text-(--color-ink)' }}">
            {{ __($reportLabel) }}
        </a>
    @endforeach
</nav>
