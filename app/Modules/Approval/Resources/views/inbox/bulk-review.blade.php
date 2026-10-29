{{--
    ⭐ একসাথে সইয়ের আগে দেখা — দফা ১৩ (অনুমোদন মডিউলের নকশা, মালিক ২১ অক্টোবর ২০২৬)।

    ⓘ কয়টা কাগজে সই হবে আর মোট কত টাকা, তারপর কোনগুলো কেন বাদ। ⛔ আগে বোতামে চাপ দিলেই সই হত — কয়টা, কত টাকা আর কেন
    কিছু বাদ পড়ল তা সই হয়ে যাওয়ার পরে একটা লাইনে বলা হত। ⓘ নিয়ম [[BulkApproval::review()]]-এর, আসল সইও সেটাই মানে।
--}}
@php
    $labels = app(\App\Core\Engines\Approval\ApprovalLabel::class);
    $money = fn ($a) => $a === null ? '—' : \App\Core\Support\Money::format((string) $a);
    $count = count($sign);
    // ⓘ বাদ পড়াগুলোর মোটও — কত টাকার কাগজ একটা একটা করে দেখতে হবে (টাকার তালিকা মোটে শেষ হয়)
    $skipTotal = array_reduce($skip, fn (string $sum, array $r) => bcadd($sum, (string) ($r['approval']->amount ?? '0'), 4), '0');
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::message.bulk_review_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('approval::message.bulk_review_title')"
                          :subtitle="$count > 0
                              ? __('approval::message.bulk_will_sign', ['count' => $count, 'total' => \App\Core\Support\Money::format($total)])
                              : __('approval::message.bulk_review_none')" />
    </x-slot:header>

    <div class="space-y-4" data-bulk-review>
        @if ($count > 0)
            <section data-bulk-sign class="space-y-2">
                <x-ui.table compact
                            :empty="__('approval::message.bulk_review_none')"
                            :rows="$sign"
                            :columns="[
                                ['key' => 'what', 'label' => __('approval::field.action'),
                                 'render' => fn ($a) => $labels->of((string) $a->module, (string) $a->action)],
                                ['key' => 'requested_by', 'label' => __('approval::field.requested_by'),
                                 'render' => fn ($a) => $a->requesterName()],
                                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true,
                                 'render' => fn ($a) => $money($a->amount)],
                            ]"
                            :totals="['amount' => \App\Core\Support\Money::format($total)]"
                            :totalsLabel="__('core.print.total')" />

                <form method="POST" action="{{ route('approval.inbox.bulk') }}"
                      data-confirm="{{ __('approval::message.confirm_bulk_counted', ['count' => $count, 'total' => \App\Core\Support\Money::format($total)]) }}"
                      class="flex flex-wrap items-center justify-end gap-2">
                    @csrf
                    @foreach ($sign as $a)
                        <input type="hidden" name="ids[]" value="{{ $a->id }}">
                    @endforeach
                    <a href="{{ route('approval.inbox.index') }}"
                       class="rounded-(--radius-field) border border-(--color-border) px-3 py-2 text-sm">
                        {{ __('approval::message.bulk_back') }}
                    </a>
                    <x-ui.button type="submit">{{ __('approval::message.bulk_sign_now', ['count' => $count]) }}</x-ui.button>
                </form>
            </section>
        @else
            <a href="{{ route('approval.inbox.index') }}"
               class="inline-block rounded-(--radius-field) border border-(--color-border) px-3 py-2 text-sm">
                {{ __('approval::message.bulk_back') }}
            </a>
        @endif

        @if ($skip !== [])
            <section data-bulk-skip class="space-y-2">
                <h2 class="text-sm font-semibold">{{ __('approval::message.bulk_left_out') }}</h2>
                <x-ui.table compact
                            :empty="__('approval::message.bulk_left_out')"
                            :rows="$skip"
                            :columns="[
                                ['key' => 'what', 'label' => __('approval::field.action'),
                                 'render' => fn ($r) => $labels->of((string) $r['approval']->module, (string) $r['approval']->action)],
                                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true,
                                 'render' => fn ($r) => $money($r['approval']->amount)],
                                ['key' => 'why', 'label' => __('approval::field.reason'),
                                 'render' => fn ($r) => __('approval::message.bulk_why_'.$r['why'])],
                            ]"
                            :totals="['amount' => \App\Core\Support\Money::format($skipTotal)]"
                            :totalsLabel="__('core.print.total')" />
            </section>
        @endif
    </div>
</x-layouts.app>
