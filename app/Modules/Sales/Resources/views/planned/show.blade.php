{{-- মেনুতে আগে বসানো পর্দা — এখনো তৈরি হয়নি, সৎভাবে তাই বলা ([[PlannedScreenController]])। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::planned.'.$screen) }}</x-slot:title>

    {{-- ⛔ কেন হলো না, এই পাতাতেই — বোতাম আটকালে বার্তাটা এখানে দেখায় (ARefusalNobodyEverSawTest, ১০ অক্টোবর ২০২৬) --}}
    <x-ui.errors />

    <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
        <h1 class="text-xl font-semibold text-(--color-ink)">{{ __('sales::planned.'.$screen) }}</h1>

        <p class="mt-3 inline-flex rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-1 text-sm
                  font-semibold text-(--color-badge-pending-ink)">
            {{ __('sales::planned.being_built') }}
        </p>

        <p class="mt-4 max-w-3xl text-sm leading-relaxed text-(--color-ink-body)">
            {{ __('sales::planned.about_'.$screen) }}
        </p>
    </div>
</x-layouts.app>
