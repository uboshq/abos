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
    /*
     * ⭐ মালিকের নমুনা (২৬ নভেম্বর ২০২৬, "ঠিক আছে"): পক্ষ আর পয়েন্ট মাথায় ([[dhead]]); এখানে প্রথম সারিতে চারটা ছোট ঘর —
     *   শাখা · তারিখ · কোথায়/কী · লেনদেন নং; বাকি খবর (চেয়েছেন, অপেক্ষা, ধাপ, প্রস্তুতকারী …) এক লাইনে ছোট চিপে ([[$chips]])।
     * ⓘ [label, html, hue 1–5, span]
     */
    $tiles = [
        [__('approval::field.branch'), e(($facts['branch'] ?? null) ?: '—'), 2, 1],
        [__('approval::field.board_date'), e($paperDate), 3, 1],
        [__('approval::field.board_where'), e($whereShown ?: '—'), 4, 1],
        [__('approval::field.board_tx'), e($txNo ?: '—'), 5, 1],
    ];
    $chips = [
        [__('approval::field.asked_by'), e($approval->requesterName()), false],
        [__('core.print.prepared_by'), e($preparedBy ?: $approval->requesterName()), false],
        [__('approval::field.board_waited'), e($wait).(($deadline['text'] ?? null) ? ' · '.e($deadline['text']) : ''), ($deadline['tone'] ?? null) === 'danger'],
        [__('approval::field.step'), e($step['at'].' / '.$step['of']).($step['name'] ? ' · '.e($step['name']) : ''), false],
    ];
    if ($documentHidden || $document === null) {
        $tiles[] = [__('approval::field.board_paper'), e(__($documentHidden ? 'approval::message.document_not_yours' : 'approval::message.document_gone')), 5, 4];
    }
    if ($standInFor) {
        $tiles[] = [__('approval::field.board_stand_in'), '<span data-approval-stand-in>'.e(__('approval::message.on_behalf_of', ['name' => $standInFor])).'</span>', 5, 4];
    }
    // ⓘ any fact of the paper no tile says yet keeps a chip of its own — nothing is lost, nothing is said twice
    $said = collect($tiles)->merge($chips)->map(fn ($t) => strip_tags(html_entity_decode((string) $t[1])))->implode(' | ')
        .' | '.($facts['about'] ?? '').' | '.$workName.' | '.($partyName ?? '').' | '.($facts['point'] ?? '');
    $skip = [__('accounts::field.date'), __('approval::field.document'), __('approval::field.party'), __('core.company.branch'),
        __('accounts::field.instrument_no'), __('core.print.prepared_by'), __('core.table.narration')];
    foreach ($sheetFacts as $row) {
        if (! in_array($row['label'], $skip, true) && ! str_contains($said, (string) $row['value'])
            // ⓘ "করিম ট্রেডার্স · Head Office" — পক্ষ মাথাতেই আছে
            && ! (filled($partyName) && str_contains((string) $row['value'], (string) $partyName))
            // ⓘ "1106 — QA ব্যাংক" is the same account the where-tile already names
            && ! str_contains($said, trim((string) preg_replace('/^[\d.\-]+\s*—\s*/u', '', (string) $row['value'])))) {
            $chips[] = [$row['label'], e($row['value']), false];
        }
    }
    $note = collect([$narration, $facts['about'] ?? null, $approval->requested_reason])->filter()->unique()->implode(' · ');
    // ⓘ নিশ্চিতকরণের ঘরগুলো যা উপরে আগেই বলা (পক্ষ, তারিখ, গুদাম …) তা আবার বলে না — [[sheet-body]] এটা পড়ে
    $boardSaid = collect($tiles)->merge($chips)->map(fn ($t) => strip_tags(html_entity_decode((string) $t[1])))->implode(' | ')
        .' | '.$said.' | '.$paperDate.' | '.$note;
    $notice = $approval->payload['supersedes'] ?? null
        ? ($was === null ? __('approval::message.supersedes_plain') : __('approval::message.supersedes', ['was' => '৳'.\App\Core\Support\Money::format($was)]))
        : ($changedSinceAsked ? __('approval::message.changed_since_asked') : null);
@endphp

<div data-approval-detail="{{ $approval->id }}" class="inbox-board-detail" style="--k: {{ $tone }}; --kd: {{ $deep }}">
    {{-- ⭐ "অনুমোদন চাওয়ার পরে বদলেছে" — সবার উপরে, পুরো চওড়ায়, হালকা রঙে গাঢ় লেখা (মালিক, ২৬ নভেম্বর ২০২৬) --}}
    @if ($notice !== null)
        <p class="inbox-board-notice" data-board-notice role="note">⚠ <b>{{ __('approval::message.changed_since_asked_short') }} —</b> {{ $notice }}</p>
    @endif
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
                @unless ($pending)
                    @include('approval::inbox.partials.status', ['approval' => $approval])
                @endunless
            </p>
            {{-- ⭐ নম্বরের নিচে পক্ষ আর পয়েন্ট (নমুনা) --}}
            <p class="inbox-board-who">
                <span>{{ __('approval::field.party') }}</span> {!! $link($partyName, $partyUrl) !!}
                <span>· {{ __('approval::field.board_point') }}</span> <b>{!! $link($facts['point'] ?? null, $partyUrl) !!}</b>
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
        </dl>

        {{-- ⓘ বাকি খবর — এক লাইনে ছোট চিপে --}}
        <p class="inbox-board-chips">
            @foreach ($chips as [$label, $value, $late])
                <span @class(['is-late' => $late])><i>{{ $label }}</i> {!! $value !!}</span>
            @endforeach
        </p>

        @if ($note !== '')
            <dl class="inbox-board-tiles !pt-0">
                <div class="inbox-board-tile is-wide" data-tile="2">
                    <dt>{{ __('approval::field.board_note') }}</dt>
                    <dd>{{ $note }}</dd>
                </div>
            </dl>
        @endif

        @if ($timeline !== [])
            <div class="inbox-board-route">
                <b>{{ __('approval::field.journey') }}:</b>
                @include('approval::inbox.partials.timeline', ['timeline' => $timeline, 'approval' => $approval])
            </div>
        @endif

        {{-- ── the boxes ─────────────────────────────────────────────────── --}}
        <div class="inbox-board-boxes">
            @if ($bundle !== null)
                <section class="inbox-board-box">@include('approval::inbox.partials.bundle', ['inBoard' => true, 'boardSaid' => $boardSaid, 'boardParty' => (string) $partyName])</section>
            @elseif ($sheet !== null)
                <section class="inbox-board-box">@include('approval::inbox.partials.sheet', ['boardSheet' => true, 'inBoard' => true, 'boardSaid' => $boardSaid, 'boardParty' => (string) $partyName])</section>
            @endif

            @if ($document instanceof \App\Core\Contracts\Drillable)
                @include('approval::inbox.partials.papers')
            @endif

            @if (($partyPapers ?? []) !== [])
                {{-- ⭐ বন্ধ থাকে — কয়টা কাগজ আর কোনগুলো এক লাইনে; চাপলে খোলে (মালিক, ২৬ নভেম্বর ২০২৬) --}}
                <details class="inbox-board-box inbox-board-fold" data-board-party-papers>
                    <summary class="inbox-board-boxhead">
                        <span>▸ {{ __('approval::field.board_party_papers') }} ({{ count($partyPapers) }}) — {{ __('approval::field.board_open_to_see') }}</span>
                        <span class="num">{{ collect($partyPapers)->pluck('no')->filter()->take(4)->implode(' · ') }}</span>
                    </summary>
                    @if ($partyUrl)
                        <a href="{{ $partyUrl }}" class="inbox-board-link text-xs">{{ __('approval::field.board_party_papers') }} →</a>
                    @endif
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
                </details>
            @endif
        </div>

        @if (($printView ?? null) !== null)
            {{-- ⭐ "দেখুন" opens the paper exactly as it prints (owner, 24 Nov 2026) — the PDF of the print door, drawn only when opened --}}
            <div x-data="{ open: false }" x-on:open-paper.window="open = true" data-paper-view="{{ $printView }}">
                <div x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="paper-view-{{ $approval->id }}" data-dialog-focus
                     x-on:keydown.escape.window="open = false"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 text-start">
                    <div x-on:click.outside="open = false" class="inbox-board-paper">
                        <div class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2">
                            <p id="paper-view-{{ $approval->id }}" class="num min-w-0 flex-1 truncate font-bold">{{ $workName }} · {{ $paperNo ?? '#'.$approval->id }}</p>
                            <button type="button" x-on:click="open = false"
                                    class="rounded-(--radius-field) border border-(--color-border) px-3 py-1 text-xs">{{ __('approval::action.close') }}</button>
                        </div>
                        <template x-if="open">
                            <iframe src="{{ $printView }}#toolbar=0&amp;view=FitH" title="{{ $paperNo ?? $workName }}" class="inbox-board-paperframe"></iframe>
                        </template>
                    </div>
                </div>
            </div>
        @endif

    </div>

        {{-- ── decide — ডান পাশের নিচে, সবসময় চোখে (মালিক, ২৬ নভেম্বর ২০২৬); ⓘ আগে বাক্সগুলোর নিচে প্রবাহে ছিল ── --}}
        <div class="inbox-board-actions">
            @if ($pending && $canDecide)
                @include('approval::inbox.partials.decide', ['boardActions' => true, 'boardSheetOpen' => $sheet !== null && $bundle === null])
            @elseif ($pending)
                @if (($printView ?? null) !== null)
                    <x-ui.button type="button" tone="secondary" x-data x-on:click="$dispatch('open-paper')" data-opens-dialog aria-haspopup="dialog" class="inbox-board-view justify-self-start">{{ __('approval::action.board_view') }}</x-ui.button>
                @endif
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
