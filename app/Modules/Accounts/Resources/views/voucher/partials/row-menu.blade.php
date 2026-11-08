{{--
    ভাউচারের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: খসড়ায় `accounts.voucher.update` দিয়ে সম্পাদনা আর পোস্ট
    (পাতায়, সারাংশ দেখে), `accounts.voucher.delete` দিয়ে বাতিল — খসড়ায় খসড়া বাতিল, পোস্ট হলে উল্টো দাখিলা ("বাতিল (রিভার্স)")।
    ⓘ ছাপা, PDF আর পাঠানো `view` নীতিতে — পাতার ছাপার মেনুর মতোই।
--}}
@php($can = auth()->user())
@php($draft = $voucher->isDraft())
@php($open = \App\Core\Support\RowMenu::monthOpen($voucher->trx_date))
<x-ui.paper-row-menu
    :paper="$voucher"
    :show="route('accounts.voucher.show', $voucher)"
    :state="__('core.status.'.$voucher->status)"
    module="accounts"
    :edit="route('accounts.voucher.edit', $voucher)"
    :editable="$draft && $open && (bool) $can?->can('accounts.voucher.update')"
    :confirmable="$draft && (bool) $can?->can('accounts.voucher.update')"
    :cancellable="(bool) $can?->can('accounts.voucher.delete')"
    :cancel-label="__('core.row_menu.reverse')"
    :prints="$can?->can('view', $voucher) ? [[__('core.row_menu.print'), route('accounts.voucher.print', $voucher)]] : []"
    :pdf="$can?->can('view', $voucher) ? route('accounts.voucher.print', ['voucher' => $voucher, 'download' => 1]) : null"
    :share="$can?->can('view', $voucher) ? ['route' => 'accounts.voucher.print', 'param' => 'voucher', 'type' => 'accounts_voucher'] : null" />
