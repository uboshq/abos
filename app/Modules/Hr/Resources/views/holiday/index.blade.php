{{--
    ⭐ ছুটির দিনের তালিকা — বছর ধরে, পাশে যোগ বা বদলের ঘর (শ্রম আইন ধারা ১১৮; [[HolidayController]]; a6, ২১ অক্টোবর ২০২৬)।

    ⓘ ছুটির ধরনের পর্দার একই গড়ন: ঘর মাত্র তিনটা আর কাজটা বছরে একবার, তাই এক পাতায় তালিকা আর ফর্ম। বদল চাপলে একই পাতা
    খোলে, ফর্মে সেই সারি ভরা।
--}}
@php
    $columns = [
        ['key' => 'holiday_date', 'label' => __('hr::field.holiday_date'), 'width' => '10rem',
         'render' => fn ($h) => \App\Core\Support\DateFormat::format($h->holiday_date).', '.$h->holiday_date->locale(app()->getLocale())->translatedFormat('l')],
        ['key' => 'name', 'label' => __('hr::field.holiday_name'),
         'render' => fn ($h) => $h->name],
        ['key' => 'branch', 'label' => __('hr::field.branch'), 'width' => '10rem',
         'render' => fn ($h) => $h->branch?->name() ?? __('hr::field.all_branches')],
        ['key' => 'actions', 'label' => '—', 'width' => '10rem',
         'render' => fn ($h) => view('hr::holiday.partials.actions', ['holiday' => $h])],
    ];
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::text('year', __('hr::field.year')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.holidays') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ⭐ ব্যাখ্যার লাইন নয় — লেখাটা নামের পাশে ⓘ-এ (মালিক, ১৯ নভেম্বর ২০২৬ রাত: "no description line on any screen");
         `data-page-subtitle` লাইনটা কেবল "ABOS" আর "Dynamic"-এ লুকায়, বাকি রূপে আগের মতো ([[ui/page-info]]) --}}
    <p data-page-subtitle class="mb-4 text-sm text-(--color-ink-muted)">{{ __('hr::message.holiday_note', ['weekly' => $weeklyOff]) }}</p>
    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="__('hr::menu.holidays').' · '.$year" :columns="$columns" :search="$abosHead" :search-placeholder="__('hr::field.holiday_name')" :facets="$facetPanel"
                              :sort="$abosHead ? ['oldest' => __('core.notify.sort.oldest'), 'newest' => __('core.notify.sort.newest')] : []"
                              :info="__('hr::message.holiday_note', ['weekly' => $weeklyOff])">
                    @unless ($abosHead)
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        {{ __('hr::field.year') }}
                        <input type="number" name="year" value="{{ $year }}" min="2000" max="2100"
                               class="h-(--spacing-field) w-24 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    @endunless
                </x-ui.toolbar>
            </form>

            <x-ui.table
                :empty="__('hr::message.no_holidays')"
                :rows="$holidays"
                :columns="$columns" />

            <x-ui.pager :rows="$holidays" />
            <x-ui.list-totals :rows="$holidays" />
        </div>

        @can('hr.attendance.manage')
            <form method="POST"
                  action="{{ $editing->exists ? route('hr.holiday.update', $editing->id) : route('hr.holiday.store') }}"
                  class="rounded-(--radius-card) border border-(--color-border)
                         bg-(--color-surface-card) p-4">
                @csrf
                @if ($editing->exists) @method('PUT') @endif

                <h2 class="mb-3 font-semibold">{{ $editing->exists ? $editing->name : __('hr::action.add_holiday') }}</h2>

                <div class="space-y-3">
                    <x-ui.field name="holiday_date" type="date" :label="__('hr::field.holiday_date')"
                                :value="old('holiday_date', $editing->holiday_date?->toDateString())" required />
                    <x-ui.field name="name" :label="__('hr::field.holiday_name')"
                                :value="old('name', $editing->name)" required />
                    {{-- ⛔ "সব শাখা" কেবল যাঁর শাখার সীমা নেই — নিয়ামকের একই দেয়াল --}}
                    <x-ui.select name="branch_id" :label="__('hr::field.branch')"
                                 :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                                 :selected="old('branch_id', $editing->branch_id)"
                                 :placeholder="$mayAllBranches ? __('hr::field.all_branches') : '-'" />
                </div>

                <div class="mt-3 flex gap-2">
                    <x-ui.button type="submit" tone="primary">{{ __('hr::action.save') }}</x-ui.button>
                    @if ($editing->exists)
                        <x-ui.button :href="route('hr.holiday.index', ['year' => $year])">{{ __('core.action.cancel') }}</x-ui.button>
                    @endif
                </div>
            </form>

            {{-- ⭐ শাখা ধরে সাপ্তাহিক ছুটি — দোকান আর কারখানা আলাদা (ধারা ১০৩, ১১৪; fe-র উত্তর ৬; [[HolidayController::storeWeeklyOff()]]) --}}
            <section data-weekly-off class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('hr::message.weekly_off_branches') }}</h2>

                <div class="space-y-2">
                    {{-- ⭐ কোম্পানির ডিফল্ট — প্রথম সারি, শাখা খালি; নিচে প্রতিটা শাখার বদল (fe-র উত্তর ৪; এক নিয়ম, এক জায়গা) --}}
                    <form data-weekly-off-company method="POST" action="{{ route('hr.holiday.weekly_off.store') }}" class="flex items-center gap-2 border-b border-(--color-border) pb-2">
                        @csrf
                        <input type="hidden" name="branch_id" value="">
                        <span class="w-28 truncate text-sm font-semibold">{{ __('hr::message.weekly_off_company_row') }}</span>
                        <select name="weekly_off" aria-label="{{ __('hr::message.weekly_off_company_row') }}" @disabled(! $mayAllBranches)
                                class="h-(--spacing-field) flex-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            @foreach ($weeklyOffOptions as $option)
                                <option value="{{ $option }}" @selected($weeklyOffKey === $option)>{{ __('hr::settings.weekly_off_'.$option) }}</option>
                            @endforeach
                        </select>
                        @if ($mayAllBranches)
                            <x-ui.button type="submit">{{ __('hr::action.save') }}</x-ui.button>
                        @endif
                    </form>

                    @foreach ($branches as $branch)
                        <form method="POST" action="{{ route('hr.holiday.weekly_off.store') }}" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                            <span class="w-28 truncate text-sm">{{ $branch->name() }}</span>
                            <select name="weekly_off" aria-label="{{ $branch->name() }}"
                                    class="h-(--spacing-field) flex-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                <option value="">{{ __('hr::message.weekly_off_company', ['day' => $weeklyOff]) }}</option>
                                @foreach ($weeklyOffOptions as $option)
                                    <option value="{{ $option }}" @selected(($branchWeeklyOff[$branch->id] ?? null) === $option)>
                                        {{ __('hr::settings.weekly_off_'.$option) }}
                                    </option>
                                @endforeach
                            </select>
                            <x-ui.button type="submit">{{ __('hr::action.save') }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endcan
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
