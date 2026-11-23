{{--
    ⭐ নতুন বিক্রয় ভাউচার (SV) / ক্রয় ভাউচার (PUV) — স্টক ছাড়া, মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।

    ⓘ মাথা এক সারিতে: পক্ষ | তারিখ·সময় (তালা) | শেষ দিন (পক্ষের শর্ত থেকে, তালা) | SV: গ্রাহকের রেফ/পিও; PUV: সরবরাহকারীর
    বিল নম্বর + মূসক-৬.৩ নম্বর ও তারিখ | মোট। ⛔ টাকার মাধ্যম, ব্যাংক বা নগদের ঘর নেই — টাকা আসে রসিদে (RCV), যায় পরিশোধে।
    ⓘ PUV-র সারাংশে উৎসে কর (TDS), নিচের লাইন "সরবরাহকারী পাবেন" = মোট − TDS; উৎসে ভ্যাট (VDS) নেই।
--}}
@php
    $sale = $kind === 'sale';
    $doc = $sale ? 'sv' : 'puv';
    $title = __('accounts::trade.new_'.$kind);
    $chip = trim(implode(' · ', array_filter([$numberPreview, $sale ? __('accounts::trade_form.chip_sale') : null])));
    $types = [
        ['key' => 'product', 'label' => __('accounts::trade_form.type_product')],
        ['key' => 'service', 'label' => __('accounts::trade_form.type_service')],
        ['key' => 'account', 'label' => __('accounts::trade_form.type_account')],
    ];

    // ⓘ কেবল এই কাগজের দামের দিক — বিক্রয় ভাউচারের পাতায় কেনা দাম যায় না
    $pickProducts = array_map(fn (array $p) => [
        'id' => $p['id'], 'name' => $p['name'], 'unit' => $p['unit'],
        'sale_price' => $sale ? $p['sale_price'] : '0', 'purchase_price' => $sale ? '0' : $p['purchase_price'],
        'tax_id' => $p['tax_id'], 'vat_rate' => $p['vat_rate'],
    ], $products);

    $oldLines = json_decode((string) old('lines_json', ''), true);

    $config = [
        'doc' => $doc,
        'products' => $pickProducts,
        'taxes' => $taxes,
        'accounts' => $accounts,
        'productAccount' => $productAccount,
        'partyId' => old('party_id', $partyId),
        'partyNames' => collect($parties)->mapWithKeys(fn (array $o) => [(string) $o['id'] => (string) $o['label']])->all(),
        'creditDays' => (object) $creditDays,
        'today' => $now->toDateString(),
        'tds' => (string) old('tds_amount', ''),
        'lines' => is_array($oldLines) ? $oldLines : [],
        'labels' => collect(['1110', '1139', '2118', '2120', '2121'])
            ->mapWithKeys(fn (string $c) => ['acc_'.$c => __('accounts::trade_form.acc_'.$c)])->all(),
        'text' => [
            'accountOf' => __('accounts::trade_form.account_of'),
            'vatPrefix' => __('accounts::trade_form.vat_prefix'),
            'days' => __('accounts::trade_form.days'),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$title" class="tv-head tv-tone-{{ $tone }}" data-trade-head>
            <x-slot:actions>
                @if (filled($chip))
                    <span class="tv-chip tv-chip-doc num" data-number-preview>{{ $chip }}</span>
                @endif
                <span class="tv-chip tv-chip-st">{{ __('core.status.draft') }}</span>
                <span class="tv-chip tv-chip-ap" data-approver>
                    @if ($approver)
                        {{ __('accounts::trade_form.approver', ['who' => $approver['who']]) }}@if ($approver['levels'] > 1) · {{ __('accounts::trade_form.approver_levels', ['n' => $approver['levels']]) }}@endif
                    @else
                        {{ __('accounts::trade_form.approver_none') }}
                    @endif
                </span>
                @if ($inventoryOn)
                    <span class="tv-badge tv-badge-info" data-stock-does-not-move>{{ __('accounts::trade.stock_does_not_move') }}</span>
                @endif
                @if ($binMissing && $sale)
                    <span class="tv-badge tv-badge-warn" data-bin-missing>{{ __('accounts::note.bin_missing') }}</span>
                @endif
                <span class="tv-grow"></span>
                <x-ui.button :href="$listUrl" tone="secondary">{{ __('accounts::trade_form.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="tv-form" name="submit" value="draft" tone="secondary" data-save-draft>
                    {{ __('accounts::trade_form.save_draft') }}
                </x-ui.button>
                <x-ui.button type="submit" form="tv-form" name="submit" value="send" tone="primary" class="tv-pri" data-send>
                    {{ __('accounts::trade_form.send') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    <form id="tv-form" method="POST" action="{{ $storeUrl }}" novalidate
          class="tv-screen tv-tone-{{ $tone }}" data-trade-form="{{ $kind }}"
          x-data="tradeLines(@js($config))" x-on:change="changed($event)">
        @csrf

        <div class="tv-card">
            <div @class(['tv-hdr', 'tv-hdr-sale' => $sale, 'tv-hdr-purchase' => ! $sale])>
                <div class="tv-f">
                    <span class="tv-label">{{ $sale ? __('accounts::trade_form.party_customer') : __('accounts::trade_form.party_supplier') }}</span>
                    <x-ui.party-search name="party_id" :options="$parties" :selected="old('party_id', $partyId)"
                                       :aria-label="$sale ? __('accounts::trade_form.party_customer') : __('accounts::trade_form.party_supplier')" />
                    @error('party_id')<span class="tv-badge tv-badge-bad">{{ $message }}</span>@enderror
                </div>
                <div class="tv-f">
                    <label class="tv-label" for="tv-when">{{ __('accounts::trade_form.date_time') }}</label>
                    <div class="tv-lock">
                        <input id="tv-when" type="text" class="num" readonly value="{{ $now->format('d M Y · H:i') }}">
                        <x-ui.icon name="lock" :size="13" />
                    </div>
                </div>
                <div class="tv-f">
                    <label class="tv-label" for="tv-due">{{ __('accounts::trade_form.due_date') }}</label>
                    <div class="tv-lock">
                        <input id="tv-due" type="text" class="num" readonly :value="dueText" data-due-date>
                        <x-ui.icon name="lock" :size="13" />
                    </div>
                </div>
                <div class="tv-f">
                    <label class="tv-label" for="tv-ref">{{ $sale ? __('accounts::trade_form.ref_sale') : __('accounts::trade_form.ref_purchase') }}</label>
                    <input id="tv-ref" type="text" name="ref_no" maxlength="64" value="{{ old('ref_no') }}">
                    @error('ref_no')<span class="tv-badge tv-badge-bad">{{ $message }}</span>@enderror
                </div>
                @unless ($sale)
                    <div class="tv-f">
                        <label class="tv-label" for="tv-mushak">{{ __('accounts::trade_form.mushak_no') }}</label>
                        <input id="tv-mushak" type="text" name="mushak_no" maxlength="64" value="{{ old('mushak_no') }}">
                    </div>
                    <div class="tv-f">
                        <span class="tv-label">{{ __('accounts::trade_form.mushak_date') }}</span>
                        <x-ui.date dense name="mushak_date" id="tv-mushak-date" :value="old('mushak_date')" :max="$now->toDateString()" />
                        @error('mushak_date')<span class="tv-badge tv-badge-bad">{{ $message }}</span>@enderror
                    </div>
                @endunless
                <div class="tv-f">
                    <label class="tv-label" for="tv-total">{{ __('accounts::trade_form.total') }}</label>
                    <input id="tv-total" type="text" class="num tv-n" readonly :value="sumGrossText">
                </div>
            </div>
        </div>

        @include('accounts::partials.trade-lines', [
            'types' => $types,
            'pickProducts' => $pickProducts,
            'isNote' => false,
            'returnLabel' => null,
            'returnChip' => null,
            'returnNote' => null,
            'bottomLabel' => $sale ? __('accounts::trade_form.bottom_sale') : __('accounts::trade_form.bottom_purchase'),
            'withTds' => ! $sale,
        ])
    </form>
</x-layouts.app>
