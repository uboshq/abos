{{--
    ⭐ The inbox board's left side — papers grouped by kind (owner "ok", 23 Nov 2026, sample YRFmwfF7 v5).

        ◯ আদায় 5টা
          ✓ 1  RCV-0002  আলম এন্টারপ্রাইজ  শত্রুগঞ্জ বাজার  IBBL · ব্যাংক  5,000.00  2 দিন
          …
             আদায় — উপমোট                                             95,350.00

    ⓘ One rounded box per kind, in the kind's colour; rows alternate light and deeper inside the box; the open row is the
      deepest. ⓘ Every number, party, point and amount is a link (owner, 23 Nov 2026: "হাইপার লিঙ্কগুলো কোনোভাবেই মিস করা
      যাবে না"): the number and the amount open the paper itself, the party and the point open the party, the row opens
      the paper on the right.
    ⛔ A paper that moves money never gets a checkbox (owner's decision 5) — the group tick picks only the rows that have
      one, and [[BulkApproval]] checks again on the server.
    ⓘ Only in the three ABOS looks ([[ApprovalInboxController::BOARD_LOOKS]]); every other look keeps the cards.
--}}
@php
    $kindTone = [\App\Modules\Approval\Http\Controllers\ApprovalInboxController::class, 'kindTone'];

    $waited = static function ($at): string {
        if ($at === null) {
            return '—';
        }

        $days = (int) $at->diffInDays(now());
        $hours = (int) $at->diffInHours(now());

        return $days >= 1
            ? __('approval::field.board_waited_days', ['count' => $days])
            : ($hours >= 1 ? __('approval::field.board_waited_hours', ['count' => $hours]) : __('approval::field.board_waited_now'));
    };

    $groups = $rows->groupBy(fn ($a) => $a->module.'.'.$a->action);
    $serial = 0;
@endphp

<div data-inbox-board-list class="inbox-board-list">
    @if ($bulkable !== [])
        {{-- ⓘ the pick bar — how many and how much is picked, and the one "together" button (the existing bulk review) --}}
        <div class="inbox-board-pickbar" data-board-pickbar>
            {{-- ⓘ the count is on the button itself ([[approval-keys.js]]); here only how much — never the count twice --}}
            <span class="text-sm text-(--color-ink-muted)">{{ __('approval::field.board_picked') }}</span>
            <span class="num text-sm font-semibold" data-board-picked-sum>৳0.00</span>
            <x-ui.button type="submit" tone="primary" form="bulk-form" data-bulk-button class="ms-auto"
                         data-label="{{ __('approval::action.bulk_picked', ['count' => '__N__']) }}">
                {{ __('approval::action.bulk_picked', ['count' => 0]) }}
            </x-ui.button>
        </div>
    @endif

    <div class="inbox-board-scroll" data-board-scroll>
        <table class="ui-list inbox-board-table">
            <thead>
                <tr>
                    <th scope="col" class="inbox-board-tick"><span class="sr-only">{{ __('approval::field.pick') }}</span></th>
                    <th scope="col" class="inbox-board-serial">#</th>
                    <th scope="col">{{ __('approval::field.paper_no') }}</th>
                    <th scope="col">{{ __('approval::field.party') }}</th>
                    <th scope="col">{{ __('approval::field.board_point') }}</th>
                    <th scope="col">{{ __('approval::field.board_where') }}</th>
                    <th scope="col" class="text-end">{{ __('approval::field.board_amount') }}</th>
                    <th scope="col">{{ __('approval::field.board_waited') }}</th>
                </tr>
            </thead>

            @foreach ($groups as $kind => $items)
                @php
                    $first = $items->first();
                    [$tone, $deep] = $kindTone((string) $first->module, (string) $first->action);
                    $kindName = $labels[$kind]
                        ?? app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $first->module, (string) $first->action);
                    $pickable = $items->filter(fn ($a) => isset($canPick[$a->id]));
                    $sub = \App\Core\Support\Money::sumOf($items, fn ($a) => (string) ($a->amount ?? '0'));
                    $hasAmount = $items->contains(fn ($a) => $a->amount !== null);
                @endphp

                <tbody class="inbox-board-group" style="--k: {{ $tone }}; --kd: {{ $deep }}" data-board-group="{{ $kind }}">
                    <tr class="inbox-board-head">
                        <th scope="rowgroup" colspan="8">
                            <span class="inbox-board-headline">
                                @if ($pickable->isNotEmpty())
                                    <input type="checkbox" class="size-4" data-board-group-pick="{{ $kind }}"
                                           aria-label="{{ __('approval::field.board_pick_group', ['kind' => $kindName]) }}">
                                @endif
                                <span class="inbox-board-kind">{{ $kindName }}</span>
                                <span class="inbox-board-count num">{{ __('approval::field.board_count', ['count' => $items->count()]) }}</span>
                            </span>
                        </th>
                    </tr>

                    @foreach ($items as $approval)
                        @php
                            $serial++;
                            $fact = $facts[$approval->id] ?? [];
                            $isOpen = $opened !== null && (int) $opened->id === (int) $approval->id;
                            $openUrl = request()->fullUrlWithQuery(['open' => $approval->id]);
                            $partyName = $fact['party_name'] ?? null;
                            $partyShown = $partyName ?? ($fact['party'] ?? ($fact['about'] ?? null));
                            $deadline = $deadlines[$approval->id] ?? null;
                            // ⭐ nothing the cards showed is lost (owner): the step "ধাপ 1 / 2" beside the number, and a passed or
                            //   near deadline ("শেষ সময় পেরিয়েছে 3 ঘণ্টা") in place of the plain wait
                            $step = $steps[$approval->id] ?? null;
                            $dueShown = $approval->status === \App\Models\Approval::PENDING
                                && in_array($deadline['tone'] ?? null, ['danger', 'warning'], true);
                        @endphp
                        <tr @class(['inbox-board-row', 'is-even' => $loop->even, 'is-open' => $isOpen]) data-inbox-card="{{ $approval->id }}"
                            data-board-open="{{ $openUrl }}" @if ($isOpen) aria-current="true" @endif>
                            <td class="inbox-board-tick">
                                @if (isset($canPick[$approval->id]))
                                    <input type="checkbox" name="ids[]" value="{{ $approval->id }}" form="bulk-form" class="size-4"
                                           data-inbox-pick data-board-in="{{ $kind }}" data-amount="{{ (string) ($approval->amount ?? '0') }}"
                                           aria-label="{{ __('approval::field.pick') }} {{ $fact['no'] ?? '#'.$approval->id }}">
                                @endif
                            </td>
                            <td class="inbox-board-serial num"><a href="{{ $openUrl }}" class="inbox-board-open">{{ $serial }}</a></td>
                            <td class="num font-semibold">
                                @if (! empty($fact['no']) && ! empty($fact['url']))
                                    <a href="{{ $fact['url'] }}" class="inbox-board-link">{{ $fact['no'] }}</a>
                                @else
                                    {{ $fact['no'] ?? '—' }}
                                @endif
                                @if ($approval->payload['supersedes'] ?? null)
                                    <span class="inbox-board-changed">{{ __('approval::message.changed_short') }}</span>
                                @endif
                                @if ($step !== null)
                                    <span class="inbox-board-step" data-card-step>{{ __('approval::field.step_at', ['at' => $step['at'], 'of' => $step['of']]) }}</span>
                                @endif
                            </td>
                            <td class="inbox-board-cut">
                                @if ($partyShown !== null && ! empty($fact['party_url']))
                                    <a href="{{ $fact['party_url'] }}" class="inbox-board-link">{{ $partyShown }}</a>
                                @else
                                    {{ $partyShown ?? '—' }}
                                @endif
                            </td>
                            <td class="inbox-board-cut">
                                @if (! empty($fact['point']) && ! empty($fact['party_url']))
                                    <a href="{{ $fact['party_url'] }}" class="inbox-board-link">{{ $fact['point'] }}</a>
                                @else
                                    {{ $fact['point'] ?? '—' }}
                                @endif
                            </td>
                            <td class="inbox-board-cut" title="{{ collect([$fact['where'] ?? null, $fact['about'] ?? null])->filter()->implode(' · ') }}">
                                {{ collect([$fact['where'] ?? null, $partyName !== null ? ($fact['about'] ?? null) : null])->filter()->implode(' · ') ?: '—' }}
                            </td>
                            <td class="num text-end">
                                @if ($approval->amount === null)
                                    —
                                @elseif (! empty($fact['url']))
                                    <a href="{{ $fact['url'] }}" class="inbox-board-link">{{ \App\Core\Support\Money::format($approval->amount) }}</a>
                                @else
                                    {{ \App\Core\Support\Money::format($approval->amount) }}
                                @endif
                            </td>
                            <td @class(['num', 'inbox-board-late' => ($deadline['tone'] ?? null) === 'danger', 'inbox-board-near' => $dueShown && ($deadline['tone'] ?? null) === 'warning'])
                                title="{{ $dueShown ? $waited($approval->requested_at) : ($deadline['text'] ?? '') }}">
                                @if ($dueShown)
                                    <span data-card-deadline>{{ $deadline['text'] }}</span>
                                @else
                                    {{ $waited($approval->requested_at) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    <tr class="inbox-board-sub">
                        <td colspan="6">{{ __('approval::field.board_subtotal', ['kind' => $kindName]) }}</td>
                        <td class="num text-end">{{ $hasAmount ? \App\Core\Support\Money::format($sub) : '—' }}</td>
                        <td></td>
                    </tr>
                </tbody>
            @endforeach
        </table>
    </div>
</div>
