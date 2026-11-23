{{-- ⭐ ছাঁকনির বোতাম (চালু সংখ্যাসহ) আর চালু ছাঁকনির চিপ — [[toolbar]] আর [[filter-bar]] দুই জায়গায় হুবহু একই (মালিক, ১৪ নভেম্বর ২০২৬ নমুনা) --}}
<span class="order-1" x-data="filterToggle">
    {{-- ⓘ সাজের নিজের লেখা — chips সাজে "+ ছাঁকনি", বাকিগুলোয় "Filter" ([[TheChipSaidOneAndNobodyKnewWhichFilter]]) --}}
    <button type="button" x-on:click="toggle" data-filter-toggle aria-controls="list-filter-panel" class="list-filter-open"
            @if ($filterMode === 'chips') data-look-chip @endif>
        <x-ui.icon name="filter" class="size-4" />
        {{-- ⭐ অনুমোদিত নমুনা (১৪ নভেম্বর ২০২৬): বোতামে কেবল "ছাঁকনি" আর পাশে সংখ্যা — "+" নেই; বাকি ন'টা সাজ আগের মতো --}}
        {{ $filterMode === 'chips' ? __('core.list_filter.title') : __('core.toolbar.filter') }}
        @if ($facets['count'] > 0)
            <span class="list-filter-badge" data-filter-toggle-count>{{ $facets['count'] }}</span>
        @endif
    </button>
</span>
@if ($facets['chips'] !== [])
    {{-- ⭐ নমুনার মতো বারের নিচে নিজের লাইনে (basis-full + order-last) — বারটা এক লাইনেই থাকে --}}
    <span class="order-last flex basis-full flex-wrap items-center gap-1" data-chip-row data-list-part="chips">
        @foreach ($facets['chips'] as $chip)
            <a data-facet href="{{ $chip['url'] }}" class="list-filter-chip tone-{{ $chip['tone'] }}"
               title="{{ __('core.toolbar.remove_filter') }}">
                <span class="truncate">{{ $chip['text'] }}</span>
                <span class="x" aria-hidden="true">✕</span>
            </a>
        @endforeach
        <a href="{{ $facets['clear'] }}" class="list-filter-clearall" data-chips-clear>{{ __('core.list_filter.clear_chips') }}</a>
    </span>
@endif
