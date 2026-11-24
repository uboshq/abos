{{-- ⓘ ⋯ — "শেষ করুন" পপআপে; শেষ হওয়া বাঁধনে কিছু নেই ([[DealerBindingService::end()]]) --}}
@if ($row->ends_on === null)
    <span x-data="{ open: false }" class="relative inline-block">
        <button type="button" x-on:click="open = ! open" x-on:click.outside="open = false" x-bind:aria-expanded="open.toString()" aria-label="{{ __('core.table.action_menu') }}"
                class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 hover:bg-(--color-surface-hover)">⋯</button>
        <span x-show="open" x-cloak class="absolute end-0 z-30 mt-1 block w-44 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-lg">
            <button type="button" popovertarget="pop-end-{{ $row->id }}" data-end-binding="{{ $row->id }}" class="block w-full px-3 py-2 text-start text-sm hover:bg-(--color-surface-hover)">{{ __('customer::binding.end_button') }}</button>
        </span>
    </span>
@endif
