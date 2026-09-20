{{-- একটা পলিসির সারির কাজ — দেখা, বদলানো, নবায়ন, বন্ধ/চালু। মোছা নেই: প্রিমিয়ামের ইতিহাস পলিসিতে বাঁধা। --}}
@php
    $items = [['label' => __('core.action.view'), 'url' => route('finance.insurance.show', $policy)]];

    if (auth()->user()?->can('finance.insurance.manage')) {
        $items[] = ['label' => __('finance::insurance.edit'), 'url' => route('finance.insurance.edit', $policy)];
        $items[] = ['label' => __('finance::insurance.renew'), 'url' => route('finance.insurance.renew_form', $policy)];
        $items[] = [
            'label' => $policy->is_active ? __('finance::insurance.deactivate') : __('finance::insurance.activate'),
            'url' => route('finance.insurance.toggle', $policy),
            'method' => 'patch',
            'tone' => $policy->is_active ? 'danger' : null,
        ];
    }
@endphp
<x-ui.row-actions :items="$items" />
