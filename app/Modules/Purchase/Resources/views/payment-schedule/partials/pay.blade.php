@can('purchase.payment.create')
    <x-ui.button tone="primary" :href="route('purchase.payment.create', ['purchase_bill_id' => $bill->id])">
        {{ __('purchase::schedule.pay') }}
    </x-ui.button>
@endcan
