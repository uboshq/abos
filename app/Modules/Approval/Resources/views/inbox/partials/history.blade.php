{{--
    ⭐ "আমি অনুমোদন দিয়েছি" আর "আমার পাঠানো" — পুরো চওড়া তালিকা (মালিক, ৭ নভেম্বর ২০২৬, নমুনা ২ "ok"; SAP-এর Approval History)।

    ⓘ এখানে কাজ হয় না, হিসাব হয় — তাই বাঁয়ে-ডানে নয়, অন্য সব তালিকার মতো একটা টেবিল, একই টুলবার (+ ছাঁকনি · খুঁজুন · সাজাও ·
    ঘনত্ব · কলাম · রপ্তানি · শেয়ার · প্রিন্ট · নতুন করে আনো), নিচে মোট। নম্বরে চাপলে কাগজটা খোলে।
    ⓘ সারি, মোট আর পাতা ভাগ কন্ট্রোলারের একই প্রশ্ন থেকে ([[ApprovalInboxController::index()]]) — ইনবক্সের মাথার সংখ্যার সাথে মেলে।
--}}
@php
    $done = $tab === 'done';
    $historySorts = [
        'new' => __('approval::field.sort_new'),
        'late' => __('approval::field.sort_late'),
        'amount' => __('approval::field.sort_amount'),
    ];
    $columns = [
        ['key' => 'when', 'label' => $done ? __('approval::field.history_decided_at') : __('approval::field.history_sent_at'), 'width' => '10rem',
         'render' => function ($a) use ($done, $myDecisions) {
             $at = $done ? ($myDecisions[$a->id]->decided_at ?? null) : $a->requested_at;

             return $at !== null ? \App\Core\Support\DateFormat::format($at).' · '.\Illuminate\Support\Carbon::parse($at)->format('H:i') : '—';
         }],
        ['key' => 'number', 'label' => __('approval::field.history_number'), 'width' => '9rem',
         'render' => fn ($a) => new \Illuminate\Support\HtmlString('<a href="'.e(route('approval.inbox.show', $a->id)).'" class="num font-semibold text-(--color-link) hover:underline" data-history-open>'
             .e(($facts[$a->id]['no'] ?? null) ?: '#'.$a->id).'</a>')],
        ['key' => 'work', 'label' => __('approval::field.work'), 'width' => '10rem',
         'render' => fn ($a) => $labels[$a->module.'.'.$a->action]
             ?? app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $a->module, (string) $a->action)],
        ['key' => 'party', 'label' => __('approval::field.party'),
         'render' => fn ($a) => collect([$facts[$a->id]['party'] ?? ($facts[$a->id]['about'] ?? null), $facts[$a->id]['branch'] ?? null])->filter()->implode(' · ') ?: '—'],
        ['key' => 'amount', 'total' => 'money', 'label' => __('approval::field.history_amount'), 'numeric' => true, 'width' => '9rem',
         'raw' => fn ($a) => (string) ($a->amount ?? '0'),
         'render' => fn ($a) => $a->amount !== null ? \App\Core\Support\Money::format((string) $a->amount) : '—'],
    ];

    if ($done) {
        $columns[] = ['key' => 'decision', 'label' => __('approval::field.history_my_decision'), 'width' => '8rem',
            'render' => function ($a) use ($myDecisions) {
                $d = $myDecisions[$a->id] ?? null;
                if ($d === null) {
                    return '—';
                }
                $tone = match ($d->decision) {
                    \App\Models\ApprovalDecision::APPROVED => 'success',
                    \App\Models\ApprovalDecision::REJECTED => 'danger',
                    default => 'info',
                };

                return new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(
                    '<span data-history-decision="{{ $d }}"><x-ui.badge :tone="$tone">{{ $word }}</x-ui.badge></span>',
                    ['d' => $d->decision, 'tone' => $tone, 'word' => __('approval::status.'.$d->decision)],
                ));
            }];
        $columns[] = ['key' => 'remarks', 'label' => __('approval::field.remarks'),
            'render' => fn ($a) => ($myDecisions[$a->id]->remarks ?? null) ?: '—'];
    }

    $columns[] = ['key' => 'where', 'label' => __('approval::field.history_where_now'), 'width' => '9rem',
        'render' => fn ($a) => view('approval::inbox.partials.status', ['approval' => $a])];

    if ($done) {
        $columns[] = ['key' => 'requester', 'label' => __('approval::field.history_requested_by'), 'width' => '9rem',
            'render' => fn ($a) => $a->requester?->name ?? '—'];
    } else {
        $columns[] = ['key' => 'step', 'label' => __('approval::field.step'), 'width' => '8rem',
            // ⓘ ধাপ একটা জোড়া (কততম, কয়টার) — কার্ডের একই লেখা; ⛔ জোড়াটা সোজা ছাপলে "Array to string" — পাতা ৫০০ (Mac, ৭ নভেম্বর)
            'render' => fn ($a) => isset($steps[$a->id])
                ? __('approval::field.step_at', ['at' => $steps[$a->id]['at'], 'of' => $steps[$a->id]['of']])
                : '—'];
    }

    $grand = ['amount' => $sumAll];
@endphp

<div data-boxed data-inbox-history="{{ $tab }}"
     class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
    <form method="GET" action="{{ route('approval.inbox.index') }}" class="contents">
        <input type="hidden" name="tab" value="{{ $tab }}">

        <x-ui.toolbar :title="__('approval::field.tab_'.$tab)" :quiet="['tab']"
            :count="__('approval::message.tab_summary', ['count' => $visibleTotal, 'total' => '৳'.\App\Core\Support\Money::format($sumAll)])"
            :sort="$historySorts"
            :columns="$columns">
            {{-- ⓘ ছাঁকনি — ইনবক্সের একই ঘর (কাজ, শাখা, অঙ্কের ধাপ), একই নামে, তাই ঠিকানা ট্যাব বদলালেও কাজ করে --}}
            <select name="module" aria-label="{{ __('approval::field.work') }}" data-action="submit-form"
                    class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                <option value="">{{ __('approval::field.all_work') }}</option>
                @foreach ($modules as $code => $entry)
                    <option value="{{ $code }}" @selected($selected === $code)>{{ $entry['label'] }} ({{ $entry['count'] }})</option>
                @endforeach
            </select>
            @if (count($branches) > 1)
                <select name="branch" aria-label="{{ __('approval::field.branch') }}" data-action="submit-form"
                        class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('approval::field.all_branches') }}</option>
                    @foreach ($branches as $id => $name)
                        <option value="{{ $id }}" @selected($branch === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </x-ui.toolbar>
    </form>

    <x-ui.table
        :compact="request()->boolean('compact')"
        :empty="__('approval::field.history_empty')"
        :rows="$approvals"
        :grand="$grand"
        :columns="$columns" />

    <x-ui.pager :rows="$approvals" />
    <x-ui.list-totals :rows="$approvals" :grand="$grand" :columns="$columns" />
</div>
