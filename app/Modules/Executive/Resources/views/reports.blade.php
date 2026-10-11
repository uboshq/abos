{{--
    মালিকের কেন্দ্র — রিপোর্ট।

    ⓘ রিপোর্ট সেন্টারের দরজা (/reports), মালিকের কেন্দ্রের নিজের রিপোর্ট আর তার ফাইল (CSV, Excel, JSON, PDF —
    সবই কেন্দ্রীয় রপ্তানির পথে, তাই প্রতিটা নামানো রপ্তানির খাতায় লেখা হয়), নিজের সংরক্ষিত দৃশ্য, আর নির্ধারিত রিপোর্ট।
    ⓘ ই-মেইলে রিপোর্ট পাঠানো এখন নয় — মালিকের উত্তর, প্রশ্ন ৪; সব অ্যাপের ভিতরেই।
--}}
@php use App\Modules\Executive\Support\Go; @endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('executive::reports.title') }}</x-slot:title>

    @include('executive::partials.open-form')
    @include('executive::partials.fit')

    <div data-executive-reports class="flex flex-col gap-3">
        <div data-fit class="flex flex-wrap items-center justify-between gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
             style="height: var(--exec-bar)">
            <h1 class="truncate text-lg font-bold text-(--color-ink)">{{ __('executive::reports.title') }}</h1>
            <a href="{{ route('reports.center') }}" class="text-sm font-semibold text-(--color-brand-700) hover:underline">{{ __('executive::reports.center') }} →</a>
        </div>

        <div class="grid gap-3 xl:grid-cols-3">
            <section data-own-reports class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::reports.own') }}</h2>
                <div class="px-4 py-3">
                    <a href="{{ route('executive.report.show', ['slug' => 'profit-by-customer']) }}"
                       class="text-sm font-semibold text-(--color-brand-700) hover:underline">{{ __('executive::analysis.profit_by_customer') }}</a>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('executive::analysis.profit_by_customer_hint') }}</p>
                    <p class="mt-2 flex flex-wrap gap-3 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('executive::reports.download') }}:</span>
                        @foreach ($formats as $format)
                            <a data-export="{{ $format }}" href="{{ route('executive.report.show', ['slug' => 'profit-by-customer', 'export' => $format]) }}"
                               class="font-semibold text-(--color-brand-700) hover:underline">{{ strtoupper($format === 'xlsx' ? 'Excel' : $format) }}</a>
                        @endforeach
                        <a href="{{ route('executive.report.show', ['slug' => 'profit-by-customer', 'print' => 1]) }}"
                           class="font-semibold text-(--color-brand-700) hover:underline">PDF</a>
                    </p>
                    <p class="mt-2 text-2xs text-(--color-ink-muted)">{{ __('executive::reports.export_note') }}</p>
                </div>
            </section>

            <section data-saved-views class="flex min-w-0 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
                     style="max-height: 420px">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::reports.saved_views') }}</h2>
                <ul class="min-h-0 flex-1 overflow-auto text-sm">
                    @forelse ($views as $view)
                        <li class="border-t border-(--color-border) px-4 py-1.5">
                            <button type="submit" form="executive-open" name="go"
                                    value="{{ Go::to($view['company_id'], null, $view['route'], $view['params']) }}"
                                    class="flex w-full items-center justify-between gap-2 text-left hover:underline">
                                <span class="min-w-0 truncate">{{ $view['name'] }}</span>
                                <span class="shrink-0 text-2xs text-(--color-ink-muted)">{{ $view['company_name'] }}</span>
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ __('executive::reports.no_views') }}</li>
                    @endforelse
                </ul>
            </section>

            <section data-scheduled class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::reports.scheduled') }}</h2>
                <div class="px-4 py-3 text-sm">
                    @if ($canSchedule)
                        <a href="{{ route('system_admin.reports.schedule.index') }}" class="font-semibold text-(--color-brand-700) hover:underline">{{ __('executive::reports.open_schedules') }} →</a>
                    @else
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('executive::reports.no_schedule_key') }}</span>
                    @endif
                    <p class="mt-2 text-2xs text-(--color-ink-muted)">{{ __('executive::reports.in_app_only') }}</p>
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
