{{--
    ⭐ নতুন নোট — ক্রেডিট নোট (CN), গ্রাহকের ডেবিট নোট, সরবরাহকারীর ডেবিট নোট (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬:
    "আমি ওকে করে দিলাম")।

    ⓘ মাথা এক সারিতে: নাম · নম্বর · অবস্থা · অনুমোদনকারী … বোতাম। নিচে মাথার কার্ড (পক্ষ | তারিখ·সময় | কারণ | মোট), দ্বিতীয়
    সারিতে মূল বিল + "⇣ মূল বিল থেকে আনুন" আর ভ্যাটের ঘর (সরবরাহকারীর DN-এ তাঁর মূসক-৬.৭)। তারপর সারির ছক, ন্যারেশন,
    "খাতায় যা উঠবে", ডানে সারাংশ ([[accounts::partials.trade-lines]], যুক্তি [[trade-lines.js]])।
    ⛔ শাখার ঘর নেই — শাখা উপরের বার থেকে। ⛔ ব্যাখ্যার লাইন বা নোটিশের পটি নেই — সতর্কতা কেবল ছোট ব্যাজ।
--}}
@php
    $sells = $type !== 'dn';
    $title = __('accounts::note.new_'.$type);
    $chip = trim(implode(' · ', array_filter([
        $numberPreview,
        in_array($type, ['cn', 'cdn'], true) ? __('accounts::note_form.chip_'.$type) : null,
    ])));
    $types = array_values(array_filter([
        $productsAllowed ? ['key' => 'product', 'label' => __('accounts::trade_form.type_product')] : null,
        ['key' => 'account', 'label' => __('accounts::trade_form.type_account')],
    ]));
    $returnLabel = in_array($type, ['cn', 'dn'], true) ? __('accounts::note_form.returned_'.$type) : null;
    $mainKind = ['cn' => 'customer', 'cdn' => 'customer', 'dn' => 'supplier'][$type];
    $bottomLabel = $kind === $mainKind ? __('accounts::note_form.bottom_'.$type) : __('accounts::note_form.bottom_other');

    // ⓘ কেবল এই কাগজের দামের দিক — বিক্রয়ের নোটে কেনা দাম পাতায় যায় না
    // ⛔ কেনা দর কেবল `inventory.cost.view`-এ — চাবি না থাকলে পাতায়ই যায় না, দর হাতে লেখা
    $seesCost = auth()->user()?->can('inventory.cost.view') === true;
    $pickProducts = array_map(fn (array $p) => [
        'id' => $p['id'], 'name' => $p['name'], 'unit' => $p['unit'],
        'sale_price' => $sells ? $p['sale_price'] : '0', 'purchase_price' => ! $sells && $seesCost ? $p['purchase_price'] : '0',
        'tax_id' => $p['tax_id'], 'vat_rate' => $p['vat_rate'],
    ], $products);

    $oldLines = json_decode((string) old('lines_json', ''), true);

    $config = [
        'doc' => $type,
        'kind' => $kind,
        'products' => $pickProducts,
        'taxes' => $taxes,
        'accounts' => $accounts,
        'productsAllowed' => (bool) $productsAllowed,
        'partyId' => old('party_id', $partyId),
        'partyNames' => collect($parties)->mapWithKeys(fn (array $o) => [(string) $o['id'] => (string) $o['label']])->all(),
        'billNo' => (string) old('against_no', $billNo),
        'billUrl' => $billUrl,
        'today' => $now->toDateString(),
        'supplierNoteNo' => (string) old('supplier_note_no', ''),
        'supplierNoteDate' => (string) old('supplier_note_date', ''),
        'lines' => is_array($oldLines) ? $oldLines : [],
        'labels' => collect(['1110', '1120', '1139', '2111', '2118', '2120', '2121', '4100', '4110', '5150', 'party'])
            ->mapWithKeys(fn (string $c) => ['acc_'.$c => __('accounts::trade_form.acc_'.$c)])->all(),
        'text' => [
            'needParty' => __('accounts::note_form.need_party'),
            'needNo' => __('accounts::note_form.need_no'),
            'billFailed' => __('accounts::note_form.bill_failed'),
            'vatFromBill' => __('accounts::note_form.vat_from_bill'),
            'vatNoBill' => __('accounts::note_form.vat_no_bill'),
            'room' => __('accounts::note_form.bill_room'),
            'accountOf' => __('accounts::trade_form.account_of'),
            'vatPrefix' => __('accounts::trade_form.vat_prefix'),
            'days' => __('accounts::trade_form.days'),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$title" class="tv-head tv-tone-{{ $tone }}" data-note-head>
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
                @if ($binMissing && $type !== 'dn')
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
          class="tv-screen tv-tone-{{ $tone }}" data-note-form="{{ $type }}"
          x-data="tradeLines(@js($config))" x-on:change="changed($event)">
        @csrf
        <input type="hidden" name="note_type" value="{{ $type }}">
        <input type="hidden" name="party_kind" value="{{ $kind }}">

        <div class="tv-card">
            <div class="tv-hdr tv-hdr-note">
                <div class="tv-f">
                    <span class="tv-label">{{ __('accounts::note_form.party_label') }}</span>
                    <div class="tv-box">
                        <select class="tv-kind" x-on:change="goKind($event)" aria-label="{{ __('accounts::note_form.kind') }}" data-note-kind>
                            @foreach ($kinds as $option)
                                <option value="{{ $option['url'] }}" @selected($option['key'] === $kind)>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <x-ui.party-search name="party_id" class="tv-grow-1" :options="$parties"
                                           :selected="old('party_id', $partyId)" :aria-label="__('accounts::note_form.party_label')" />
                    </div>
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
                    <label class="tv-label" for="tv-reason">{{ __('accounts::note.reason') }}</label>
                    <select id="tv-reason" name="reason">
                        @foreach ($reasons as $reason)
                            <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ __('accounts::note.reason_'.$reason) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="tv-f">
                    <label class="tv-label" for="tv-total">{{ __('accounts::trade_form.total') }}</label>
                    <input id="tv-total" type="text" class="num tv-n" readonly :value="sumGrossText">
                </div>

                <div class="tv-f tv-w2">
                    <label class="tv-label" for="tv-bill">{{ $sells ? __('accounts::note_form.bill_sale') : __('accounts::note_form.bill_purchase') }}</label>
                    <div class="tv-box">
                        <input id="tv-bill" type="text" name="against_no" maxlength="64" x-model="billNo"
                               x-on:keydown.enter.prevent="fetchBill()"
                               placeholder="{{ __('accounts::note_form.bill_placeholder') }}" data-against-no>
                        <button type="button" class="tv-mini" x-on:click="fetchBill()" :disabled="billBusy" data-fetch-bill>
                            <span x-show="! billBusy">{{ __('accounts::note_form.fetch_bill') }}</span>
                            <span x-show="billBusy" x-cloak>{{ __('accounts::note_form.fetching') }}</span>
                        </button>
                        <span class="tv-chip tv-chip-bill num" x-show="hasBill" x-cloak x-text="billInfo" data-bill-info></span>
                        <button type="button" class="tv-x" x-show="hasBill" x-cloak x-on:click="clearBill()"
                                title="{{ __('accounts::note_form.clear_bill') }}" aria-label="{{ __('accounts::note_form.clear_bill') }}">×</button>
                    </div>
                    <span class="tv-badge tv-badge-bad" x-show="hasBillError" x-cloak x-text="billError" data-bill-error></span>
                    @error('against_no')<span class="tv-badge tv-badge-bad">{{ $message }}</span>@enderror
                </div>

                @if ($type === 'dn')
                    <div class="tv-f">
                        <label class="tv-label" for="tv-sup-no">{{ __('accounts::note_form.supplier_note_no') }}</label>
                        <input id="tv-sup-no" type="text" name="supplier_note_no" maxlength="64" x-model="supNo" data-supplier-note-no>
                    </div>
                    <div class="tv-f">
                        <span class="tv-label">{{ __('accounts::note_form.supplier_note_date') }}</span>
                        <x-ui.date dense name="supplier_note_date" id="tv-sup-date" :value="old('supplier_note_date')"
                                   :max="$now->toDateString()" bind-model="supDate" />
                        @error('supplier_note_date')<span class="tv-badge tv-badge-bad">{{ $message }}</span>@enderror
                    </div>
                @else
                    <div class="tv-f">
                        <label class="tv-label" for="tv-vatbox">{{ __('accounts::note_form.vat') }}</label>
                        <div class="tv-lock">
                            <input id="tv-vatbox" type="text" readonly :value="vatBoxText" data-vat-box>
                            <x-ui.icon name="lock" :size="13" />
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @include('accounts::partials.trade-lines', [
            'types' => $types,
            'pickProducts' => $pickProducts,
            'isNote' => true,
            'returnLabel' => $returnLabel,
            'returnChip' => $returnLabel ? __('accounts::note_form.return_chip_'.$type) : null,
            'returnNote' => $returnLabel ? __('accounts::note_form.return_note_'.$type) : null,
            'bottomLabel' => $bottomLabel,
            'withTds' => false,
        ])
    </form>
</x-layouts.app>
