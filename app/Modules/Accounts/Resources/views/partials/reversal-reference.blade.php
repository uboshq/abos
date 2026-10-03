{{--
    উল্টো কাগজের সূত্র — পাকা ভাউচার বা নোটের পাতায় (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬; [[AccountsReversalService]])।
    চাই: $type (`voucher`/`note`), $id। ⓘ উল্টো কাগজ না থাকলে কিছুই আঁকে না।
--}}
@php($reversal = \App\Modules\Accounts\Models\Reversal::of($type, (int) $id))
@if ($reversal)
    <p role="status" data-reversal
       class="mb-4 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">
        <strong>{{ __('accounts::reversal.reference', ['rev' => $reversal->document_no]) }}</strong>
        — {{ __('accounts::reversal.reversed_on', ['date' => \App\Core\Support\DateFormat::format($reversal->trx_date), 'reason' => $reversal->reason]) }}
    </p>
@endif
