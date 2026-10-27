{{--
    ⭐ একসাথে পোস্ট বা অনুমোদনে পাঠানোর আগে দেখা — অনুমোদন পর্দা ভাগ ২, দফা ১৪ ([[VoucherBulkPostController]])।

    তিন দল: পোস্ট হবে (অনুমোদন লাগে না বা আগেই অনুমোদিত) · অনুমোদনে যাবে · বাদ (কেন)। প্রতিটার সংখ্যা আর মোট টাকা।
    ⓘ কিছুই লেখে না; নিচের বোতাম চাপলে তবেই — ব্রাউজারের "নিশ্চিত?" পেরিয়ে (দফা ১২)।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format((string) ($v ?? '0'));
    $columns = [
        ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
         'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '12rem',
         'render' => fn ($v) => $v->document_no],
        ['key' => 'type', 'label' => __('accounts::field.voucher_type'), 'width' => '10rem',
         'render' => fn ($v) => __('accounts::menu.'.$v->type)],
        ['key' => 'narration', 'label' => __('core.table.narration')],
        ['key' => 'amount', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($v) => $money($v->amount)],
    ];
    $count = count($post) + count($ask);
    $total = bcadd($postTotal, $askTotal, 4);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::message.bulk_post_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::message.bulk_post_title')"
                          :subtitle="__('accounts::message.bulk_post_summary', [
                              'posted' => count($post), 'asked' => count($ask), 'skipped' => count($skip),
                          ])" />
    </x-slot:header>

    <div class="space-y-4" data-voucher-bulk-review>
        @if ($post !== [])
            <section data-bulk-post class="space-y-2">
                <h2 class="text-sm font-semibold">{{ __('accounts::message.bulk_post_will_post', ['count' => count($post)]) }}</h2>
                <x-ui.table compact :empty="''" :rows="$post" :columns="$columns"
                            :totals="['amount' => $money($postTotal)]" :totalsLabel="__('core.print.total')" />
            </section>
        @endif

        @if ($ask !== [])
            <section data-bulk-ask class="space-y-2">
                <h2 class="text-sm font-semibold">{{ __('accounts::message.bulk_post_will_ask', ['count' => count($ask)]) }}</h2>
                <x-ui.table compact :empty="''" :rows="$ask" :columns="$columns"
                            :totals="['amount' => $money($askTotal)]" :totalsLabel="__('core.print.total')" />
            </section>
        @endif

        <div class="flex flex-wrap items-center justify-end gap-2">
            <a href="{{ $back }}" class="rounded-(--radius-field) border border-(--color-border) px-3 py-2 text-sm">
                {{ __('accounts::action.bulk_post_back') }}
            </a>

            @if ($count > 0)
                <form method="POST" action="{{ route('accounts.voucher.bulk_post') }}"
                      data-confirm="{{ __('accounts::message.bulk_post_confirm', ['posted' => count($post), 'asked' => count($ask), 'total' => $money($total)]) }}">
                    @csrf
                    <input type="hidden" name="back" value="{{ $back }}">
                    @foreach ([...$post, ...$ask] as $v)
                        <input type="hidden" name="ids[]" value="{{ $v->id }}">
                    @endforeach
                    <x-ui.button type="submit" tone="primary">{{ __('accounts::action.bulk_post_now', ['count' => $count]) }}</x-ui.button>
                </form>
            @endif
        </div>

        @if ($skip !== [])
            <section data-bulk-skip class="space-y-2">
                <h2 class="text-sm font-semibold">{{ __('accounts::message.bulk_post_left_out_title') }}</h2>
                <x-ui.table compact :empty="''" :rows="$skip" :columns="[
                    ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '12rem',
                     'render' => fn ($r) => $r['voucher']->document_no],
                    ['key' => 'amount', 'label' => __('accounts::field.amount'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($r) => $money($r['voucher']->amount)],
                    ['key' => 'why', 'label' => __('accounts::field.bulk_post_why'),
                     'render' => fn ($r) => __('accounts::message.bulk_post_why_'.$r['why'])],
                ]" />
            </section>
        @endif
    </div>
</x-layouts.app>
