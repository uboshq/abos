{{-- ক্রয়ের খরচের মাথা — ABOS রূপে পুরো চওড়ায় কার্ডের আগে, বাকি রূপে টেবিলের বাক্সে ([[AbosHead]]) --}}
<form id="list-form" method="GET" class="contents" data-report>
    <input type="hidden" name="by" value="{{ $by }}">
    <x-ui.toolbar :title="__('purchase::cost_report.title')" :search="false" :report="true"
                  :count="implode(' · ', $chips)"
                  :columns="$columns" :facets="$filters" :quiet="['by']">
        <x-slot:actions>
            {{-- ⭐ ভাগ — ইনভয়েস / সরবরাহকারী / মাস / পণ্য (`?by=`) --}}
            <nav aria-label="{{ __('purchase::cost_report.group_label') }}" data-cost-groups
                 class="inline-flex overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                @foreach (\App\Modules\Purchase\Services\PurchaseCostReport::GROUPS as $g)
                    <a href="{{ request()->fullUrlWithQuery(['by' => $g, 'page' => null]) }}" data-group="{{ $g }}"
                       @if ($g === $by) aria-current="page" @endif
                       @class([$segment, 'border-s border-(--color-border)' => ! $loop->first,
                               'bg-(--color-surface-selected) font-semibold text-(--color-brand-600)' => $g === $by])>{{ __('purchase::cost_report.by.'.$g) }}</a>
                @endforeach
            </nav>
            @if ($canDownload)
                <a href="{{ $exportUrl('print') }}" target="_blank" rel="noopener" data-no-prefetch data-download="print" class="{{ $button }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current"><path d="M7 3h10v4H7V3ZM5 9h14a2 2 0 0 1 2 2v6h-4v4H7v-4H3v-6a2 2 0 0 1 2-2Zm4 8h6v4H9v-4Z"/></svg>
                    {{ __('purchase::cost_report.print') }}
                </a>
                <a href="{{ $exportUrl('pdf') }}" target="_blank" rel="noopener" data-no-prefetch data-download="pdf" class="{{ $button }}">{{ __('purchase::cost_report.pdf') }}</a>
                <a href="{{ $exportUrl('xlsx') }}" data-no-prefetch data-download="xlsx" class="{{ $button }}">{{ __('purchase::cost_report.excel') }}</a>
            @endif
        </x-slot:actions>
    </x-ui.toolbar>
</form>

@php
    // ⓘ কাগজের মাথা — রিপোর্টের নাম আর সময়কাল, তারপর বাকি চিপ (টুলবারের বসানোর পরে, তাই এটাই থাকে)
    $export->describe([
        'title' => __('purchase::cost_report.title'),
        'title_en' => app()->getLocale() === 'en' ? '' : __('purchase::cost_report.title', [], 'en'),
        'period' => $period,
        'facts' => array_slice($chips, 1),
    ]);
@endphp
