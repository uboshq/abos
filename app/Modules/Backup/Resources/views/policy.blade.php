{{--
    ব্যাকআপের নীতি — যা সত্যিই চলে।

    ⚠️ কেবল দেখার পর্দা, আর সেটা ইচ্ছাকৃত: রাতের ব্যাকআপ সার্ভারের
    `config('abos.backup')` পড়ে, `bak_policies` টেবিল নয়। ⛔ এখানে
    সম্পাদনার ঘর থাকলে সেটা "সংরক্ষিত" বলত আর কিছুই বদলাত না।
    বিস্তার [[RecoveryController]]-এ।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('backup::screen.policy_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('backup::screen.policy_title')"
                          :subtitle="__('backup::screen.policy_subtitle')" />
    </x-slot:header>

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.every_night_at') }}</dt>
                <dd class="font-medium">{{ $dailyAt }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.kept_for') }}</dt>
                <dd class="font-medium">{{ trans_choice('core.backup.days', $keepDays, ['count' => $keepDays]) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.checked_after') }}</dt>
                <dd class="font-medium">{{ __('backup::screen.checked_after_value') }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.folder') }}</dt>
                <dd class="font-mono text-2xs break-all">{{ $directory }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('backup::screen.second_copy') }}</dt>
                @if ($mirror)
                    <dd class="font-mono text-2xs break-all">{{ $mirror }}</dd>
                @else
                    <dd class="font-medium text-(--color-badge-danger-ink)">{{ __('backup::screen.no_second_copy') }}</dd>
                @endif
            </div>
        </dl>

        <p class="mt-4 max-w-(--spacing-prose-max) rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2 text-sm text-(--color-ink-muted)">
            {{ __('backup::screen.policy_why_read_only') }}
        </p>
    </section>

    <section data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-2 font-semibold">{{ __('backup::screen.destinations') }}</h2>

        @if ($destinations->isEmpty())
            <p class="text-sm text-(--color-badge-danger-ink)">{{ __('backup::screen.no_destinations') }}</p>
        @else
            <ul class="space-y-1.5 text-sm">
                @foreach ($destinations as $destination)
                    <li class="flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate">{{ $destination->name }}</span>
                        <span class="shrink-0 text-2xs text-(--color-ink-muted)">
                            {{ $destination->is_active ? __('backup::screen.active') : __('backup::screen.inactive') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
