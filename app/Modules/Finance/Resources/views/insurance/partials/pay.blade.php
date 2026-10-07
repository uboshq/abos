{{--
    প্রিমিয়ামের অবস্থা — দেওয়া হলে ভাউচারের নম্বর, না হলে "প্রিমিয়াম দিন"।

    ⓘ বোতামটা পরিশোধ ভাউচার খোলে `against_type=insurance_premium` নিয়ে;
    ভাউচার পোস্ট হলে সারিটা নিজে "দেওয়া হয়েছে" হয় — মূলধনের "টাকা এসেছে"
    বোতামের মতোই ([[capital/partials/state]])। টাকার সব প্রশ্ন (কোন খাত
    থেকে, চেক, চার্জ) ভাউচারের; এখানে একটাও নয়।
--}}
@if ($premium->isPaid())
    <span class="inline-flex items-center gap-1">
        <x-ui.badge tone="success">{{ __('finance::insurance.paid') }}</x-ui.badge>
        @if ($premium->voucher)
            <a href="{{ route('accounts.voucher.show', $premium->voucher) }}"
               class="text-2xs text-(--color-brand-600) underline-offset-2 hover:underline">
                {{ $premium->voucher->document_no }}
            </a>
        @endif
    </span>
@else
    @can('accounts.voucher.create')
        <x-ui.button tone="primary"
                     :href="route('accounts.voucher.create', [
                         'type' => 'payment',
                         'against_type' => 'insurance_premium',
                         'against_id' => $premium->getKey(),
                         'amount' => $premium->amount,
                         'narration' => __('finance::insurance.premium').' — '.$policy->policy_no.' · '.$policy->subject,
                     ])">
            {{ __('finance::insurance.pay_premium') }}
        </x-ui.button>
    @else
        <x-ui.badge tone="pending">{{ __('finance::insurance.unpaid') }}</x-ui.badge>
    @endcan
@endif
