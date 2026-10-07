{{--
    ⭐ ক্যাশবাক্সের দায়িত্ব হস্তান্তর — Accounts-Finance অডিট ম৮, ৪ অক্টোবর ২০২৬ ([[TillHandoverService]])।

    ⓘ নতুন জন খাতার জের দেখেন, গুনে কম-বেশি পেলে গোনা অঙ্কটা লেখেন — পার্থক্য নগদ গণনার কাগজে যায়। ছক চালু থাকলে সইয়ের
    অপেক্ষা, তারপর বাক্স নতুন জনের। ইতিহাস এক সারিতে এক কথা (মালিকের পর্দা ডান দিক কাটে)।
--}}
<section data-boxed data-handover
         class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <h2 class="font-semibold">{{ __('accounts::custody.handover_title') }}</h2>

    @can('update', $till)
        @if ($till->is_active)
            <form method="POST" action="{{ route('accounts.till.handover', $till) }}" class="mt-3 grid gap-3 sm:grid-cols-4">
                @csrf
                <x-ui.select name="to_holder_id" :label="__('accounts::custody.handover_to')"
                             :options="$holders->reject(fn ($u) => (int) $u->id === (int) $till->holder_id)->mapWithKeys(fn ($u) => [$u->id => $u->name])"
                             placeholder="-" required />
                <x-ui.field name="counted_amount" type="number" step="1" min="0" numeric
                            :label="__('accounts::custody.handover_counted')"
                            :hint="__('accounts::custody.handover_book', ['amount' => \App\Core\Support\Money::format($balance)])" />
                <x-ui.field name="narration" :label="__('core.table.narration')" />
                <div class="flex items-end">
                    <x-ui.button type="submit" tone="primary">{{ __('accounts::custody.handover_action') }}</x-ui.button>
                </div>
            </form>
        @endif
    @endcan

    @if ($handovers->isNotEmpty())
        <ul class="mt-4 divide-y divide-(--color-border) text-sm">
            @foreach ($handovers as $h)
                <li class="py-2" data-handover-row="{{ $h->status }}">
                    <p class="font-medium">
                        {{ $h->document_no }} · {{ \App\Core\Support\DateFormat::format($h->trx_date) }} ·
                        {{ $h->fromHolder?->name ?? '—' }} → {{ $h->toHolder?->name ?? '—' }}
                    </p>
                    <p class="text-(--color-ink-muted)">
                        {{ __('accounts::custody.handover_book', ['amount' => \App\Core\Support\Money::format($h->book_balance)]) }}
                        @if ($h->counted_amount !== null)
                            · {{ __('accounts::custody.handover_counted_was', ['amount' => \App\Core\Support\Money::format($h->counted_amount)]) }}
                            @if ($h->cashCount)
                                · <a href="{{ route('accounts.count.show', $h->cashCount) }}" class="underline-offset-2 hover:underline">{{ $h->cashCount->document_no }}</a>
                            @endif
                        @endif
                    </p>
                    <p>
                        <x-ui.badge :tone="$h->isConfirmed() ? 'success' : ($h->isAwaiting() || $h->status === 'draft' ? 'pending' : 'danger')">
                            {{ __('accounts::custody.handover_state.'.$h->status) }}
                        </x-ui.badge>
                        @if (($h->isAwaiting() || $h->status === 'draft'))
                            @can('update', $till)
                                <form method="POST" action="{{ route('accounts.till.handover.cancel', [$till, $h]) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="ms-2 text-xs text-(--color-danger) underline-offset-2 hover:underline">
                                        {{ __('accounts::custody.handover_cancel') }}
                                    </button>
                                </form>
                            @endcan
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</section>
