{{--
    ভাই-কোম্পানির টাকা — এই কোম্পানি যা যা দিয়েছে।

    ── ⚠️ কেন কেবল "দেওয়া", "পাওয়া" নয় ────────────────────────────────
    সারিগুলো `acc_inter_company`-র, আর সেখানে `company_id` মানে **যে
    দিয়েছে**। BelongsToCompany ওটাই ছাঁকে, তাই পাওয়াগুলো এখানে আসে না।

    ⓘ লুকানো হয়নি — নিচে লেখা আছে, আর কারণটাও: পাওয়ার দিকের দাখিলা
    ঐ কোম্পানির খাতায় বসেছে, তাই দেখতে হলে ওখানে সুইচ করতে হয়।
--}}
@php
    $columns = [
        [
            'key' => 'trx_date',
            'label' => __('accounts::field.date'),
            'width' => '9rem',
            'render' => fn ($t) => \App\Core\Support\DateFormat::format($t->trx_date),
        ],
        [
            'key' => 'counter',
            'label' => __('accounts::field.inter_company_counter'),
            'render' => fn ($t) => $t->counterCompany?->name(),
        ],
        [
            /*
                ⭐ কাজটা কী ধরনের — দ্বিতীয় দফায় যোগ হলো।

                ⓘ এখন একটা সারি তিনটা জিনিসের যেকোনোটা হতে পারে: টাকা
                সরানো, তাদের খরচ দেওয়া, বা তাদের দেনা মেটানো। ⚠️ কেবল
                "কারণ" কলামটা দেখে সেটা বলা যায় না — মানুষ ওখানে যা খুশি
                লেখেন।

                ⓘ উত্তরটা আছে তাদের দিকের ভাউচারে: কোন **ধরনের** খাত
                ডেবিট হয়েছে। ⛔ সারিতে আলাদা কোনো ঘর রাখা হয়নি, কারণ
                সেটা দ্বিতীয় একটা সত্য হত — আর দুইটা একদিন আলাদা হত।
            */
            'key' => 'kind',
            'label' => __('accounts::field.inter_company_kind'),
            'width' => '8rem',
            'render' => fn ($t) => __('accounts::field.inter_company_kind_'
                .($kinds[$t->in_voucher_id] ?? 'unknown')),
        ],
        [
            'key' => 'purpose',
            'label' => __('accounts::field.inter_company_purpose'),
            'wrap' => true,
            'render' => fn ($t) => $t->purpose,
        ],
        [
            'key' => 'amount',
            'label' => __('accounts::field.amount'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($t) => view('accounts::report.partials.amount', ['value' => $t->amount]),
        ],
        [
            'key' => 'balanced',
            'label' => __('accounts::field.inter_company_both_sides'),
            'width' => '8rem',
            /*
                ⚠️ অবস্থার ঘর নয়, **দুইটা ভাউচারই আছে কি না**।

                ⓘ `status` "হয়ে গেছে" বলতে পারে অথচ এক পাশ বসেনি — আর
                তখন খাতা মেলে না। এই কলামটা সেই আসল প্রশ্নটাই করে।
            */
            'render' => fn ($t) => $t->isBalanced()
                ? __('accounts::field.inter_company_balanced')
                : __('accounts::field.inter_company_half'),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.inter_company') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.inter_company')"
                          :subtitle="__('accounts::message.inter_company_only_given')">
            <x-slot:actions>
                <x-ui.button :href="route('accounts.inter_company.create')" icon="plus">
                    {{ __('accounts::menu.inter_company_new') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.table :rows="$rows"
                :columns="$columns"
                :empty="__('accounts::message.inter_company_none')" />

    <x-ui.pager :rows="$rows" />
</x-layouts.app>
