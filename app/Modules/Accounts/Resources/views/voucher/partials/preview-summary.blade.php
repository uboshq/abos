{{--
    ⭐ হিসাবের সারাংশ — খরচ, কন্ট্রা আর জাবেদায় (মালিক, ৬ নভেম্বর ২০২৬: "ভাউচারের কাজ korar somoy হিসাবের সারাংশ ase na eta deko";
    ৭ নভেম্বর: "baki kaj gulo kore rako")। রসিদ-পরিশোধের সারাংশের একই উৎস — সার্ভারের খাতার সারি ([[VoucherController::simulate()]],
    [[voucherPreview]]); পর্দা কিছু গোনে না। এক কার্ডে: বাঁয়ে অঙ্ক আর কথায়, ডানে খাতায় যা যাবে।
--}}
@php $journal = ($type ?? null) === \App\Modules\Accounts\Models\Voucher::JOURNAL; @endphp
<section data-boxed data-voucher-preview-summary
         x-data="voucherPreview({ url: @js(route('accounts.voucher.simulate')), voucherId: @js($voucher->exists ? $voucher->id : null) })"
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        <div>
            <h2 data-form-band="money" class="mb-3">{{ __('money.screen.summary') }}</h2>
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                @if ($journal)
                    <dt>{{ __('accounts::field.debit') }}</dt>
                    <dd class="num" data-preview-debit x-text="debitText">—</dd>
                    <dt>{{ __('accounts::field.credit') }}</dt>
                    <dd class="num" data-preview-credit x-text="creditText">—</dd>
                @else
                    <dt>{{ __('accounts::field.amount') }}</dt>
                    <dd class="num" data-preview-amount x-text="amountText">—</dd>
                    <dt>{{ __('money.band.cut') }}</dt>
                    <dd class="num" data-preview-cut x-text="cutText">—</dd>
                    <dt>{{ __('money.screen.sum.charge') }}</dt>
                    <dd class="num" data-preview-charge x-text="chargeText">—</dd>
                    <dt class="font-semibold">{{ __('money.screen.sum.net') }}</dt>
                    <dd class="num border-t border-(--color-border) pt-1 font-semibold" data-preview-net x-text="netText">—</dd>
                @endif
            </dl>
            <p class="mt-2 text-xs text-(--color-ink-muted)" data-preview-words x-show="wordsText !== ''" x-cloak x-text="wordsText"></p>
            <p class="mt-2 text-xs text-(--color-ink-muted)" data-preview-waits x-show="waiting">{{ __('accounts::message.ledger_empty') }}</p>
            <p class="mt-2 border-s-2 border-(--color-danger) px-3 py-1 text-xs text-(--color-danger)" data-preview-blocked x-show="blocked" x-cloak x-text="blockedText"></p>
        </div>

        <div>
            <h2 data-form-band="party" class="mb-3">{{ __('money.screen.ledger') }}</h2>
            <table class="w-full text-xs" data-preview-ledger x-show="hasRows" x-cloak>
                <tbody>
                    <template x-for="row in rows">
                        <tr class="border-b border-(--color-border)" data-preview-row>
                            <td class="py-1 pe-2"><span x-text="row.account"></span> <span class="text-(--color-ink-muted)" x-text="row.party"></span></td>
                            <td class="num py-1 text-end" x-text="row.debit"></td>
                            <td class="num py-1 text-end" x-text="row.credit"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p class="mt-2 text-xs font-semibold text-(--color-badge-success-ink)" data-preview-balanced x-show="balanced" x-cloak>{{ __('accounts::message.ledger_matches') }}</p>
            <p class="mt-2 text-xs font-semibold text-(--color-danger)" data-preview-unbalanced x-show="unbalanced" x-cloak>{{ __('money.band.unbalanced') }}</p>
        </div>
    </div>
</section>
