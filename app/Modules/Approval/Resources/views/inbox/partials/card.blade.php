{{--
    ⭐ ইনবক্সের একটা কার্ড — অনুমোদন পর্দা ভাগ ২ (দফা ১, ৫, ৭, ৮; পরিকল্পনার পাতা ২)।

        পরিশোধ · PAY-0231                                   ৳1,20,000.00
        জাবেদ অ্যাগ্রো ফুড · SUP-0003 · প্রধান শাখা
        চেয়েছেন: আল-আমিন · শেষ সময় পেরিয়েছে 3 ঘণ্টা · ধাপ 2 / 2

    ⓘ পক্ষ "নাম · পয়েন্ট · শাখা" ([[PartyRegistry::labelsOf()]]); পক্ষ না থাকলে কী বাবদ। কাগজের শাখা পক্ষের লেখায় আগে থেকেই না থাকলে
    পাশে বসে (দফা ৫)। ⓘ শেষ সময় লেখায়, রং [[ApprovalSla]]-এর (দফা ৭)। ⓘ চেকবক্স কার্ডের লিংকের **বাইরে** — লিংকের ভেতরে
    থাকলে বাছাই করতে গিয়ে কাগজটাই খুলে যেত।
--}}
@php
    $fact = $facts[$approval->id] ?? [];
    $work = $labels[$approval->module.'.'.$approval->action]
        ?? app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $approval->module, (string) $approval->action);
    $party = $fact['party'] ?? null;
    $branchName = $fact['branch'] ?? null;
    $place = collect([$party ?? ($fact['about'] ?? null)])
        ->when($branchName !== null && ! str_contains((string) $party, (string) $branchName), fn ($c) => $c->push($branchName))
        ->filter()
        ->implode(' · ');
    $deadline = $deadlines[$approval->id] ?? null;
    $step = $steps[$approval->id] ?? null;
    $isOpen = $opened !== null && (int) $opened->id === (int) $approval->id;
    $toneInk = [
        'danger' => 'font-semibold text-(--color-badge-danger-ink)',
        'warning' => 'font-semibold text-(--color-badge-pending-ink)',
        'success' => 'text-(--color-badge-success-ink)',
    ];
@endphp

<li data-inbox-card="{{ $approval->id }}"
    @class([
        'flex gap-2 rounded-(--radius-card) border bg-(--color-surface-card) p-3',
        'border-(--color-brand-500) ring-1 ring-(--color-brand-500)' => $isOpen,
        'border-(--color-border) hover:bg-(--color-surface-hover)' => ! $isOpen,
    ])>
    @if (isset($canPick[$approval->id]))
        <label class="flex shrink-0 items-start pt-0.5">
            <input type="checkbox" name="ids[]" value="{{ $approval->id }}" form="bulk-form" class="size-4" data-inbox-pick>
            <span class="sr-only">{{ __('approval::field.pick') }} {{ $fact['no'] ?? '#'.$approval->id }}</span>
        </label>
    @endif

    <a href="{{ request()->fullUrlWithQuery(['open' => $approval->id]) }}" class="block min-w-0 flex-1"
       @if ($isOpen) aria-current="true" @endif>
        <span class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-semibold">{{ $work }}@if (! empty($fact['no'])) · <span class="num">{{ $fact['no'] }}</span>@endif</span>
            <span class="num shrink-0 text-sm font-semibold">{{ $approval->amount === null ? '—' : '৳'.\App\Core\Support\Money::format($approval->amount) }}</span>
        </span>

        @if ($place !== '')
            <span class="block truncate text-xs text-(--color-ink-muted)" data-card-place>{{ $place }}</span>
        @endif

        {{-- ⭐ কী বাবদ · টাকা কোথায় — পুরনো দুই নিয়ম থাকে (মালিক, ২০ সেপ্টেম্বর ২০২৬: "kothy theke eseche kothay joma holo"; সইদাতা কী
             বিক্রি হচ্ছে দেখেন — [[TheSignerSeesWhatIsBeingSoldTest]])। ⓘ পক্ষ না থাকলে "কী বাবদ" উপরের লাইনেই, তাই এখানে আর নয়। --}}
        @php $more = collect([$party !== null ? ($fact['about'] ?? null) : null, $fact['where'] ?? null])->filter()->implode(' · '); @endphp
        @if ($more !== '')
            <span class="block truncate text-xs text-(--color-ink-muted)" data-card-about>{{ $more }}</span>
        @endif

        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-(--color-ink-muted)">
            <span>{{ __('approval::field.asked_by') }}: {{ $approval->requesterName() }}</span>

            @if ($approval->status !== \App\Models\Approval::PENDING)
                <x-ui.badge :tone="match ($approval->status) {
                    \App\Models\Approval::APPROVED => 'success',
                    \App\Models\Approval::REJECTED => 'danger',
                    \App\Models\Approval::RETURNED => 'info',
                    default => 'draft',
                }">{{ __('approval::status.'.$approval->status) }}</x-ui.badge>
            @elseif ($deadline !== null)
                <span class="{{ $toneInk[$deadline['tone']] ?? '' }}" data-card-deadline>{{ $deadline['text'] }}</span>
            @endif

            @if ($step !== null)
                <span class="num rounded-full bg-(--color-surface-sunken) px-2" data-card-step>{{ __('approval::field.step_at', ['at' => $step['at'], 'of' => $step['of']]) }}</span>
            @endif

            @if (isset($standIns[$approval->id]))
                <span data-card-stand-in>{{ __('approval::message.on_behalf_of', ['name' => $standIns[$approval->id]]) }}</span>
            @endif
        </span>
    </a>
</li>
