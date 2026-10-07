{{--
    বাজেটের তিন ট্যাব — Capital পাতার ধরনে, কিন্তু প্রতিটার নিজের ঠিকানা
    (মানচিত্রের প্রতিটা লাইন একটা আসল রুটে পৌঁছায়)।

    ⓘ বছর আর বিভাগের ছাঁকনি ট্যাব বদলালেও সাথে যায় — নইলে "২০২৫-এর
    পরিকল্পনা" দেখে "প্রকৃত"-তে গেলে চুপচাপ ২০২৬ খুলত।
--}}
@php
    $keep = array_filter(['year' => $year, 'center' => $center]);
    $tabs = [
        'plan' => ['finance.budget.index', __('finance::budget.tab_plan')],
        'actual' => ['finance.budget.actual', __('finance::budget.tab_actual')],
        'centers' => ['finance.budget.centers', __('finance::budget.tab_centers')],
    ];
@endphp

<nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
     aria-label="{{ __('finance::budget.title') }}">
    @foreach ($tabs as $key => [$route, $label])
        <a href="{{ route($route, $keep) }}"
           @if ($tab === $key) aria-current="page" @endif
           class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                  {{ $tab === $key
                      ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                      : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
