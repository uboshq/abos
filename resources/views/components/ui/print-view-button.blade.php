@props(['href' => null])

{{--
    ⭐ "প্রিন্ট ভিউ" — রিপোর্টের নিজের কাগজ নতুন ট্যাবে (মালিক, ২৩ নভেম্বর ২০২৬; [[ReportPaper]], [[ListPaper]])।
    ⓘ পর্দা থাকে যেখানে ছিল। ⛔ নামানোর চাবি ছাড়া বোতাম নেই — `?export=` দরজা একই চাবিতে ([[ExportListing]])।
--}}
@can('system_admin.list.export')
    <x-ui.button tone="secondary" icon="printer" target="_blank" rel="noopener" data-print-view data-no-prefetch
                 :href="$href ?? \App\Core\Support\ReportPaper::href()">
        {{ __('core.action.print_view') }}
    </x-ui.button>
@endcan
