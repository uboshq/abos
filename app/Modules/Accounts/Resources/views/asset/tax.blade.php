{{--
    ⭐ করের অবচয় — আয়বর্ষ বেছে হিসাব, তারপর খাতা বনাম কর প্রতিবেদন (স্থায়ী সম্পদ ধাপ ৫)।
    ⓘ হার আর পদ্ধতি শ্রেণিতে, মালিকের বসানো — আইনের হার কোডে নেই। খাতায় কিছু বসে না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::asset_report.tax_page') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::asset_report.tax_page')" :subtitle="__('accounts::asset_report.tax_page_hint')" />
    </x-slot:header>

    <x-ui.errors />

    <div class="mb-5 grid gap-4 lg:grid-cols-2">
        @can('accounts.asset.manage')
            <form method="POST" action="{{ route('accounts.asset.tax.run') }}"
                  class="flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf
                <div class="w-full">
                    <p class="text-sm font-semibold">{{ __('accounts::asset_report.tax_run') }}</p>
                    <p class="text-2xs text-(--color-ink-muted)">
                        {{ __('accounts::asset_report.tax_year', ['start' => $start->format('d M Y'), 'end' => $end->format('d M Y')]) }}
                    </p>
                </div>
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset_report.tax_any_day') }}</span>
                    <x-ui.date name="on" :required="true" :value="$end->toDateString()" />
                </label>
                <x-ui.button type="submit" tone="primary">{{ __('accounts::asset_report.tax_run_action') }}</x-ui.button>
            </form>
        @endcan

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset_report.tax_rates') }}</h2>
            <ul class="divide-y divide-(--color-border) text-sm">
                @forelse ($categories as $category)
                    <li class="flex items-center gap-3 px-4 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ $category->code }} · {{ $category->name() }}</span>
                        @if ($category->tax_rate !== null)
                            <span class="num tabular-nums">{{ rtrim(rtrim((string) $category->tax_rate, '0'), '.') }}%</span>
                            <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.tax_method_'.$category->tax_method) }}</span>
                        @else
                            <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset_report.tax_no_rate') }}</span>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-2 text-(--color-ink-muted)">{{ __('accounts::asset.category_empty') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.app>
