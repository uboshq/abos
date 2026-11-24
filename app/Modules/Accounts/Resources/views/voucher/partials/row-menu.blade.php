{{--
    ভাউচারের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: খসড়ায় `accounts.voucher.update` দিয়ে সম্পাদনা আর পোস্ট
    (পাতায়, সারাংশ দেখে), `accounts.voucher.delete` দিয়ে বাতিল — খসড়ায় খসড়া বাতিল, পোস্ট হলে উল্টো দাখিলা ("বাতিল (রিভার্স)")।
    ⓘ ছাপা, PDF আর পাঠানো `view` নীতিতে — পাতার ছাপার মেনুর মতোই।
--}}
@php($can = auth()->user())
@php($draft = $voucher->isDraft())
{{-- ⓘ এই সারির প্রশ্ন একবার — ছাপা, PDF আর পাঠানো একই উত্তর নেয় (সইকারী কি না, তা খুঁজতে কোয়েরি লাগে) --}}
@php($mayView = (bool) $can?->can('view', $voucher))
@php($open = \App\Core\Support\RowMenu::monthOpen($voucher->trx_date))
<x-ui.paper-row-menu
    :paper="$voucher"
    :show="route('accounts.voucher.show', $voucher)"
    :state="($awaiting ?? false) ? __('core.status.awaiting_approval') : __('core.status.'.$voucher->status)"
    module="accounts"
    :edit="route('accounts.voucher.edit', $voucher)"
    :editable="$draft && $open && (bool) $can?->can('accounts.voucher.update')"
    :confirmable="$draft && (bool) $can?->can('accounts.voucher.update')"
    :cancellable="(bool) $can?->can('accounts.voucher.delete')"
    :cancel-label="__('core.row_menu.reverse')"
    :draft-cancel-label="($awaiting ?? false) ? __('core.row_menu.cancel_paper') : null"
    :prints="$mayView ? [[__('core.row_menu.print'), route('accounts.voucher.print', $voucher)]] : []"
    :pdf="$mayView ? route('accounts.voucher.print', ['voucher' => $voucher, 'download' => 1]) : null"
    :share="$mayView ? ['route' => 'accounts.voucher.print', 'param' => 'voucher', 'type' => 'accounts_voucher'] : null" />
