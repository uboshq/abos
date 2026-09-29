{{--
    ডকুমেন্ট ম্যানেজমেন্টের একটা পর্দার পরিকল্পনা ([[PlanController::show()]],
    ৩০ সেপ্টেম্বর ২০২৬)। পর্দাটা এখনো তৈরি হয়নি — পাতাটা সৎভাবে তাই বলে, আর
    জানায় পর্দাটা কী করবে, পরিকল্পনার কোন অংশ থেকে, আর কীসের উপর দাঁড়াবে।

    ⛔ কোনো ফর্ম, কোনো বোতাম নেই। ⓘ `data-doc-page` থেকে `data-doc-page-end`
    পর্যন্তই পাতার নিজের অংশ — পরীক্ষা কেবল ঐটুকু দেখে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.'.$screen) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.'.$screen)"
                          :subtitle="__('documents::page.screen_subtitle')" />
    </x-slot:header>

    <div data-doc-page="{{ $screen }}">
        <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
            <p class="inline-flex rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-1 text-sm
                      font-semibold text-(--color-badge-pending-ink)">
                {{ __('documents::page.coming_soon') }}
            </p>

            <h2 class="mt-4 text-sm font-semibold text-(--color-ink)">{{ __('documents::page.what_heading') }}</h2>

            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-(--color-ink-body)">
                {{ __('documents::screen.'.$screen.'.what') }}
            </p>

            @if ($plan['new'])
                <h2 class="mt-4 text-sm font-semibold text-(--color-ink)">{{ __('documents::page.new_heading') }}</h2>

                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-(--color-ink-body)">
                    {{ __('documents::screen.'.$screen.'.new') }}
                </p>
            @endif

            <p class="mt-4 max-w-3xl text-2xs text-(--color-ink-muted)">
                {{ __('documents::page.nothing_works_yet') }}
            </p>
        </section>

        <h2 class="mb-2 text-sm font-semibold text-(--color-ink)">{{ __('documents::page.plan_heading') }}</h2>

        <ul data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            @foreach ($plan['sections'] as $no)
                <li class="py-1" data-doc-section="{{ $no }}">
                    <span class="font-semibold">
                        {{ __('documents::page.section_ref', ['no' => $no]) }}
                        {{ __('documents::plan.s'.$no.'.title') }}
                    </span>
                    <span class="mt-0.5 block text-2xs text-(--color-ink-muted)">
                        {{ __('documents::plan.s'.$no.'.summary') }}
                    </span>
                </li>
            @endforeach
        </ul>

        <h2 class="mb-2 text-sm font-semibold text-(--color-ink)">{{ __('documents::page.built_on_heading') }}</h2>

        <ul data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            @foreach ($plan['systems'] as $system)
                <li class="py-1" data-doc-system="{{ $system }}">
                    {{ __('documents::system.'.$system) }}
                    <code class="font-mono text-2xs text-(--color-ink-muted)">{{ $systems[$system] }}</code>
                </li>
            @endforeach
        </ul>

        <a href="{{ route('documents.dashboard') }}" class="text-sm text-(--color-link) hover:underline">
            {{ __('documents::page.back_to_plan') }}
        </a>
    </div>
    <span data-doc-page-end></span>
</x-layouts.app>
