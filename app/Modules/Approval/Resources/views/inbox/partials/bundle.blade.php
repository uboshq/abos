{{--
    ⭐ এক বিক্রি, এক পাতা — নিশ্চিতকরণ, ২৮ সেপ্টেম্বর ২০২৬ ([[ApprovalBundles]])।

    ⓘ মালিক: *"অ্যাপ্রভাল করার সময় পরিবহন দেখাচ্ছে না, পরিবহনের ভাড়াও না, জমা টাকার
    হিসাবও না … এক নজরে সব"*। তাই পণ্য, মোট, চালান ও বিলের নম্বর, পরিবহন, ভাড়া,
    প্রতিটা জমা (স্লিপসহ) আর বাকি — সব এখানে। ⚠️ ভিতরের অংশ একক কাগজের পাতার
    সাথে একই ([[sheet-body]]), তাই দুই পাতায় ঘর দুই রকম হতে পারে না।
--}}
@php
    $sheet = $bundle;
    $columns = array_map(fn (array $c) => [
        'key' => $c['key'],
        'label' => $c['label'],
        'numeric' => $c['numeric'] ?? false,
        'render' => fn (array $row) => $row[$c['key']] ?? '',
    ], $bundle['columns']);
    $rows = $bundle['rows'];
@endphp

<div data-sale-bundle
     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <h2 class="text-sm font-semibold">{{ __('approval::field.bundle_title') }}</h2>

    @include('approval::inbox.partials.sheet-body')

    @if ($bundle['deposits'] !== [])
        <div class="mt-4">
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('approval::field.deposits') }}</p>

            <x-ui.table compact
                        :empty="__('approval::field.deposits')"
                        :rows="$bundle['deposits']"
                        :columns="[
                            ['key' => 'method', 'label' => __('approval::field.method'), 'render' => fn ($d) => $d['method']],
                            ['key' => 'account', 'label' => __('approval::field.account'), 'render' => fn ($d) => $d['account']],
                            ['key' => 'reference', 'label' => __('approval::field.reference'), 'render' => fn ($d) => $d['reference']],
                            ['key' => 'slip', 'label' => __('approval::field.slip'),
                             'render' => fn ($d) => view('approval::inbox.partials.slip-links', ['slips' => $d['slips']])],
                            ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true, 'render' => fn ($d) => $d['amount']],
                        ]" />
        </div>
    @endif
</div>
