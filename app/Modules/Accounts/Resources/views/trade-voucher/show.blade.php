{{--
    ⭐ একটা বিক্রয় ভাউচার (SV) / ক্রয় ভাউচার (PUV) — স্টক ছাড়া, মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।

    ⓘ মাথা এক সারিতে: নম্বর · অবস্থা · … ছাপা · অনুমোদনে পাঠান · বাতিল; পাকা SV-তে "টাকা নিন" — রসিদ (RCV) খোলে, গ্রাহক আর
    অঙ্ক আগে থেকে ভরা (মালিকের এক-কাগজের নিয়ম)। ⛔ টাকার মাধ্যমের ঘর নেই; নোটিশের পটি নেই — বার্তা ছোট ব্যাজে।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Accounts\Support\TradeCatalog;

    $sale = $kind === 'sale';
    $title = $voucher->document_no;
    $params = ['kind' => $kind, 'voucher' => $voucher->id];
    $dr = array_reduce($postings, fn ($t, $r) => bcadd($t, (string) $r['debit'], 4), '0');
    $cr = array_reduce($postings, fn ($t, $r) => bcadd($t, (string) $r['credit'], 4), '0');
    $days = $voucher->trx_date && $voucher->due_on ? (int) $voucher->trx_date->diffInDays($voucher->due_on) : null;
    // ⛔ ক্রয় ভাউচারে পণ্যের সারির দর কেনা দর — চাবি না থাকলে ঢাকা; ভাউচারের মোট দেনার অঙ্ক, খোলা
    $showsPrice = $sale || TradeCatalog::seesCost();
    $figure = fn ($line, $value) => $showsPrice || $line->line_type !== 'product' ? Money::format($value) : \App\Core\Security\FieldSecurity::mask();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$title" class="tv-head tv-tone-{{ $tone }}" data-trade-head>
            <x-slot:actions>
                <span class="tv-chip tv-chip-doc">{{ __('accounts::trade.doc_'.$kind) }}@if ($sale) · {{ __('accounts::trade_form.chip_sale') }}@endif</span>
                @if ($voucher->isCancelled())
                    <span class="tv-chip tv-chip-bad">{{ __('core.status.cancelled') }}</span>
                @elseif ($voucher->isConfirmed())
                    <span class="tv-chip tv-chip-ok">{{ __('core.status.confirmed') }}</span>
                @else
                    <span class="tv-chip tv-chip-st">{{ __('core.status.draft') }}</span>
                @endif
                @if (session('saved'))
                    <span role="status" class="tv-badge tv-badge-ok" data-saved>{{ session('saved') }}</span>
                @endif
                @if ($binMissing && $sale)
                    <span class="tv-badge tv-badge-warn" data-bin-missing>{{ __('accounts::note.bin_missing') }}</span>
                @endif
                <span class="tv-grow"></span>

                <x-ui.button :href="$listUrl" tone="secondary">{{ __('accounts::trade_form.to_list') }}</x-ui.button>

                <x-ui.print-menu :documents="[[
                    'label' => $voucher->document_no,
                    'url' => route('accounts.trade_voucher.print', $params),
                    'paper_setting' => 'accounts.print.paper.voucher',
                    'type' => 'accounts_trade_voucher',
                    'id' => $voucher->id,
                    'no' => $voucher->document_no,
                ]]" />

                @can('accounts.voucher.create')
                    @unless ($voucher->isCancelled())
                        <form method="POST" action="{{ route('accounts.trade_voucher.cancel', $params) }}"
                              x-data="reasonPrompt({ question: @js(__('accounts::trade_form.cancel_prompt')), empty: @js(__('core.form.cancel_needs_reason')) })"
                              @submit="ask($event)">
                            @csrf
                            <input type="hidden" name="cancel_reason" x-ref="reason">
                            <x-ui.button type="submit" tone="secondary">{{ __('accounts::trade_form.cancel_paper') }}</x-ui.button>
                        </form>
                    @endunless

                    @if ($voucher->isDraft())
                        <form method="POST" action="{{ route('accounts.trade_voucher.confirm', $params) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary" class="tv-pri" data-send>{{ __('accounts::trade_form.confirm') }}</x-ui.button>
                        </form>
                    @endif
                @endcan

                {{-- ⭐ "টাকা নিন" — পাকা SV-র পরে রসিদ (RCV) --}}
                @if ($collectUrl)
                    <x-ui.button :href="$collectUrl" tone="primary" class="tv-pri" icon="banknote" data-collect>{{ __('accounts::trade_form.collect') }}</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    {{-- ⭐ উল্টো কাগজের সূত্র — মূল ভাউচার থাকে, চিহ্ন দেওয়া --}}
    @include('accounts::partials.reversal-reference', ['type' => \App\Modules\Accounts\Models\Reversal::TRADE_VOUCHER, 'id' => $voucher->id])

    <div class="tv-screen tv-tone-{{ $tone }}" data-trade-show="{{ $kind }}">
        <div class="tv-card">
            <dl class="tv-hdr tv-hdr-show">
                <div class="tv-f">
                    <dt class="tv-label">{{ $sale ? __('accounts::trade_form.party_customer') : __('accounts::trade_form.party_supplier') }}</dt>
                    <dd>
                        @if ($partyRoute)
                            <a href="{{ route($partyRoute[0], $partyRoute[1]) }}" class="tv-link">{{ $partyName }}</a>
                        @else
                            {{ $partyName }}
                        @endif
                    </dd>
                </div>
                <div class="tv-f">
                    <dt class="tv-label">{{ __('accounts::trade_form.date_time') }}</dt>
                    <dd class="num">{{ $voucher->trx_date?->format('d M Y') }}@if ($voucher->created_at) · {{ $voucher->created_at->format('H:i') }}@endif</dd>
                </div>
                <div class="tv-f">
                    <dt class="tv-label">{{ __('accounts::trade_form.due_date') }}</dt>
                    <dd class="num">{{ $voucher->due_on?->format('d M Y') ?? '—' }}@if ($days !== null) · {{ $days }} {{ __('accounts::trade_form.days') }}@endif</dd>
                </div>
                <div class="tv-f">
                    <dt class="tv-label">{{ $sale ? __('accounts::trade_form.ref_sale') : __('accounts::trade_form.ref_purchase') }}</dt>
                    <dd class="num">{{ $voucher->ref_no ?: '—' }}</dd>
                </div>
                @unless ($sale)
                    <div class="tv-f">
                        <dt class="tv-label">{{ __('accounts::trade_form.mushak_no') }}</dt>
                        <dd class="num">{{ $voucher->mushak_no ?: '—' }}@if ($voucher->mushak_date) · {{ $voucher->mushak_date->format('d M Y') }}@endif</dd>
                    </div>
                @endunless
                <div class="tv-f">
                    <dt class="tv-label">{{ __('accounts::trade_form.total') }}</dt>
                    <dd class="num tv-strong">{{ Money::format($voucher->total) }}</dd>
                </div>
            </dl>
        </div>

        <div class="tv-body">
            <div class="tv-main">
                <div class="tv-lines">
                    <div class="tv-scroll">
                        <table class="ui-lines">
                            <thead>
                                <tr>
                                    <th class="tv-c-no">{{ __('accounts::trade_form.col_no') }}</th>
                                    <th>{{ __('accounts::trade_form.col_type') }}</th>
                                    <th>{{ __('accounts::trade_form.col_item') }}</th>
                                    <th>{{ __('accounts::trade_form.col_desc') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_qty') }}</th>
                                    <th>{{ __('accounts::trade_form.col_unit') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_rate') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_amount') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_vat') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_vat_amount') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($voucher->lines as $line)
                                    <tr data-trade-line>
                                        <td class="num tv-c-no">{{ $line->line_no }}</td>
                                        <td>{{ __('accounts::trade_form.type_'.$line->line_type) }}</td>
                                        <td>
                                            {{ $line->line_type === 'account' ? ($line->account ? $line->account->code.' · '.$line->account->name() : '—') : $line->item_name }}
                                            @if ($line->line_type !== 'account' && $line->account)
                                                <span class="tv-src">{{ __('accounts::trade_form.account_of') }} {{ $line->account->code }} · {{ $line->account->name() }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $line->description ?: '—' }}</td>
                                        <td class="num tv-r">{{ TradeCatalog::rate((string) $line->qty) }}</td>
                                        <td>{{ $line->unit ?: '—' }}</td>
                                        <td class="num tv-r">{{ $figure($line, $line->rate) }}</td>
                                        <td class="num tv-r">{{ $figure($line, $line->amount) }}</td>
                                        <td class="num tv-r">{{ bccomp((string) $line->vat_rate, '0', 4) > 0 ? TradeCatalog::rate((string) $line->vat_rate).'%' : __('accounts::trade_form.vat_none') }}</td>
                                        <td class="num tv-r">{{ $figure($line, $line->vat_amount) }}</td>
                                        <td class="num tv-r tv-strong">{{ $figure($line, $line->total) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if (filled($voucher->narration))
                    <div class="tv-narr">
                        <span class="tv-label">{{ __('accounts::trade_form.narration') }}</span>
                        <p>{{ $voucher->narration }}</p>
                    </div>
                @endif

                @if ($voucher->isCancelled() && filled($voucher->cancel_reason))
                    <div class="tv-narr">
                        <span class="tv-label">{{ __('core.status.cancelled') }}</span>
                        <p>{{ $voucher->cancel_reason }}</p>
                    </div>
                @endif

                <details class="tv-post" @if ($voucher->isConfirmed()) open @endif data-posting>
                    <summary>
                        <span>{{ $voucher->isConfirmed() ? __('accounts::trade_form.posted_title') : __('accounts::trade_form.post_title') }} ▸</span>
                        <span class="tv-dr">{{ __('accounts::trade_form.debit') }} <span class="num">{{ Money::format($dr) }}</span></span>
                        <span class="tv-cr">{{ __('accounts::trade_form.credit') }} <span class="num">{{ Money::format($cr) }}</span></span>
                        @if (bccomp($dr, $cr, 4) === 0 && bccomp($dr, '0', 4) > 0)
                            <span class="tv-ok">{{ __('accounts::trade_form.balanced') }}</span>
                        @endif
                    </summary>
                    <table class="ui-lines">
                        <thead>
                            <tr>
                                <th>{{ __('accounts::trade_form.col_account') }}</th>
                                <th>{{ __('accounts::trade_form.col_party') }}</th>
                                <th class="tv-r">{{ __('accounts::trade_form.debit') }}</th>
                                <th class="tv-r">{{ __('accounts::trade_form.credit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($postings as $row)
                                <tr>
                                    <td class="num">{{ $row['code'] }} · {{ $row['name'] }}</td>
                                    <td>{{ $row['party'] ? $partyName : '—' }}</td>
                                    <td class="num tv-r tv-dr">{{ bccomp((string) $row['debit'], '0', 4) > 0 ? Money::format($row['debit']) : '' }}</td>
                                    <td class="num tv-r tv-cr">{{ bccomp((string) $row['credit'], '0', 4) > 0 ? Money::format($row['credit']) : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            </div>

            <aside class="tv-sum">
                <h4>{{ __('accounts::trade_form.summary') }}</h4>
                <span class="tv-k">{{ __('accounts::trade_form.sum_amount') }}</span>
                <span class="tv-v num">{{ Money::format($voucher->amount) }}</span>
                <span class="tv-k">{{ __('accounts::trade_form.sum_vat') }}</span>
                <span class="tv-v num">{{ Money::format($voucher->tax_amount) }}</span>
                <span class="tv-k">{{ __('accounts::trade_form.sum_gross') }}</span>
                <span class="tv-v num">{{ Money::format($voucher->total) }}</span>
                @unless ($sale)
                    <h4 class="tv-sum-gap">{{ __('accounts::trade_form.withheld') }}</h4>
                    <span class="tv-k">{{ __('accounts::trade_form.tds') }}</span>
                    <span class="tv-v num">{{ Money::format($voucher->tds_amount) }}</span>
                @endunless
                <span class="tv-k tv-tot">{{ $sale ? __('accounts::trade_form.bottom_sale') : __('accounts::trade_form.bottom_purchase') }}</span>
                <span class="tv-v num tv-tot tv-tone-ink">{{ Money::format($voucher->party_total) }}</span>
            </aside>
        </div>
    </div>
</x-layouts.app>
