{{--
    একটা অনুরোধ — আর সিদ্ধান্তের দুইটা বোতাম।

    উপরে যা যা দেখে সিদ্ধান্ত নেওয়া হয়: কী চাওয়া হয়েছে, কত টাকার,
    কোন কাগজে, কে চেয়েছে, আর কেন। ডকুমেন্টটা খোলার লিংকও আছে — অঙ্ক
    দেখে সন্দেহ হলে কাগজটা না দেখে কেউ যেন "হ্যাঁ" না বলেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$labels[$approval->module.'.'.$approval->action] ?? $approval->module.' · '.$approval->action"
            :subtitle="$approval->requested_at?->format('d M Y, H:i')">
            <x-slot:actions>
                <x-ui.badge :tone="match ($approval->status) {
                    \App\Models\Approval::APPROVED => 'success',
                    \App\Models\Approval::REJECTED => 'danger',
                    \App\Models\Approval::CANCELLED => 'draft',
                    default => 'pending',
                }">
                    {{ __('approval::status.'.$approval->status) }}
                </x-ui.badge>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.requested_by') }}
                        </dt>
                        <dd class="text-sm">{{ $approval->requester?->name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.amount') }}
                        </dt>
                        <dd class="tabular text-sm">
                            {{ $approval->amount === null ? '—' : \App\Core\Support\Money::format($approval->amount) }}
                        </dd>

                        {{-- ⛔ অঙ্কটা সই চাওয়ার দিনের, আজকের নয়।

                             ⚠️ অপেক্ষায় থাকা কাগজ খসড়াই থাকে, আর খসড়া বদলানো
                             যায়। ⓘ কারণসহ [[ApprovalInboxController::show()]]-এ।

                             ⭐ লাইনটা অঙ্কের **নিচে**, পাতার মাথায় নয় — যিনি
                             সংখ্যাটা পড়ছেন তাঁর চোখ তখন ঠিক ওখানেই। --}}
                        @if ($changedSinceAsked)
                            <p class="mt-1 rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-1
                                      text-2xs text-(--color-badge-draft-ink)">
                                {{ __('approval::message.changed_since_asked') }}
                            </p>
                        @endif

                        {{-- ⭐ এই অনুরোধটা আগের একটা সইয়ের বদলে।

                             ⚠️ ── কেন লাইনটা লাগে ────────────────────────────
                             অঙ্ক বদলে গেলে পুরনো সই আর কাগজটা ঢাকে না
                             ([[Approval::covers()]]), তাই নতুন একটা অনুরোধ বসে —
                             আর সইকারীর ইনবক্সে **একই কাগজ দ্বিতীয়বার** আসে।

                             ⛔ কারণ না জানলে সেটা ভুলের মতো দেখায়, আর মানুষ
                             ভাবেন ব্যবস্থাটা অকারণে দুইবার চাইছে। ⓘ পুরনো
                             অঙ্কটা পাশে থাকলে তিনি নিজেই দেখতে পান কী বদলেছে। --}}
                        @php $was = $approval->payload['was_amount'] ?? null; @endphp

                        @if (($approval->payload['supersedes'] ?? null) !== null)
                            <p class="mt-1 rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-1
                                      text-2xs text-(--color-badge-draft-ink)">
                                {{ $was === null
                                    ? __('approval::message.supersedes_plain')
                                    : __('approval::message.supersedes', [
                                        'was' => \App\Core\Support\Money::format($was),
                                    ]) }}
                            </p>
                        @endif
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.document') }}
                        </dt>
                        <dd class="text-sm">
                            {{--
                                কাগজটা আছে, কিন্তু এই পাঠকের জন্য নয়।

                                ⚠️ ── কেন লাইনটা লাগে ────────────────────────────
                                ঘরটা খালি রাখলে নিরীক্ষক ভাববেন কাগজটা মুছে গেছে
                                বা পাতাটা ভাঙা — আর দুইটাই মিথ্যা। কারণটা লেখা
                                থাকলে তিনি জানেন জিনিসটা আছে, শুধু তাঁর চাবি নেই।

                                ⓘ "নেই" আর "আপনি দেখতে পারবেন না" এক কথা নয়, আর
                                পর্দার দুইটাকে এক দেখানো চলে না।
                            --}}
                            @if ($documentHidden)
                                <span class="text-(--color-ink-muted)">{{ __('approval::message.document_not_yours') }}</span>
                            @elseif ($document === null)
                                <span class="text-(--color-ink-muted)">{{ __('approval::message.document_gone') }}</span>
                            @elseif (method_exists($document, 'drillRoute'))
                                <a href="{{ route(...$document->drillRoute()) }}"
                                   class="text-(--color-brand-600) underline-offset-2 hover:underline">
                                    {{ $document->drillDocumentNo() }} — {{ $document->drillLabel() }}
                                </a>
                            @else
                                {{ class_basename($document).' #'.$approval->approvable_id }}
                            @endif
                        </dd>
                    </div>

                    @if ($approval->requested_reason)
                        <div class="sm:col-span-2">
                            <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                                {{ __('approval::field.reason') }}
                            </dt>
                            <dd class="text-sm">{{ $approval->requested_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- সিদ্ধান্তের ইতিহাস — কোন স্তরে কে কী বলেছেন।

                 শুধু চূড়ান্ত অবস্থা রাখলে "তিন নম্বর স্তরে আটকে ছিল কেন"
                 প্রশ্নের উত্তর কখনো পাওয়া যেত না। --}}
            @if ($approval->decisions->isNotEmpty())
                <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-3 py-2 text-sm font-semibold">
                        {{ __('approval::field.decisions') }}
                    </h2>

                    <x-ui.table
                        :empty="__('approval::field.decisions')"
                        :rows="$approval->decisions"
                        :columns="[
                            /* ⓘ নাম থাকলে '২ · সুপারভাইজার', না থাকলে আগের মতোই '২' */
                            ['key' => 'level', 'label' => __('approval::field.level'), 'width' => '9rem',
                             'render' => fn ($d) => isset($stepNames[$d->level])
                                 ? $d->level.' · '.$stepNames[$d->level]
                                 : $d->level],
                            ['key' => 'user', 'label' => __('approval::field.approver'),
                             'render' => fn ($d) => $d->user?->name],
                            /* ⭐ ফরওয়ার্ড হলে কার কাছে গেল সেটাও এখানেই — ২২ সেপ্টেম্বর ২০২৬।

                               ⚠️ নামটা ছাড়া সারিটা বলত «অন্যের কাছে পাঠানো»,
                               আর প্রশ্নটা থেকে যেত: **কার** কাছে? ⛔ ঐ উত্তরটা
                               হারালে «কে সই করেছিল» প্রশ্নের শিকলটাই ছিঁড়ে যায়,
                               আর ঐ শিকলের জন্যই গোটা ব্যবস্থাটা আছে। */
                            ['key' => 'decision', 'label' => __('approval::field.status'), 'width' => '12rem',
                             'render' => fn ($d) => $d->decision === \App\Models\ApprovalDecision::FORWARDED
                                 && $d->forwardedTo !== null
                                 ? __('approval::status.'.$d->decision).' → '.$d->forwardedTo->name
                                 : __('approval::status.'.$d->decision)],
                            ['key' => 'remarks', 'label' => __('approval::field.remarks')],
                            ['key' => 'decided_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                             'render' => fn ($d) => $d->decided_at?->format('d M Y, H:i')],
                        ]" />
                </div>
            @endif
        </div>

        <div class="space-y-3">
            @if ($approval->status === \App\Models\Approval::PENDING && $canDecide)
                <form method="POST" action="{{ route('approval.inbox.approve', $approval->id) }}"
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    <label class="block">
                        <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.remarks') }}
                        </span>
                        <textarea name="remarks" rows="3" maxlength="500"
                                  class="w-full rounded-(--radius-field) border border-(--color-border)
                                         bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                    </label>

                    <x-ui.button type="submit" tone="primary" class="mt-3 w-full">
                        {{ __('approval::action.approve') }}
                    </x-ui.button>
                </form>

                {{-- ফেরত পাঠাতে কারণ লেখা বাধ্যতামূলক — "না" শুনে মানুষ
                     প্রথমেই জানতে চান কেন, আর না জানলে একই অনুরোধ আবার
                     আসে। --}}
                <form method="POST" action="{{ route('approval.inbox.reject', $approval->id) }}"
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    <label class="block">
                        <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.remarks') }} *
                        </span>
                        <textarea name="remarks" rows="3" maxlength="500" required
                                  class="w-full rounded-(--radius-field) border border-(--color-border)
                                         bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                    </label>

                    <x-ui.button type="submit" tone="danger" class="mt-3 w-full">
                        {{ __('approval::action.reject') }}
                    </x-ui.button>
                </form>

                {{-- ⭐ সইটা অন্যের হাতে দেওয়া — ২২ সেপ্টেম্বর ২০২৬।

                     ⓘ তালিকায় কেবল তাঁরাই, যাঁদের নাম কোনো সচল ছকে আছে
                     (মালিকের সিদ্ধান্ত)। ⚠️ যে কারো কাছে পাঠানো গেলে ছকটা
                     আর "কে সই দিতে পারেন" প্রশ্নের উত্তর থাকত না।

                     ⛔ বোতামটা সবার শেষে, আর কারণটা ক্রমে: প্রথমে সই,
                     তারপর ফেরত, তারপর হাতবদল। ⓘ মানুষ উপরেরটা আগে পড়েন,
                     তাই সহজ পথটা যেন উপরে থাকে। --}}
                @if ($forwardTo !== [])
                    <form method="POST" action="{{ route('approval.inbox.forward', $approval->id) }}"
                          class="rounded-(--radius-card) border border-(--color-border)
                                 bg-(--color-surface-card) p-4">
                        @csrf

                        <x-ui.select name="to"
                                     :label="__('approval::field.forward_to')"
                                     :options="$forwardTo"
                                     :placeholder="__('approval::field.forward_pick')"
                                     required />

                        {{-- ⓘ কারণ ছাড়া নয়: যাঁর হাতে কাগজটা পড়বে তাঁর
                             প্রথম প্রশ্ন "আমাকে কেন?" — উত্তর না থাকলে তিনি
                             আবার কাউকে পাঠান, আর কাগজটা ঘুরতে থাকে। --}}
                        <label class="mt-3 block">
                            <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                                {{ __('approval::field.remarks') }} *
                            </span>
                            <textarea name="remarks" rows="2" maxlength="500" required
                                      class="w-full rounded-(--radius-field) border border-(--color-border)
                                             bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                        </label>

                        <x-ui.button type="submit" tone="secondary" class="mt-3 w-full">
                            {{ __('approval::action.forward') }}
                        </x-ui.button>
                    </form>
                @endif
            @elseif ($approval->status === \App\Models\Approval::PENDING)
                <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)
                          p-4 text-sm text-(--color-ink-muted)">
                    {{ $approval->requested_by === auth()->id()
                        ? __('approval::message.own_request')
                        : __('approval::message.not_your_turn') }}
                </p>
            @endif

            @if ($approval->status === \App\Models\Approval::PENDING && $approval->requested_by === auth()->id())
                <form method="POST" action="{{ route('approval.inbox.withdraw', $approval->id) }}">
                    @csrf
                    <x-ui.button type="submit" tone="secondary" class="w-full">
                        {{ __('approval::action.withdraw') }}
                    </x-ui.button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.app>
