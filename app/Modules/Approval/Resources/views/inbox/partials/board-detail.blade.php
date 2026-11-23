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
    $tiles = array_filter([
        [__('approval::field.party'), $link($partyName, $partyUrl), 'who'],
        [__('approval::field.board_point'), $link($facts['point'] ?? null, $partyUrl), 'who'],
        [__('approval::field.board_date'), e(\App\Core\Support\DateFormat::spoken($approval->requested_at)), 'when'],
        [__('approval::field.board_where'), e(($facts['where'] ?? null) ?: '—'), 'where'],
        [__('approval::field.board_paper'), $documentHidden
            ? e(__('approval::message.document_not_yours'))
            : ($document === null ? e(__('approval::message.document_gone')) : $link($paperNo ?? ('#'.$approval->approvable_id), $paperUrl)), 'what'],
        [__('approval::field.branch'), e(($facts['branch'] ?? null) ?: '—'), 'where'],
        [__('approval::field.asked_by'), e($approval->requesterName()), 'who'],
        [__('approval::field.deadline'), e($deadline['text'] ?? '—'), ($deadline['tone'] ?? null) === 'danger' ? 'late' : 'when'],
        [__('approval::field.step'), e($step['at'].' / '.$step['of']).($step['name'] ? ' · '.e($step['name']) : ''), 'what'],
    ]);
    $note = collect([$facts['about'] ?? null, $approval->requested_reason])->filter()->unique()->implode(' · ');
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
                    <span class="inbox-board-dflag">{{ __('approval::message.changed_since_asked_short') }}</span>
                @endif
                @unless ($pending)
                    @include('approval::inbox.partials.status', ['approval' => $approval])
                @endunless
            </p>

            @if (($prevUrl ?? null) || ($nextUrl ?? null))
                <span class="mt-1 flex items-center gap-1">
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
            @foreach ($tiles as [$label, $value, $hue])
                <div class="inbox-board-tile" data-tile="{{ $hue }}">
                    <dt>{{ $label }}</dt>
                    <dd>{!! $value !!}</dd>
                </div>
            @endforeach
            @if ($note !== '')
                <div class="inbox-board-tile is-wide" data-tile="note">
                    <dt>{{ __('approval::field.board_note') }}</dt>
                    <dd>{{ $note }}</dd>
                </div>
            @endif
        </dl>

        @if ($standInFor)
            <p class="inbox-board-note" data-approval-stand-in>{{ __('approval::message.on_behalf_of', ['name' => $standInFor]) }}</p>
        @endif

        @if ($changedSinceAsked)
            <p class="inbox-board-warn">{{ __('approval::message.changed_since_asked') }}</p>
        @endif

        @if (($approval->payload['supersedes'] ?? null) !== null)
            <p class="inbox-board-warn">
                {{ $was === null
                    ? __('approval::message.supersedes_plain')
                    : __('approval::message.supersedes', ['was' => \App\Core\Support\Money::format($was)]) }}
            </p>
        @endif

        {{-- ── the boxes ─────────────────────────────────────────────────── --}}
        <div class="inbox-board-boxes">
            @if ($bundle !== null)
                <section class="inbox-board-box">@include('approval::inbox.partials.bundle')</section>
            @elseif ($sheet !== null)
                <section class="inbox-board-box">@include('approval::inbox.partials.sheet')</section>
            @endif

            @if ($document instanceof \App\Core\Contracts\Drillable)
                @include('approval::inbox.partials.papers')
            @endif

            @if ($timeline !== [])
                <section class="inbox-board-box">
                    <h3 class="inbox-board-boxhead">{{ __('approval::field.journey') }}</h3>
                    @include('approval::inbox.partials.timeline', ['timeline' => $timeline, 'approval' => $approval])
                </section>
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
                @include('approval::inbox.partials.decide')
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
