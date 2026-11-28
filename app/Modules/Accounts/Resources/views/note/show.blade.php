<x-page-css name="trade-voucher" />{{-- এই পাতার নিজের CSS ([[PageCss]]) --}}
{{--
    ⭐ একটা নোট — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬: মাথা এক সারিতে (নম্বর · ধরন · অবস্থা · অনুমোদনকারী … ছাপা · পাঠান · বাতিল),
    মাথার কার্ড, সারির ছক, ডানে সারাংশ, "খাতায় যা উঠবে" আর মাল ফেরতের কাগজ (SR/PR)।

    ⓘ খসড়ায় খাতায় কিছুই যায়নি; "অনুমোদনে পাঠান" চাপলে সই লাগলে অনুরোধ যায়, না লাগলে খাতায় বসে ([[NoteService::confirm()]])।
    ⓘ বাতিল করলে দাখিলা ফিরিয়ে নেওয়া হয়, মুছে ফেলা হয় না — পাকা নোটে উল্টো কাগজ ([[AccountsReversalService]])।
    ⛔ নোটিশের পটি নেই — বার্তা আর সতর্কতা মাথার সারিতে ছোট ব্যাজ।
--}}
@php
    use App\Core\Support\Money;

    $type = $type ?? $note->type();
    $title = $note->document_no;
    $typeChip = trim(implode(' · ', array_filter([
        __('accounts::note.type_'.$type),
        in_array($type, ['cn', 'cdn'], true) ? __('accounts::note_form.chip_'.$type) : null,
    ])));
    $hasBillLines = $note->lines->contains(fn ($l) => $l->source_line_id !== null);
    $hasReturns = in_array($type, ['cn', 'dn'], true);
    $mainKind = ['cn' => 'customer', 'cdn' => 'customer', 'dn' => 'supplier'][$type] ?? null;
    $bottomLabel = ($note->party_kind ?? $note->party_type) === $mainKind
        ? __('accounts::note_form.bottom_'.$type) : __('accounts::note_form.bottom_other');
    $lineTypeLabel = fn (string $t) => __('accounts::trade_form.type_'.$t);
    $dr = array_reduce($postings, fn ($t, $r) => bcadd($t, (string) $r['debit'], 4), '0');
    $cr = array_reduce($postings, fn ($t, $r) => bcadd($t, (string) $r['credit'], 4), '0');
    // ⓘ মাল ফেরতের সারি নোটের মোটে নেই (ফেরতের কাগজ তোলে) — সারাংশে সব সারি, নকশার মতো
    $moved = $note->lines->filter(fn ($l) => (bool) $l->goods_moved);
    $movedAmount = $moved->reduce(fn ($t, $l) => bcadd($t, (string) $l->amount, 4), '0');
    // ⛔ সরবরাহকারীর নোটে পণ্যের সারির দর কেনা দর — চাবি না থাকলে ঢাকা; নোটের মোট দেনার অঙ্ক, খোলা
    $showsPrice = $type !== 'dn' || \App\Modules\Accounts\Support\TradeCatalog::seesCost();
    $figure = fn ($line, $value) => $showsPrice || $line->line_type !== 'product' ? Money::format($value) : \App\Core\Security\FieldSecurity::mask();
    $movedVat = $moved->reduce(fn ($t, $l) => bcadd($t, (string) $l->vat_amount, 4), '0');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$title" class="tv-head tv-tone-{{ $tone }}" data-note-head>
            <x-slot:actions>
                <span class="tv-chip tv-chip-doc">{{ $typeChip }}</span>
                @if ($note->isCancelled())
                    <span class="tv-chip tv-chip-bad">{{ __('core.status.cancelled') }}</span>
                @elseif ($note->isConfirmed())
                    <span class="tv-chip tv-chip-ok">{{ __('core.status.confirmed') }}</span>
                @else
                    <span class="tv-chip tv-chip-st">{{ __('core.status.draft') }}</span>
                @endif
                @if ($note->isDraft())
                    <span class="tv-chip tv-chip-ap" data-approver>
                        @if ($approver)
                            {{ __('accounts::trade_form.approver', ['who' => $approver['who']]) }}@if ($approver['levels'] > 1) · {{ __('accounts::trade_form.approver_levels', ['n' => $approver['levels']]) }}@endif
                        @else
                            {{ __('accounts::trade_form.approver_none') }}
                        @endif
                    </span>
                @endif
                @if (session('saved'))
                    <span role="status" class="tv-badge tv-badge-ok" data-saved>{{ session('saved') }}</span>
                @endif
                @if ($binMissing && $type !== 'dn')
                    <span class="tv-badge tv-badge-warn" data-bin-missing>{{ __('accounts::note.bin_missing') }}</span>
                @endif
                <span class="tv-grow"></span>

                <x-ui.button :href="$listUrl" tone="secondary">{{ __('accounts::trade_form.to_list') }}</x-ui.button>

                {{-- ⭐ ছাপা — মাপ, ফাইল আর কতবার ছাপা হলো ([[NotePrintController]]) --}}
                <x-ui.print-menu :documents="[[
                    'label' => $note->document_no,
                    'url' => route('accounts.note.print', $note),
                    'paper_setting' => 'accounts.print.paper.note',
                    'type' => 'accounts_note',
                    'id' => $note->id,
                    'no' => $note->document_no,
                ]]" />

                @can('accounts.note.manage')
                    @unless ($note->isCancelled())
                        {{-- ⚠️ কারণ বাধ্যতামূলক — ব্যাখ্যাহীন বাতিল পরে কেউ বুঝত না --}}
                        <form method="POST" action="{{ route('accounts.note.cancel', $note) }}"
                              x-data="reasonPrompt({ question: @js(__('accounts::note.cancel_reason_prompt')), empty: @js(__('core.form.cancel_needs_reason')) })"
                              @submit="ask($event)">
                            @csrf
                            <input type="hidden" name="cancel_reason" x-ref="reason">
                            <x-ui.button type="submit" tone="secondary">{{ __('accounts::trade_form.cancel_paper') }}</x-ui.button>
                        </form>
                    @endunless

                    @if ($note->isDraft())
                        <form method="POST" action="{{ route('accounts.note.confirm', $note) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary" class="tv-pri" data-send>{{ __('accounts::trade_form.confirm') }}</x-ui.button>
                        </form>
                    @endif
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    {{-- ⭐ উল্টো কাগজের সূত্র (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬) --}}
    @include('accounts::partials.reversal-reference', ['type' => 'note', 'id' => $note->id])

    <div class="tv-screen tv-tone-{{ $tone }}" data-note-show="{{ $type }}">
        <div class="tv-card">
            <dl class="tv-hdr tv-hdr-show">
                <div class="tv-f">
                    <dt class="tv-label">{{ __('accounts::note.kind_'.($note->party_kind ?? $note->party_type)) }}</dt>
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
                    <dd class="num">{{ \App\Core\Support\DateFormat::format($note->trx_date) }}@if ($note->created_at) · {{ $note->created_at->format('H:i') }}@endif</dd>
                </div>
                <div class="tv-f">
                    <dt class="tv-label">{{ __('accounts::note.reason') }}</dt>
                    <dd>{{ filled($note->reason) ? __('accounts::note.reason_'.$note->reason) : '—' }}</dd>
                </div>
                <div class="tv-f">
                    <dt class="tv-label">{{-- ⓘ বিলে না-বাঁধা লেখা কেবল রেফারেন্স (২৫ অক্টোবর ২০২৬) --}}{{ $note->against_id === null && filled($note->against_no) ? __('accounts::note.reference') : ($type === 'dn' ? __('accounts::note_form.bill_purchase') : __('accounts::note_form.bill_sale')) }}</dt>
                    <dd class="num">
                        {{ $note->against_no ?: '—' }}@if ($billIssuedAt) · {{ \Illuminate\Support\Carbon::instance($billIssuedAt)->format('d M Y · H:i') }}@endif
                    </dd>
                </div>
                @if ($type === 'dn')
                    <div class="tv-f">
                        <dt class="tv-label">{{ __('accounts::note_form.supplier_note') }}</dt>
                        <dd class="num">
                            @if (filled($note->supplier_note_no))
                                {{ $note->supplier_note_no }}@if ($note->supplier_note_date) · {{ \App\Core\Support\DateFormat::format($note->supplier_note_date) }}@endif
                            @else
                                <span class="tv-novat">{{ __('accounts::note_form.not_adjusted') }}</span>
                            @endif
                        </dd>
                    </div>
                @endif
                <div class="tv-f">
                    <dt class="tv-label">{{ __('accounts::trade_form.total') }}</dt>
                    <dd class="num tv-strong">{{ Money::format($note->grandTotal()) }}</dd>
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
                                    @if ($hasBillLines)
                                        <th class="tv-r">{{ __('accounts::trade_form.col_billed') }}</th>
                                    @endif
                                    <th class="tv-r">{{ __('accounts::trade_form.col_qty') }}</th>
                                    <th>{{ __('accounts::trade_form.col_unit') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_rate') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_amount') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_vat') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_vat_amount') }}</th>
                                    <th class="tv-r">{{ __('accounts::trade_form.col_total') }}</th>
                                    @if ($hasReturns)
                                        <th>{{ __('accounts::trade_form.col_goods') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($note->lines as $line)
                                    <tr data-note-line>
                                        <td class="num tv-c-no">{{ $line->line_no }}</td>
                                        <td>{{ $lineTypeLabel((string) $line->line_type) }}</td>
                                        <td>
                                            {{ $line->line_type === 'account' ? ($line->account ? $line->account->code.' · '.$line->account->name() : '—') : $line->item_name }}
                                            @if ($line->line_type !== 'account' && $line->account)
                                                <span class="tv-src">{{ __('accounts::trade_form.account_of') }} {{ $line->account->code }} · {{ $line->account->name() }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $line->description ?: '—' }}</td>
                                        @if ($hasBillLines)
                                            <td class="num tv-r">{{ $line->billed_qty !== null ? \App\Modules\Accounts\Support\TradeCatalog::rate((string) $line->billed_qty) : '—' }}</td>
                                        @endif
                                        <td class="num tv-r">{{ \App\Modules\Accounts\Support\TradeCatalog::rate((string) $line->qty) }}</td>
                                        <td>{{ $line->unit ?: '—' }}</td>
                                        <td class="num tv-r">{{ $figure($line, $line->rate) }}</td>
                                        <td class="num tv-r">{{ $figure($line, $line->amount) }}</td>
                                        <td class="num tv-r">
                                            @if (bccomp((string) $line->vat_rate, '0', 4) > 0)
                                                {{ \App\Modules\Accounts\Support\TradeCatalog::rate((string) $line->vat_rate) }}%
                                            @elseif ($line->line_type !== 'account' || $note->against_id === null)
                                                <span class="tv-novat">{{ __('accounts::note_form.not_adjusted') }}</span>
                                            @else
                                                0%
                                            @endif
                                        </td>
                                        <td class="num tv-r">{{ $figure($line, $line->vat_amount) }}</td>
                                        <td class="num tv-r tv-strong">{{ $figure($line, $line->total) }}</td>
                                        @if ($hasReturns)
                                            <td>
                                                @if ($line->goods_moved)
                                                    <span class="tv-retchip">{{ __('accounts::note_form.returned_'.$type) }}@if (filled($line->return_no)) · {{ $line->return_no }}@endif</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    {{-- ⓘ পুরনো এক-অঙ্কের নোট — সারি ছাড়া --}}
                                    <tr>
                                        <td class="num tv-c-no">1</td>
                                        <td>{{ __('accounts::trade_form.type_account') }}</td>
                                        <td>{{ $accounts['other']->code ?? '' }} · {{ $accounts['other']?->name() }}</td>
                                        <td>{{ $note->narration ?: '—' }}</td>
                                        @if ($hasBillLines)<td></td>@endif
                                        <td class="num tv-r">1</td>
                                        <td>—</td>
                                        <td class="num tv-r">{{ Money::format($note->amount) }}</td>
                                        <td class="num tv-r">{{ Money::format($note->amount) }}</td>
                                        <td class="num tv-r">—</td>
                                        <td class="num tv-r">{{ Money::format($note->tax_amount) }}</td>
                                        <td class="num tv-r tv-strong">{{ Money::format($note->total) }}</td>
                                        @if ($hasReturns)<td></td>@endif
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if (filled($note->narration))
                    <div class="tv-narr">
                        <span class="tv-label">{{ __('accounts::trade_form.narration') }}</span>
                        <p>{{ $note->narration }}</p>
                    </div>
                @endif

                @if ($note->isCancelled() && filled($note->cancel_reason))
                    <div class="tv-narr">
                        <span class="tv-label">{{ __('core.status.cancelled') }}</span>
                        <p>{{ $note->cancel_reason }}</p>
                    </div>
                @endif

                <details class="tv-post" @if ($note->isConfirmed()) open @endif data-posting>
                    <summary>
                        <span>{{ $note->isConfirmed() ? __('accounts::trade_form.posted_title') : __('accounts::trade_form.post_title') }} ▸</span>
                        <span class="tv-dr">{{ __('accounts::trade_form.debit') }} <span class="num">{{ Money::format($dr) }}</span></span>
                        <span class="tv-cr">{{ __('accounts::trade_form.credit') }} <span class="num">{{ Money::format($cr) }}</span></span>
                        @if (bccomp($dr, $cr, 4) === 0 && bccomp($dr, '0', 4) > 0)
                            <span class="tv-ok">{{ __('accounts::trade_form.balanced') }}</span>
                        @endif
                        @foreach ($returnLinks as $link)
                            @if ($link['url'])
                                <a href="{{ $link['url'] }}" class="tv-retchip" data-return-link>{{ __('accounts::note_form.returns') }}: {{ $link['no'] }}</a>
                            @else
                                <span class="tv-retchip" data-return-link>{{ __('accounts::note_form.returns') }}: {{ $link['no'] }}</span>
                            @endif
                        @endforeach
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
                    @if ($moved->isNotEmpty())
                        <p class="tv-sub">{{ __('accounts::note_form.return_note_'.$type) }}</p>
                    @endif
                </details>
            </div>

            <aside class="tv-sum">
                <h4>{{ __('accounts::trade_form.summary') }}</h4>
                <span class="tv-k">{{ __('accounts::trade_form.sum_amount') }}</span>
                <span class="tv-v num">{{ Money::format(bcadd((string) $note->amount, $movedAmount, 4)) }}</span>
                <span class="tv-k">{{ __('accounts::trade_form.sum_vat') }}</span>
                <span class="tv-v num">{{ Money::format(bcadd((string) $note->tax_amount, $movedVat, 4)) }}</span>
                <span class="tv-k">{{ __('accounts::trade_form.sum_gross') }}</span>
                <span class="tv-v num">{{ Money::format($note->grandTotal()) }}</span>
                <span class="tv-k tv-tot">{{ $bottomLabel }}</span>
                <span class="tv-v num tv-tot tv-tone-ink">{{ Money::format($note->grandTotal()) }}</span>
            </aside>
        </div>
    </div>
</x-layouts.app>
