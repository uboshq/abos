{{--
    ⭐ নতুন কী এল — সংস্করণ ধরে, সর্বশেষ সবার উপরে (মালিক, ২৪ অক্টোবর ২০২৬; [[App\Core\Support\ReleaseNotes]])।
    ⓘ নোট রেপোর resources/release-notes/{bn,en}.md, ভাষা ব্যবহারকারীর ভাষা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.release.whats_new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.release.whats_new')" :subtitle="__('core.release.running', ['label' => $current])" />
    </x-slot:header>

    <div class="max-w-3xl space-y-4" data-release-notes>
        @forelse ($sections as $section)
            <section data-release-version="{{ $section['version'] }}"
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="text-base font-semibold">{{ $section['title'] }}</h2>
                <div class="mt-2 space-y-2 text-sm [&_ul]:list-inside [&_ul]:list-disc [&_ul]:space-y-1">
                    {!! $section['html'] !!}
                </div>
            </section>
        @empty
            <p class="text-sm text-(--color-ink-muted)">{{ __('core.release.no_notes') }}</p>
        @endforelse
    </div>
</x-layouts.app>
