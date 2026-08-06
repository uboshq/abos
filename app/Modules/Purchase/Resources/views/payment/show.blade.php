{{-- একটা পরিশোধ। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $payment->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$payment->document_no" :subtitle="$payment->supplier?->name()">
            <x-slot:actions>
                @can('update', $payment)
                    <x-ui.button tone="secondary" :href="route('purchase.payment.edit', $payment)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('purchase.payment.confirm', $payment) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('purchase::action.confirm') }}</x-ui.button>
                    </form>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="space-y-4">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'purchase::field.date' => $payment->trx_date?->format('d/m/Y'),
                    'purchase::field.account' => $payment->account?->name() ?: '-',
                    'purchase::field.instrument' => $payment->instrument ?: '-',
                    'purchase::field.instrument_no' => $payment->instrument_no ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd class="mt-0.5"><x-purchase::status-badge :document="$payment" /></dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
                {{ __('purchase::message.payment_lines') }}
            </h2>

            {{--
                ভাগ না করা টাকাও থাকতে পারে — অগ্রিম।

                সরবরাহকারীকে আগাম টাকা দেওয়া হলে কোনো বিলের বিপরীতে ভাগ
                বসে না; খতিয়ানে প্রদেয় কমে, আর পরের বিলে সেটা সমন্বয় হয়।
                তাই লাইন ছাড়া পরিশোধ ভুল নয়, আর পর্দাটাও সেটা বলে।
            --}}
            <x-ui.table
                :empty="__('purchase::message.payment_unallocated')"
                :rows="$payment->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('purchase::field.line_no'), 'width' => '4rem'],
                    ['key' => 'purchase_bill_id', 'label' => __('purchase::field.bill'),
                     'render' => fn ($l) => $l->bill
                         ? view('purchase::components.doc-link', [
                             'document' => $l->bill, 'route' => 'purchase.bill.show'])
                         : '-'],
                    ['key' => 'amount', 'label' => __('purchase::field.amount'),
                     'numeric' => true, 'width' => '10rem',
                     'render' => fn ($l) => number_format((float) $l->amount, 2)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-purchase::totals :rows="[
                    'purchase::field.amount' => $payment->amount,
                ]" />
            </div>
        </section>

        @can('delete', $payment)
            @if ($payment->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-purchase::cancel-form :action="route('purchase.payment.cancel', $payment)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
