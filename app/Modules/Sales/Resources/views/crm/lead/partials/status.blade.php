{{-- ⓘ রং আগে নিচের পিএইচপি ব্লকে — কম্পোনেন্টের গুণে বহু-লাইনের match ঝুঁকি, তাই বাইরে।
     ⛔ এই মন্তব্যে ব্লকের নির্দেশক শব্দটা লেখা যাবে না: Blade মন্তব্য মোছার আগে ব্লক ধরে। --}}
@php
    $leadTone = match ($lead->status) {
        'converted' => 'success',
        'qualified' => 'info',
        'contacted' => 'pending',
        'lost' => 'danger',
        default => 'draft',
    };
@endphp
<x-ui.badge :tone="$leadTone">{{ __('sales::crm.lead_status.' . $lead->status) }}</x-ui.badge>
