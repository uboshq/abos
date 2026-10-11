{{--
    ডকুমেন্টের রিপোর্ট (§১৭; সপ্তম ধাপ) — চৌদ্দটা, এক পাতায়, প্রতিটার এক লাইনের বর্ণনাসহ।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.reports') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.reports')" :subtitle="__('documents::report.subtitle')" />
    </x-slot:header>

    <ul data-boxed class="grid gap-px overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-border) sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($slugs as $slug)
            <li class="bg-(--color-surface-card) px-4 py-3">
                <a href="{{ route('documents.report.show', $slug) }}" data-report="{{ $slug }}"
                   class="font-semibold text-(--color-link) hover:underline">{{ __('documents::report.'.str_replace('-', '_', $slug)) }}</a>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('documents::report.about_'.str_replace('-', '_', $slug)) }}</p>
            </li>
        @endforeach
    </ul>
</x-layouts.app>
