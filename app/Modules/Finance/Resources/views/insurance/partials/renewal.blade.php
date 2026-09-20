{{--
    নবায়নের অবস্থা — কত দিন বাকি, বা কত দিন আগে পেরিয়েছে।
    ⓘ ৩০ দিনের ভেতরে এলে হলুদ, পেরোলে লাল ([[InsurancePolicy::WARN_DAYS]])।
--}}
@php
    $days = $policy->daysLeft();
    $tone = ! $policy->is_active ? 'draft' : ($days < 0 ? 'danger' : ($days <= \App\Modules\Finance\Models\InsurancePolicy::WARN_DAYS ? 'pending' : 'success'));
@endphp
<span class="inline-flex flex-col">
    <span>{{ $policy->ends_on->format('d M Y') }}</span>
    @if ($policy->is_active)
        <x-ui.badge :tone="$tone" class="mt-0.5 self-start">
            {{ $days < 0
                ? __('finance::insurance.lapsed', ['days' => -$days])
                : ($days === 0 ? __('finance::insurance.ends_today') : __('finance::insurance.days_left', ['days' => $days])) }}
        </x-ui.badge>
    @endif
</span>
