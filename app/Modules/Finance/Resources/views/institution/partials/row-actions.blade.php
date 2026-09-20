{{--
    একটা প্রতিষ্ঠানের সারির কাজ — বদলানো, আর বন্ধ/চালু।
    ⓘ মোছা নেই: পুরনো ঋণ ও আমানত নামটা ধরে রাখে, তাই কেবল বন্ধ করা যায়।
--}}
@can('finance.institution.manage')
    <x-ui.row-actions :items="[
        ['label' => __('finance::institution.edit'), 'url' => route('finance.institution.edit', $institution)],
        [
            'label' => $institution->is_active ? __('finance::institution.deactivate') : __('finance::institution.activate'),
            'url' => route('finance.institution.toggle', $institution),
            'method' => 'patch',
            'tone' => $institution->is_active ? 'danger' : null,
        ],
    ]" />
@endcan
