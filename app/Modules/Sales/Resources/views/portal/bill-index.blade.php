{{--
    ⭐ গ্রাহকের বিলের ঘর — নিজের পাকা বিল, বাকি / শোধ / সব, তারিখের ছাঁকনি (গ্রাহক পোর্টাল, ধাপ ১; [[PortalInvoiceController]])।

    ⓘ প্রতিটা সারিতে মোট, শোধ আর বাকি — অফিসের আদায়ের পর্দার সেই একই অঙ্ক ([[CustomerPapers::bills()]])।
    ⓘ ফোনে কার্ড এক কলামে, বড় পর্দায় দুই কলামে — টেবিল নয়, কারণ ৪০০ পিক্সেলে পাঁচ কলামের টেবিল পাশে গড়াত।
--}}
<x-sales::portal.layout :customer="$customer" :title="__('sales::portal_bill.title')">
    <h1 class="mb-3 text-lg font-semibold">{{ __('sales::portal_bill.title') }}</h1>

    <x-ui.list-tabs :label="__('sales::portal_bill.title')" :tabs="collect(\App\Modules\Sales\Http\Controllers\PortalInvoiceController::STATES)->map(fn ($key) => [
        'key' => $key,
        'label' => __('sales::portal_bill.'.$key),
        'url' => route('sales.portal.bill.index', array_filter(['state' => $key, 'from' => $from, 'to' => $to])),
        'active' => $state === $key,
    ])->all()" />

    {{-- ছাঁকনি — GET, কারণ এটা দেখা, কোনো কাজ নয় --}}
    <form method="GET" action="{{ route('sales.portal.bill.index') }}" data-portal-bill-filter
          class="mb-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="state" value="{{ $state }}">
        <label class="min-w-36 flex-1">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::portal.from') }}</span>
            <x-ui.date name="from" :value="$from" />
        </label>
        <label class="min-w-36 flex-1">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::portal.to') }}</span>
            <x-ui.date name="to" :value="$to" />
        </label>
        <button type="submit"
                class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-4 text-sm font-medium text-white">
            {{ __('sales::portal.show') }}
        </button>
    </form>

    <div class="grid gap-2 sm:grid-cols-2">
        @forelse ($bills as $bill)
            @php($due = $bill->dueAmount())
            <a href="{{ route('sales.portal.bill.show', $bill->public_id) }}" data-portal-bill-row="{{ $bill->document_no }}"
               class="block rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 hover:bg-(--color-surface-hover)">
                <div class="flex items-start justify-between gap-2">
                    <span class="font-semibold">{{ $bill->document_no }}</span>
                    <x-ui.badge :tone="bccomp($due, '0', 4) > 0 ? 'pending' : 'success'">
                        {{ bccomp($due, '0', 4) > 0 ? __('sales::portal_bill.open') : __('sales::portal_bill.fully_paid') }}
                    </x-ui.badge>
                </div>
                <div class="text-sm text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::format($bill->trx_date) }}</div>
                <dl class="mt-2 grid grid-cols-3 gap-1 text-sm">
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_bill.total') }}</dt>
                        <dd class="num">{{ \App\Core\Support\Money::format($bill->total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_bill.paid_amount') }}</dt>
                        <dd class="num">{{ \App\Core\Support\Money::format($bill->collectedAmount()) }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_bill.due') }}</dt>
                        <dd class="num font-semibold" data-portal-bill-due>{{ \App\Core\Support\Money::format($due) }}</dd>
                    </div>
                </dl>
            </a>
        @empty
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::portal_bill.none') }}</p>
        @endforelse
    </div>

    <x-ui.pager :rows="$bills" />

    <a href="{{ route('sales.portal.home') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::portal.title') }}
    </a>
</x-sales::portal.layout>
