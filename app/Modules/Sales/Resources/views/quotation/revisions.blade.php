{{--
    উদ্ধৃতির সংস্করণ — যে উদ্ধৃতির নতুন সংস্করণ হয়েছে, মূল ধরে এক সারি।

    ⭐ মালিকের আন্তর্জাতিক পরিকল্পনা, ৪ অক্টোবর ২০২৬ — মেনুর "উদ্ধৃতির সংশোধন" এখন এই পাতা। একই মূল নম্বর, শেষে
    -R১, -R২ …; পুরনোগুলো কেবল পড়ার জন্য। ⓘ প্রতিটা সারি এক লাইনে একটা কথা (মালিকের পর্দা ডান দিক কাটে, ১ অক্টোবর ২০২৬),
    আর "সংস্করণ তুলনা" — পুরো ইতিহাস পাশাপাশি এক চাপে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::quotation.revisions.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::quotation.revisions.title')" :subtitle="__('sales::quotation.revisions.note')" />
    </x-slot:header>

    <section data-boxed data-revision-families
             class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" action="{{ route('sales.quotation.revisions') }}" class="flex flex-wrap items-end gap-2 border-b border-(--color-border) p-4">
            <x-ui.field name="q" :label="__('sales::quotation.search')" :value="$q" />
            <x-ui.button type="submit" tone="secondary">{{ __('core.action.search') }}</x-ui.button>
        </form>

        @if ($roots->isEmpty())
            <p class="p-4 text-sm text-(--color-ink-muted)">{{ $q ? __('core.empty.no_results') : __('sales::quotation.revisions.empty') }}</p>
        @else
            <ul class="divide-y divide-(--color-border)">
                @foreach ($roots as $root)
                    @php($current = $latest->get($root->id))
                    <li data-family="{{ $root->id }}" class="px-4 py-3 text-sm">
                        <span class="block">
                            <span class="text-(--color-ink-muted)">{{ __('sales::quotation.revisions.base_no') }}:</span>
                            @include('sales::components.doc-link', ['document' => $root, 'route' => 'sales.quotation.show'])
                        </span>
                        <span class="block">{{ $root->customer?->name() }}</span>
                        <span class="block">
                            <span class="text-(--color-ink-muted)">{{ __('sales::quotation.revisions.count') }}:</span>
                            <span class="num">{{ (int) ($current?->revision_no ?? 0) }}</span>
                        </span>
                        @if ($current)
                            <span class="block">
                                <span class="text-(--color-ink-muted)">{{ __('sales::quotation.revisions.latest') }}:</span>
                                @include('sales::components.doc-link', ['document' => $current, 'route' => 'sales.quotation.show'])
                                · {{ \App\Core\Support\Money::format($current->total) }}
                            </span>
                            <span class="block">@include('sales::quotation.partials.status', ['quotation' => $current])</span>
                        @endif
                        <span class="mt-2 block">
                            <x-ui.button tone="secondary" :href="route('sales.quotation.compare', ['family' => $root->id])">
                                {{ __('sales::quotation.action.compare') }}
                            </x-ui.button>
                        </span>
                    </li>
                @endforeach
            </ul>

            <x-ui.pager :rows="$roots" />
            <x-ui.list-totals :rows="$roots" />
        @endif
    </section>
</x-layouts.app>
