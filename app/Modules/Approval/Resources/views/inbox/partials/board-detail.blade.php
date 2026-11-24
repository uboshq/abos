{{--
    ⭐ The inbox board's right side — one paper (owner "ok", 23 Nov 2026, sample YRFmwfF7 v5).

    ┌ bright head in the kind's colour: kind · number · "changed" ············ ৳ 5,000.00 (big) / in words ┐
    │ tiles: party · point · date · where · paper · branch · asked by · deadline · step · narration       │
    │ ▢ the paper's lines (খাতায় যা উঠবে / কাগজের সারি)                                                   │
    │ ▢ attachments · ▢ route · ▢ this party's earlier papers                                             │
    └ the decide buttons — flowing under the boxes, never fixed (owner: "অনুমোদনের এটা যাতে না ফিক্স থাকে") ┘

    ⓘ Same data as the card detail ([[ApprovalInboxController::detailFor()]]) — nothing dropped: reason, stand-in, the
      "changed since asked" and "re-asked" notes, attachments, route with every decision and remark, why-not, withdraw.
    ⓘ Every number, party and amount links to its source (owner, 23 Nov 2026).
--}}
@php
    $workName = $labels[$approval->module.'.'.$approval->action]
        ?? app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $approval->module, (string) $approval->action);
    [$tone, $deep] = \App\Modules\Approval\Http\Controllers\ApprovalInboxController::kindTone((string) $approval->module, (string) $approval->action);
    $paperNo = $facts['no'] ?? null;
    $paperUrl = $facts['url'] ?? null;
    if ($paperUrl === null && ! $documentHidden && $document !== null && method_exists($document, 'drillRoute')) {
        $paperUrl = route(...$document->drillRoute());
    }
    $partyName = $facts['party_name'] ?? ($facts['party'] ?? null);
    $partyUrl = $facts['party_url'] ?? null;
    $place = collect([$facts['party'] ?? null, $facts['branch'] ?? null])->filter()->unique()
        ->reject(fn ($part, $i) => $i > 0 && str_contains((string) ($facts['party'] ?? ''), (string) $part))
        ->implode(' · ');
    $pending = $approval->status === \App\Models\Approval::PENDING;
    $nextId ??= null;
    $from ??= '';
    $was = $approval->payload['was_amount'] ?? null;
    $link = static fn (?string $text, ?string $url) => $text === null || $text === ''
        ? '—'
        : ($url ? '<a href="'.e($url).'" class="inbox-board-link">'.e($text).'</a>' : e($text));
    // ⓘ the paper's own facts (way of paying, transaction no., prepared by …) stand in the tiles, never a second time over the lines
    $sheetFacts = collect($sheet['facts'] ?? []);
    $fact = static fn (string $label) => $sheetFacts->firstWhere('label', $label)['value'] ?? null;
    $txNo = $fact(__('accounts::field.instrument_no'));
    $preparedBy = $fact(__('core.print.prepared_by'));
    $narration = $fact(__('core.table.narration'));
    $wait = $approval->requested_at === null ? '—' : (($days = (int) $approval->requested_at->diffInDays(now())) >= 1
        ? __('approval::field.board_waited_days', ['count' => $days])
        : (($hours = (int) $approval->requested_at->diffInHours(now())) >= 1
            ? __('approval::field.board_waited_hours', ['count' => $hours]) : __('approval::field.board_waited_now')));
    $paperDate = $fact(__('accounts::field.date')) ?? \App\Core\Support\DateFormat::format($approval->requested_at);
    $whereShown = collect([$facts['where'] ?? null, $fact(__('accounts::field.instrument'))])->filter()->unique()->implode(' · ');
    // [label, html, hue 1–5, span] — the sample's order, four to a row
    $tiles = [
        [__('approval::field.party'), $link($partyName, $partyUrl), 1, 2],
        [__('approval::field.board_point'), $link($facts['point'] ?? null, $partyUrl), 2, 1],
        [__('approval::field.board_date'), e($paperDate), 3, 1],
        [__('approval::field.board_where'), e($whereShown ?: '—'), 4, 2],
        [__('approval::field.board_tx'), e($txNo ?: '—'), 5, 1],
        [__('approval::field.branch'), e(($facts['branch'] ?? null) ?: '—'), 2, 1],
        [__('approval::field.asked_by'), e($approval->requesterName()), 3, 1],
        [__('approval::field.board_waited'), e($wait).(($deadline['text'] ?? null) ? ' · '.e($deadline['text']) : ''), ($deadline['tone'] ?? null) === 'danger' ? 'late' : 5, 1],
        [__('approval::field.step'), e($step['at'].' / '.$step['of']).($step['name'] ? ' · '.e($step['name']) : ''), 1, 1],
        [__('core.print.prepared_by'), e($preparedBy ?: $approval->requesterName()), 4, 1],
    ];
    if ($documentHidden || $document === null) {
        $tiles[] = [__('approval::field.board_paper'), e(__($documentHidden ? 'approval::message.document_not_yours' : 'approval::message.document_gone')), 5, 4];
    }
    if ($standInFor) {
        $tiles[] = [__('approval::field.board_stand_in'), '<span data-approval-stand-in>'.e(__('approval::message.on_behalf_of', ['name' => $standInFor])).'</span>', 5, 4];
    }
    // ⓘ any fact of the paper no tile says yet keeps a tile of its own — nothing is lost, nothing is said twice
    $said = collect($tiles)->map(fn ($t) => strip_tags(html_entity_decode((string) $t[1])))->implode(' | ').' | '.($facts['about'] ?? '').' | '.$workName;
    $skip = [__('accounts::field.date'), __('approval::field.document'), __('approval::field.party'), __('core.company.branch'),
        __('accounts::field.instrument_no'), __('core.print.prepared_by'), __('core.table.narration')];
    foreach ($sheetFacts as $row) {
        if (! in_array($row['label'], $skip, true) && ! str_contains($said, (string) $row['value'])) {
            $tiles[] = [$row['label'], e($row['value']), 4, 1];
        }
    }
    $note = collect([$narration, $facts['about'] ?? null, $approval->requested_reason])->filter()->unique()->implode(' · ');
@endphp

<div data-approval-detail="{{ $approval->id }}" class="inbox-board-detail" style="--k: {{ $tone }}; --kd: {{ $deep }}">
    {{-- ── bright head ─────────────────────────────────────────────────── --}}
    <header class="inbox-board-dhead">
        <div class="min-w-0">
            <p class="inbox-board-dtitle">
                <span class="inbox-board-dkind">{{ $workName }}</span>
                @if ($paperNo)
                    @if ($paperUrl)
                        <a href="{{ $paperUrl }}" class="inbox-board-dno num">{{ $paperNo }}</a>
                    @else
                        <span class="inbox-board-dno num">{{ $paperNo }}</span>
                    @endif
                @endif
                @if ($changedSinceAsked)
                    <span class="inbox-board-dflag" title="{{ __('approval::message.changed_since_asked') }}">{{ __('approval::message.changed_since_asked_short') }}</span>
                @endif
                @if (($approval->payload['supersedes'] ?? null) !== null)
                    <span class="inbox-board-dflag">{{ $was === null
                        ? __('approval::message.supersedes_plain')
                        : __('approval::message.supersedes', ['was' => \App\Core\Support\Money::format($was)]) }}</span>
                @endif
                @unless ($pending)
                    @include('approval::inbox.partials.status', ['approval' => $approval])
                @endunless
            </p>

            @if (($prevUrl ?? null) || ($nextUrl ?? null))
                <span class="inbox-board-arrows">
                    @if ($prevUrl ?? null)
                        <a href="{{ $prevUrl }}" data-approval-prev aria-keyshortcuts="ArrowLeft" class="inbox-board-arrow"
                           aria-label="{{ __('approval::action.previous') }}" title="{{ __('approval::action.previous') }}">&larr;</a>
                    @endif
                    @if ($nextUrl ?? null)
                        <a href="{{ $nextUrl }}" data-approval-next aria-keyshortcuts="ArrowRight" class="inbox-board-arrow"
                           aria-label="{{ __('approval::action.next') }}" title="{{ __('approval::action.next') }}">&rarr;</a>
                    @endif
                </span>
            @endif
        </div>

        {{-- ⭐ the main amount — big, bright, linked to the paper; in words under it (owner: "5000 লেখাটাকে আর একটু বড়") --}}
        <p class="inbox-board-dmoney" data-approval-headline>
            @if ($approval->amount === null)
                <span class="inbox-board-damount">—</span>
            @elseif ($paperUrl)
                <a href="{{ $paperUrl }}" class="inbox-board-damount num">৳ {{ \App\Core\Support\Money::format($approval->amount) }}</a>
            @else
                <span class="inbox-board-damount num">৳ {{ \App\Core\Support\Money::format($approval->amount) }}</span>
            @endif
            @if ($amountWords)
                <span class="inbox-board-dwords" data-amount-words>{{ $amountWords }}</span>
            @endif
        </p>
    </header>

    <div class="inbox-board-dscroll" data-board-scroll>
        {{-- ── tiles ─────────────────────────────────────────────────────── --}}
        <dl class="inbox-board-tiles">
            @foreach ($tiles as [$label, $value, $hue, $span])
                <div @class(['inbox-board-tile', 'is-two' => $span === 2, 'is-wide' => $span === 4]) data-tile="{{ $hue }}">
                    <dt>{{ $label }}</dt>
                    <dd>{!! $value !!}</dd>
                </div>
            @endforeach
            @if ($note !== '')
                <div class="inbox-board-tile is-wide" data-tile="2">
                    <dt>{{ __('approval::field.board_note') }}</dt>
                    <dd>{{ $note }}</dd>
                </div>
            @endif
        </dl>

        @if ($timeline !== [])
            <div class="inbox-board-route">
                <b>{{ __('approval::field.journey') }}:</b>
                @include('approval::inbox.partials.timeline', ['timeline' => $timeline, 'approval' => $approval])
            </div>
        @endif

        {{-- ── the boxes ─────────────────────────────────────────────────── --}}
        <div class="inbox-board-boxes">
            @if ($bundle !== null)
                <section class="inbox-board-box">@include('approval::inbox.partials.bundle')</section>
            @elseif ($sheet !== null)
                <section class="inbox-board-box">@include('approval::inbox.partials.sheet', ['boardSheet' => true])</section>
            @endif

            @if ($document instanceof \App\Core\Contracts\Drillable)
                @include('approval::inbox.partials.papers')
            @endif

            @if (($partyPapers ?? []) !== [])
                <section class="inbox-board-box" data-board-party-papers>
                    <h3 class="inbox-board-boxhead">
                        @if ($partyUrl)
                            <a href="{{ $partyUrl }}" class="inbox-board-link">{{ __('approval::field.board_party_papers') }}</a>
                        @else
                            {{ __('approval::field.board_party_papers') }}
                        @endif
                    </h3>
                    <table class="ui-list inbox-board-mini">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('approval::field.board_paper') }}</th>
                                <th scope="col">{{ __('approval::field.board_date') }}</th>
                                <th scope="col" class="text-end">{{ __('approval::field.board_party_due') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($partyPapers as $paper)
                                <tr>
                                    <td class="num">{!! $link($paper['no'], $paper['url']) !!}</td>
                                    <td class="num">{{ $paper['date'] ? \App\Core\Support\DateFormat::format($paper['date']) : '—' }}</td>
                                    <td class="num text-end">{!! $link(\App\Core\Support\Money::format($paper['amount']), $paper['url']) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        </div>

        {{-- ── decide — in the flow, under the boxes (not fixed) ───────────────────── --}}
        <div class="inbox-board-actions">
            @if ($pending && $canDecide)
                @include('approval::inbox.partials.decide', ['boardActions' => true, 'boardSheetOpen' => $sheet !== null && $bundle === null])
            @elseif ($pending)
                <p class="inbox-board-note">{{ $whyNot ?? __('approval::message.not_your_turn') }}</p>
            @endif

            @if ($pending && $approval->requested_by === auth()->id())
                <form method="POST" action="{{ route('approval.inbox.withdraw', $approval->id) }}" data-confirm="{{ __('approval::message.confirm_withdraw') }}">
                    @csrf
                    <x-ui.button type="submit" tone="secondary">{{ __('approval::action.withdraw') }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>
</div>
