{{--
    দুর্যোগ থেকে ফেরা — সার্ভারটাই গেলে কী হাতে থাকে।

    ⓘ তিনটা প্রশ্ন, একটার পর একটা: শেষ ব্যাকআপ কবে, শেষবার কবে সেটা
    ফিরিয়ে এনে দেখা গেছে, আর সেটা এই সার্ভারের **বাইরে** আছে কি না।
    ⚠️ তৃতীয়টাই দুর্যোগের আসল প্রশ্ন — আর লাইভে আজ (১৯ সেপ্টেম্বর ২০২৬)
    তার উত্তর "না", তাই সতর্কবার্তাটা সবার উপরে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('backup::screen.dr_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('backup::screen.dr_title')"
                          :subtitle="__('backup::screen.dr_subtitle')" />
    </x-slot:header>

    @if (! $mirror && $destinations->isEmpty())
        <p role="alert" data-dr-no-mirror
           class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm font-medium text-(--color-badge-danger-ink)">
            {{ __('backup::screen.no_mirror_warning') }}
        </p>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.newest_backup') }}</h2>
            @if ($newestAt)
                <p class="mt-1 text-lg font-semibold">{{ \App\Core\Support\DateFormat::formatWithTime($newestAt) }}</p>
                <p class="font-mono text-2xs text-(--color-ink-muted) break-all">{{ $newestName }}</p>
            @else
                <p class="mt-1 text-lg font-semibold text-(--color-badge-danger-ink)">{{ __('backup::screen.never') }}</p>
            @endif
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.last_good_check') }}</h2>
            @if ($lastPassed)
                <p class="mt-1 text-lg font-semibold">{{ \App\Core\Support\DateFormat::formatWithTime($lastPassed->verified_at) }}</p>
                <p class="text-2xs text-(--color-ink-muted)">
                    {{ __('backup::screen.check_passed', ['tables' => $lastPassed->detail['tables'] ?? 0]) }}
                </p>
            @else
                {{-- ⓘ "কখনো নয়" নয়: ১৯ সেপ্টেম্বরের আগে রাতের যাচাই খাতায় উঠত না,
                     কেবল লগে থাকত — যাচাই হয়নি বলাটা মিথ্যা হত --}}
                <p class="mt-1 text-sm font-medium text-(--color-ink-muted)">{{ __('backup::screen.not_recorded_yet') }}</p>
            @endif
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.mirrored_at') }}</h2>
            @if ($mirroredAt)
                <p class="mt-1 text-lg font-semibold">{{ \App\Core\Support\DateFormat::formatWithTime($mirroredAt) }}</p>
            @elseif ($destinations->isNotEmpty())
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($destinations as $destination)
                        @php $days = $destination->daysSinceLastCopy(); @endphp
                        <li class="flex justify-between gap-2">
                            <span class="min-w-0 truncate">{{ $destination->name }}</span>
                            <span @class(['num shrink-0 text-2xs', 'text-(--color-badge-danger-ink)' => $days === null || $days > 7])>
                                {{ $days === null ? __('backup::screen.never') : __('backup::screen.days_old', ['days' => $days]) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-1 text-lg font-semibold text-(--color-badge-danger-ink)">{{ __('backup::screen.never') }}</p>
            @endif
        </section>
    </div>

    <section data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-2 font-semibold">{{ __('backup::screen.steps_title') }}</h2>
        <ol class="list-inside list-decimal space-y-1.5 text-sm">
            @foreach (['step_1', 'step_2', 'step_3', 'step_4', 'step_5'] as $step)
                <li>{{ __('backup::screen.'.$step) }}</li>
            @endforeach
        </ol>
    </section>
</x-layouts.app>
