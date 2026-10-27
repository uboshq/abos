{{--
    ⭐ একটা কাগজ, অনুমোদনের জন্য — ইনবক্সের ডান দিক আর কাগজের নিজের পাতা, দুই জায়গাতেই এই একটা ফাইল (অনুমোদন পর্দা ভাগ ২, দফা ১;
    মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; SAP Fiori "My Inbox")।

    উপরে সারাংশ (কাজ · চেয়েছেন · শেষ সময় · ধাপ), তারপর কাগজের লাইন আর সংযুক্তি, পাশে যাত্রাপথ, নিচে মন্তব্য আর চারটা কাজ।

    ⓘ তথ্য আসে [[ApprovalInboxController::detailFor()]] থেকে। ডাকা পাতা দেয় `$nextId` (অনুমোদনের পরে কোনটা খুলবে) আর `$from`
    (ইনবক্সের ছাঁকনি, ফেরার পথে); কাগজের নিজের পাতায় দুইটাই খালি, তখন ফেরা হয় ইনবক্সের মাথায়।

    ⛔ কাগজ দেখার অধিকার না থাকলে (নিরীক্ষক, খবর পেয়ে আসা মানুষ) লাইন, সংযুক্তি আর পক্ষ আঁকা হয় না — `$document` তখন `null`,
    আর পর্দা বলে কেন ([[documentHidden]])।
--}}
@php
    $workName = $labels[$approval->module.'.'.$approval->action]
        ?? app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $approval->module, (string) $approval->action);
    $paperNo = $facts['no'] ?? null;
    $place = collect([$facts['party'] ?? null, $facts['branch'] ?? null])
        ->filter()
        ->unique()
        ->reject(fn ($part, $i) => $i > 0 && str_contains((string) ($facts['party'] ?? ''), (string) $part))
        ->implode(' · ');
    $pending = $approval->status === \App\Models\Approval::PENDING;
    $nextId ??= null;
    $from ??= '';
    $toneInk = [
        'danger' => 'text-(--color-badge-danger-ink)',
        'warning' => 'text-(--color-badge-pending-ink)',
        'success' => 'text-(--color-badge-success-ink)',
    ];
@endphp

<div data-approval-detail="{{ $approval->id }}" class="@container min-w-0 space-y-3">

    {{-- ── সারাংশ ─────────────────────────────────────────────────────── --}}
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
            <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold">
                <span>{{ $workName }}@if ($paperNo) · <span class="num">{{ $paperNo }}</span>@endif</span>

                @unless ($pending)
                    <x-ui.badge :tone="match ($approval->status) {
                        \App\Models\Approval::APPROVED => 'success',
                        \App\Models\Approval::REJECTED => 'danger',
                        \App\Models\Approval::CANCELLED => 'draft',
                        \App\Models\Approval::RETURNED => 'info',
                        default => 'pending',
                    }">{{ __('approval::status.'.$approval->status) }}</x-ui.badge>
                @endunless
            </h2>

            {{-- ⭐ আগের আর পরের — তালিকার ক্রমে (ইনবক্সে তালিকার পাশের সারি, কাগজের পাতায় অপেক্ষার ক্রম); ← → দিয়েও।
                 ⓘ কেবল তখনই, যখন সত্যিই আগের বা পরের আছে — নাহলে মৃত বোতাম। --}}
            @if (($prevUrl ?? null) || ($nextUrl ?? null))
                <span class="flex items-center gap-1">
                    @if ($prevUrl ?? null)
                        <a href="{{ $prevUrl }}" data-approval-prev
                           class="rounded-(--radius-field) border border-(--color-border) px-2 py-0.5 text-sm hover:bg-(--color-surface-hover)"
                           aria-label="{{ __('approval::action.previous') }}" title="{{ __('approval::action.previous') }}">&larr;</a>
                    @endif
                    @if ($nextUrl ?? null)
                        <a href="{{ $nextUrl }}" data-approval-next
                           class="rounded-(--radius-field) border border-(--color-border) px-2 py-0.5 text-sm hover:bg-(--color-surface-hover)"
                           aria-label="{{ __('approval::action.next') }}" title="{{ __('approval::action.next') }}">&rarr;</a>
                    @endif
                </span>
            @endif

            {{-- ⓘ পক্ষ · শাখা · টাকা, আর টাকা কথায় (দফা ৬) — যিনি অনুমোদন দেন তাঁর প্রথম তিন প্রশ্ন এক লাইনে --}}
            <p class="text-xs text-(--color-ink-muted)" data-approval-headline>
                @if ($place !== '')<span>{{ $place }}</span> · @endif
                <span class="num font-semibold text-(--color-ink)">{{ $approval->amount === null ? '—' : '৳'.\App\Core\Support\Money::format($approval->amount) }}</span>
                @if ($amountWords)
                    <span data-amount-words>({{ $amountWords }})</span>
                @endif
            </p>
        </div>

        <dl class="mt-3 grid gap-3 @xs:grid-cols-2 @lg:grid-cols-4">
            <div class="min-w-0">
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.work') }}</dt>
                <dd class="text-sm">{{ $workName }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.asked_by') }}</dt>
                <dd class="text-sm">{{ $approval->requesterName() }} · <span class="num">{{ \App\Core\Support\DateFormat::spoken($approval->requested_at) }}</span></dd>
            </div>
            <div class="min-w-0">
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.deadline') }}</dt>
                <dd class="text-sm {{ $deadline !== null ? $toneInk[$deadline['tone']] ?? '' : '' }}" data-approval-deadline>
                    {{ $deadline['text'] ?? '—' }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.step') }}</dt>
                <dd class="text-sm" data-approval-step>
                    <span class="num">{{ $step['at'] }} / {{ $step['of'] }}</span>@if ($step['name']) · {{ $step['name'] }}@endif
                </dd>
            </div>
        </dl>

        {{-- ⭐ কাগজটা কোথায় — লিংক, নাহলে কেন নেই। ⓘ "নেই" আর "আপনার জন্য নয়" এক কথা নয় (নিরীক্ষক ভাববেন কাগজ মুছে গেছে)। --}}
        <p class="mt-3 text-sm">
            <span class="text-(--color-ink-muted)">{{ __('approval::field.document') }}:</span>
            @if ($documentHidden)
                <span class="text-(--color-ink-muted)">{{ __('approval::message.document_not_yours') }}</span>
            @elseif ($document === null)
                <span class="text-(--color-ink-muted)">{{ __('approval::message.document_gone') }}</span>
            @elseif (method_exists($document, 'drillRoute'))
                <a href="{{ route(...$document->drillRoute()) }}"
                   class="text-(--color-link) underline-offset-2 hover:underline">
                    {{-- ⓘ নাম নিজেই নম্বর বললে আর জোড়া নয় ("JRN-0004 — জাবেদা ভাউচার — JRN-0004" দেখাত, ২৮ অক্টোবর ২০২৬-এর ছবি) --}}
                    {{ str_contains((string) $document->drillLabel(), (string) $document->drillDocumentNo())
                        ? $document->drillLabel()
                        : $document->drillDocumentNo().' — '.$document->drillLabel() }}
                </a>
            @else
                {{ class_basename($document).' #'.$approval->approvable_id }}
            @endif
        </p>

        {{-- ⓘ কী বাবদ আর টাকা কোথায় — তালিকার কার্ডের একই তথ্য ([[ApprovalFacts]]), এখানে পুরো লেখায় --}}
        @foreach (['about' => __('approval::field.what_for'), 'where' => __('approval::field.where_money')] as $key => $label)
            @if (filled($facts[$key] ?? null))
                <p class="mt-1 text-sm"><span class="text-(--color-ink-muted)">{{ $label }}:</span> {{ $facts[$key] }}</p>
            @endif
        @endforeach

        @if ($approval->requested_reason)
            <p class="mt-1 text-sm"><span class="text-(--color-ink-muted)">{{ __('approval::field.reason') }}:</span> {{ $approval->requested_reason }}</p>
        @endif

        @if ($standInFor)
            <p class="mt-2 text-xs text-(--color-ink-muted)" data-approval-stand-in>{{ __('approval::message.on_behalf_of', ['name' => $standInFor]) }}</p>
        @endif

        {{-- ⛔ অঙ্কটা অনুমোদন চাওয়ার দিনের, আজকের নয় — অপেক্ষার কাগজ খসড়াই থাকে, আর খসড়া বদলানো যায় ([[DocumentFingerprint]]) --}}
        @if ($changedSinceAsked)
            <p class="mt-2 rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-1 text-2xs text-(--color-badge-draft-ink)">
                {{ __('approval::message.changed_since_asked') }}
            </p>
        @endif

        {{-- ⭐ এই অনুরোধ আগের একটা অনুমোদনের বদলে — পুরনো অঙ্ক পাশে থাকলে পাঠক নিজেই দেখেন কী বদলেছে --}}
        @php $was = $approval->payload['was_amount'] ?? null; @endphp
        @if (($approval->payload['supersedes'] ?? null) !== null)
            <p class="mt-2 rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-1 text-2xs text-(--color-badge-draft-ink)">
                {{ $was === null
                    ? __('approval::message.supersedes_plain')
                    : __('approval::message.supersedes', ['was' => \App\Core\Support\Money::format($was)]) }}
            </p>
        @endif
    </section>

    {{-- ── কাগজ আর যাত্রাপথ ───────────────────────────────────────────── --}}
    {{-- ⚠️ পাশাপাশি কেবল যখন কাগজের দিকটা অন্তত 48rem (মূল অক্ষর 20px-এ 960px — 1920-এ হয়): 1366-এ মেনু খোলা রেখে পাশাপাশি বসালে
         লাইনের টেবিল পেত 391px আর ক্রেডিটের কলাম পাশে সরে যেত — ভাগ ১-এর দফা ৯ ("পুরো চওড়া টেবিল") আবার ভাঙত। তখন যাত্রাপথ নিচে। --}}
    <div class="grid gap-3 @3xl:grid-cols-[minmax(0,1fr)_14rem] @3xl:items-start">
        <div class="min-w-0 space-y-3">
            {{-- ⓘ কাউন্টারের আটকে থাকা বিক্রি হলে পুরো বিক্রি এক পাতায় ([[ApprovalBundles]]); নাহলে কাগজের নিজের লাইন ([[ShowsItselfForSigning]]) --}}
            @if ($bundle !== null)
                @include('approval::inbox.partials.bundle')
            @elseif ($sheet !== null)
                @include('approval::inbox.partials.sheet')
            @endif

            {{-- ⓘ স্লিপ ও অন্য সংযুক্তি — কাগজ দেখার অনুমতি থাকলেই (`$document` তখনই আসে) --}}
            @if ($document instanceof \App\Core\Contracts\Drillable)
                @include('approval::inbox.partials.papers')
            @endif
        </div>

        @if ($timeline !== [])
            <aside data-boxed class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-2 text-sm font-semibold">{{ __('approval::field.journey') }}</h2>

                @include('approval::inbox.partials.timeline', ['timeline' => $timeline, 'approval' => $approval])
            </aside>
        @endif
    </div>

    {{-- ── মন্তব্য আর চারটা কাজ (দফা ১১) ─────────────────────────────── --}}
    @if ($pending && $canDecide)
        @include('approval::inbox.partials.decide')
    @elseif ($pending)
        {{-- ⭐ কেন পারছেন না — নিজের অনুরোধ, অন্যের হাতে, সীমার বাইরে, নাকি পালা নয় ([[ApprovalInboxController::whyNot()]]) --}}
        <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm text-(--color-ink-muted)">
            {{ $whyNot ?? __('approval::message.not_your_turn') }}
        </p>
    @endif

    @if ($pending && $approval->requested_by === auth()->id())
        <form method="POST" action="{{ route('approval.inbox.withdraw', $approval->id) }}" data-confirm="{{ __('approval::message.confirm_withdraw') }}">
            @csrf
            <x-ui.button type="submit" tone="secondary">{{ __('approval::action.withdraw') }}</x-ui.button>
        </form>
    @endif
</div>
