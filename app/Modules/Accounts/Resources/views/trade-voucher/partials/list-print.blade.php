{{-- ছাপা — নতুন ট্যাবে, কোম্পানির বাছা মাপে ([[TradeVoucherPrintController]]) --}}
<a href="{{ route('accounts.trade_voucher.print', ['kind' => $voucher->kind, 'voucher' => $voucher->id]) }}"
   target="_blank" rel="noopener" data-trade-voucher-print
   class="inline-flex items-center gap-1 text-(--color-brand-600) underline-offset-2 hover:underline">
    <x-ui.icon name="printer" class="size-4" />
    <span>{{ __('core.action.print') }}</span>
</a>
