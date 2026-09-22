{{--
    ছাপার নিয়ন্ত্রণ — প্রতিটা কাগজের নিজের সুইচ।

    ── ⚠️ কেন বাঁ পাশে কাগজের তালিকা ────────────────────────────────────
    পাঁচটা কাগজ × পনেরোটা সুইচ × আটটা কলাম — একটার নিচে একটা বসালে
    পর্দাটা কয়েক হাজার পিক্সেল লম্বা হত, আর একটা সুইচ বদলাতে পাঁচটা
    কার্ড পেরোতে হত। ⓘ সেটিংসের পর্দায় এই সিদ্ধান্তটা আগেই নেওয়া
    হয়েছে, আর এখানে একই নিয়ম — দুইটা পর্দা দুই রকম আচরণ করলে মানুষ
    প্রতিবার নতুন করে শিখতেন।

    ⭐ ফর্মটা একটাই, ট্যাবগুলো কেবল দেখা-না-দেখা — তাই একবার "সংরক্ষণ"
    চাপলে পাঁচটা কাগজের বদল একসাথে বসে। ⛔ প্রতি ট্যাবে আলাদা ফর্ম হলে
    কেউ দুইটা ট্যাবে বদল করে একবার সেভ করতেন, আর অর্ধেকটা নীরবে হারাত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::settings.print_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::settings.print_title')"
                          :subtitle="__('system_admin::settings.print_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <form method="POST" action="{{ route('system_admin.print_control.update') }}"
          x-data="{ tab: '{{ $papers[0]['code'] ?? '' }}' }">
        @csrf
        @method('PUT')

        <div class="flex flex-col gap-4 md:flex-row md:items-start">
            <nav aria-label="{{ __('system_admin::settings.print_title') }}"
                 class="-mx-1 flex shrink-0 gap-1 overflow-x-auto px-1 pb-1
                        md:mx-0 md:w-56 md:flex-col md:overflow-visible md:px-0 md:pb-0">
                @foreach ($papers as $paper)
                    <button type="button" @click="tab = '{{ $paper['code'] }}'"
                            :aria-current="tab === '{{ $paper['code'] }}' ? 'page' : null"
                            class="flex min-h-(--spacing-touch) shrink-0 items-center gap-2
                                   whitespace-nowrap rounded-(--radius-field) px-3 text-sm transition-colors
                                   md:w-full"
                            :class="tab === '{{ $paper['code'] }}'
                                ? 'bg-(--color-brand-600) text-(--color-brand-ink) font-medium'
                                : 'text-(--color-ink-muted) hover:bg-(--color-surface-sunken)'">
                        {{ $paper['label'] }}
                    </button>
                @endforeach
            </nav>

            <div class="min-w-0 flex-1 space-y-4">
                @foreach ($papers as $paper)
                    <div x-show="tab === '{{ $paper['code'] }}'" x-cloak class="space-y-4">

                        {{-- ── তৈরি রূপ ───────────────────────────────────────── --}}
                        <section data-boxed
                                 class="rounded-(--radius-card) border border-(--color-border)
                                        bg-(--color-surface-card) p-4">
                            <h2 class="mb-1 text-sm font-semibold">
                                {{ __('system_admin::settings.print_pick_format') }}
                            </h2>
                            <p class="mb-3 text-xs text-(--color-ink-muted)">
                                {{ __('system_admin::settings.print_pick_format_note') }}
                            </p>

                            <select name="papers[{{ $paper['code'] }}][format]"
                                    class="h-(--spacing-field) w-full max-w-sm rounded-(--radius-field)
                                           border border-(--color-border) bg-(--color-surface-card) px-3">
                                @foreach ($formats as $format)
                                    <option value="{{ $format }}" @selected($paper['format'] === $format)>
                                        {{ __('core.print.format.'.$format) }}
                                    </option>
                                @endforeach
                            </select>
                        </section>

                        {{-- ── কাগজে যা আসবে ──────────────────────────────────── --}}
                        <section data-boxed
                                 class="rounded-(--radius-card) border border-(--color-border)
                                        bg-(--color-surface-card) p-4">
                            <h2 class="mb-1 text-sm font-semibold">
                                {{ __('system_admin::settings.print_parts') }}
                            </h2>
                            <p class="mb-3 text-xs text-(--color-ink-muted)">
                                {{ __('system_admin::settings.print_parts_note') }}
                            </p>

                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($allParts as $part)
                                    <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                                        <input type="checkbox"
                                               name="papers[{{ $paper['code'] }}][parts][{{ $part }}]"
                                               value="1" @checked(in_array($part, $paper['parts'], true))
                                               class="mt-1 size-4">
                                        <span>{{ __('core.print.part.'.$part) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </section>

                        {{-- ── কলাম ও ক্রম ────────────────────────────────────── --}}
                        <section data-boxed
                                 class="rounded-(--radius-card) border border-(--color-border)
                                        bg-(--color-surface-card) p-4">
                            <h2 class="mb-1 text-sm font-semibold">
                                {{ __('system_admin::settings.print_columns') }}
                            </h2>
                            <p class="mb-3 text-xs text-(--color-ink-muted)">
                                {{ __('system_admin::settings.print_columns_note') }}
                            </p>

                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ($paper['columns'] as $column)
                                    @php $place = array_search($column, $paper['on'], true); @endphp

                                    <label class="block">
                                        <span class="mb-1 block text-sm">{{ __('core.print.column.'.$column) }}</span>

                                        {{-- ⓘ "বন্ধ" আলাদা একটা বিকল্প, শূন্য নয় — ⚠️ শূন্য দিলে
                                             কেউ ভাবতেন ওটা "প্রথম", আর কলামটা নীরবে উধাও হত। --}}
                                        <select name="papers[{{ $paper['code'] }}][columns][{{ $column }}]"
                                                class="h-(--spacing-field) w-full rounded-(--radius-field)
                                                       border border-(--color-border)
                                                       bg-(--color-surface-card) px-3">
                                            <option value="" @selected($place === false)>
                                                {{ __('system_admin::settings.print_column_off') }}
                                            </option>

                                            @foreach (range(1, count($paper['columns'])) as $n)
                                                <option value="{{ $n }}"
                                                        @selected($place !== false && $place + 1 === $n)>{{ $n }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endforeach
                            </div>
                        </section>
                    </div>
                @endforeach

                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>

                    <a href="{{ route('system_admin.settings') }}"
                       class="text-sm text-(--color-ink-muted) underline">
                        {{ __('system_admin::settings.title') }}
                    </a>
                </div>
            </div>
        </div>
    </form>
</x-layouts.app>
