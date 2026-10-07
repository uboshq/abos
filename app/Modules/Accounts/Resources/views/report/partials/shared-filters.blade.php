{{--
    রিপোর্টের সাধারণ ছাঁকনি — গুদাম, পণ্য, ব্র্যান্ড, শ্রেণি, এলাকা, সরবরাহকারী, দোকান, বিক্রয়কর্মী (রিপোর্ট সেন্টার ধাপ ১)।

    ⛔ আগে: রিপোর্ট এগুলো ঘোষণা করত, কিন্তু পর্দা আঁকত কেবল তারিখ আর শাখা — বাকিগুলো চলত কেবল হাতে লেখা ঠিকানায়।

    ⭐ এখন: রিপোর্ট যে চাবি ঘোষণা করে আর কোনো মডিউল যার উৎস দেয় ([[ReportFilters]]), তার একটা লিখে-খোঁজার ঘর।
    ⓘ datalist লেখা পাঠায় ("কোড · নাম") — সার্ভার নম্বরে বদলায়, আর তালিকার বাইরে হলে রিপোর্টই ফেরে (শর্ত গ)।
    ⓘ তালিকাটা মানুষের নিজের নাগালের — কোম্পানি, দেখার শাখা, ডেটার পরিধি (শর্ত খ)।
    ⚠️ মানটা `$filters` থেকে, ইঞ্জিন যা মেনেছে — ঠিকানা থেকে আলাদা করে পড়লে পর্দা একটা বলত আর সংখ্যা আরেকটা।
    ⓘ যে রিপোর্ট নিজের ঘর আঁকে (`$ownFilters`), সেগুলো এখানে আবার আঁকা হয় না।
--}}
@php
    $filterSources = app(\App\Core\Engines\Report\ReportFilters::class)->sources();
    $sharedFilters = array_values(array_filter(
        $report->filters,
        fn ($key) => isset($filterSources[$key]) && ! in_array($key, $ownFilters ?? [], true),
    ));
    $anySharedFilter = false;
@endphp

@foreach ($sharedFilters as $key)
    @php
        $options = $filterSources[$key]->options();
        $current = $filters[$key] ?? null;
        $shown = $current !== null && $current !== '' ? ($options[(int) $current] ?? '') : '';
        $anySharedFilter = $anySharedFilter || $shown !== '';
        $name = __($filterSources[$key]->label());
    @endphp
    <label class="min-w-0 flex-1 sm:max-w-xs">
        <span class="sr-only">{{ $name }}</span>
        <input type="search" name="{{ $key }}" list="report-filter-{{ $key }}" value="{{ $shown }}" autocomplete="off"
               placeholder="{{ $name }} — {{ __('core.report.filters.any') }}" data-report-filter="{{ $key }}"
               class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                      bg-(--color-surface-app) px-2 text-sm">
        <datalist id="report-filter-{{ $key }}">
            @foreach ($options as $label)
                <option value="{{ $label }}"></option>
            @endforeach
        </datalist>
    </label>
@endforeach

@if ($anySharedFilter)
    <a href="{{ request()->fullUrlWithoutQuery($sharedFilters) }}"
       class="inline-flex h-(--spacing-field-compact) items-center text-sm text-(--color-ink-muted) underline">
        {{ __('core.report.filters.clear') }}
    </a>
@endif
