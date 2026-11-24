<section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <h2 class="mb-3 text-sm font-semibold">{{ __('promotion::coupon_screen.issue_heading') }}</h2>

    @if ($canIssue)
        <form method="POST" action="{{ route('promotion.coupon.store', $offer) }}" class="grid gap-3 sm:grid-cols-4">
            @csrf
            <x-ui.field name="code" :label="__('promotion::coupon.code')"
                        :hint="__('promotion::coupon_screen.code_hint')" />
            <x-ui.field name="count" type="number" :value="1" :label="__('promotion::coupon.count')"
                        :hint="__('promotion::coupon_screen.count_hint', ['max' => $maxBatch])" />
            <x-ui.field name="max_uses" type="number" :value="1" :label="__('promotion::coupon.max_uses')" required />
            <x-ui.field name="max_uses_per_customer" type="number" :label="__('promotion::coupon.max_uses_per_customer')" />
            <x-ui.field name="customer_id" type="number" :label="__('promotion::coupon.customer')"
                        :hint="__('promotion::coupon_screen.customer_hint')" />
            <x-ui.field name="valid_from" type="date" :label="__('promotion::coupon.valid_from')"
                        :hint="__('promotion::coupon_screen.dates_hint')" />
            <x-ui.field name="valid_to" type="date" :label="__('promotion::coupon.valid_to')" />
            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary">{{ __('promotion::coupon.issue') }}</x-ui.button>
            </div>
        </form>
    @elseif ($offer->status->isFinal())
        <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::coupon_screen.offer_final', ['code' => $offer->code]) }}</p>
    @else
        <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::coupon_screen.not_coupon_type', ['code' => $offer->code]) }}</p>
    @endif
</section>
