{{--
    একটা প্রতিষ্ঠানের সারির কাজ — বদলানো, আর বন্ধ/চালু।
    ⓘ মোছা নেই: পুরনো ঋণ ও আমানত নামটা ধরে রাখে, তাই কেবল বন্ধ করা যায়।
--}}
@can('finance.institution.manage')
    {{-- ⭐ তিন বিন্দুর বদলে "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"); কাজগুলো আগের মতোই --}}
    @php $items = [
        ['label' => __('finance::institution.edit'), 'url' => route('finance.institution.edit', $institution)],
        [
            'label' => $institution->is_active ? __('finance::institution.deactivate') : __('finance::institution.activate'),
            'url' => route('finance.institution.toggle', $institution),
            'method' => 'patch',
            'tone' => $institution->is_active ? 'danger' : null,
        ],
    ]; @endphp
    <x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($institution), \App\Core\Support\RowMenu::activeState($institution), null, $institution->code ?? null, 'finance')" />
@endcan
