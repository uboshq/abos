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
                {{-- ⭐ ঘড়িটা অবস্থার পাশে — ২৪ সেপ্টেম্বর ২০২৬।

                     ⓘ ইনবক্সে লাল দেখে কেউ এখানে এলে চিহ্নটা যেন
                     থাকে — নাহলে ওই তথ্যটা ঠিক সিদ্ধান্তের মুহূর্তে হারাত। --}}
                @include('approval::inbox.partials.clock', [
                    'approval' => $approval,
                    'state' => $slaState,
                ])

                <x-ui.badge :tone="match ($approval->status) {
                    \App\Models\Approval::APPROVED => 'success',
                    \App\Models\Approval::REJECTED => 'danger',
                    \App\Models\Approval::CANCELLED => 'draft',
                    default => 'pending',
                }">
                    {{ __('approval::status.'.$approval->status) }}
                </x-ui.badge>

                {{-- ⭐ আগের আর পরের — সারির ক্রম ইনবক্সেরই।

                     ⛔ বারোটা অনুরোধে সই দিতে হলে আগে বারোবার
                     তালিকায় ফিরতে হত, আর প্রতিবার খুঁজতে হত কোনটা
                     শেষ দেখা হয়েছিল। ⓘ তীর দুইটা কেবল তাঁর জন্য
                     যিনি সই দিতে পারেন — অন্যদের কোনো সারি নেই। --}}
                @if ($prev || $next)
                    <span class="flex items-center gap-1">
                        @if ($prev)
                            <a href="{{ route('approval.inbox.show', $prev) }}" data-approval-prev
                               class="rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-sm
                                      hover:bg-(--color-surface-hover)"
                               aria-label="{{ __('approval::action.previous') }}"
                               title="{{ __('approval::action.previous') }}">&larr;</a>
                        @endif

                        @if ($next)
                            <a href="{{ route('approval.inbox.show', $next) }}" data-approval-next
                               class="rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-sm
                                      hover:bg-(--color-surface-hover)"
                               aria-label="{{ __('approval::action.next') }}"
                               title="{{ __('approval::action.next') }}">&rarr;</a>
                        @endif
                    </span>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    {{-- ⭐ তিন কলাম — ২৪ সেপ্টেম্বর ২০২৬।

         বাঁয়ে যাত্রাপথ (কোথায় আছে) · মাঝখানে কাগজ · ডানে সিদ্ধান্ত।

         ⚠️ ডান কলামটা `sticky`: লম্বা কাগজ পড়তে পড়তে নিচে
         নামলে সইয়ের বোতাম দুইটা পর্দার বাইরে চলে যেত, আর
         মানুষ ফিরে উপরে যেতেন — ঠিক যখন তিনি সিদ্ধান্ত নিয়ে
         ফেলেছেন। ⓘ ছোট পর্দায় কলামগুলো একের পর এক, তাই
         `lg:` থেকেই — ফোনে sticky বসালে ওটা কাগজটা ঢেকে দিত। --}}
    <div class="grid gap-4 lg:grid-cols-[16rem_1fr_20rem] lg:items-start">
        {{-- ── বাঁ কলাম: যাত্রাপথ ──────────────────── --}}
        @if ($timeline !== [])
            <aside data-boxed
                   class="order-last min-w-0 rounded-(--radius-card) border border-(--color-border)
                          bg-(--color-surface-card) p-4 lg:order-first lg:sticky lg:top-4">
                <h2 class="mb-2 text-sm font-semibold">{{ __('approval::field.journey') }}</h2>

                @include('approval::inbox.partials.timeline', ['timeline' => $timeline])

                {{-- ⭐ পাশের তথ্য — কার, কী বাবদ, কোথায়।

                     ── ⛔ কেন এগুলো এই পাতায়ই ───────────────────────
                     ⓘ যিনি সই দিতে বসেছেন তাঁর পরের প্রশ্ন সবসময়
                     একটাই: *"কার কাগজ, কী বাবদ"*। ⚠️ উত্তরটা অন্য
                     পর্দায় থাকলে তিনি হয় সেখানে যান, নয় **না দেখেই
                     সই দেন** — আর দ্বিতীয়টাই বেশি হয়। --}}
                @if (array_filter($facts))
                    <dl class="mt-3 space-y-2 border-t border-(--color-border) pt-3">
                        @foreach ([
                            'party' => __('approval::field.party'),
                            'about' => __('approval::field.what_for'),
                            'where' => __('approval::field.where_money'),
                        ] as $key => $label)
                            @if (($facts[$key] ?? null))
                                <div>
                                    <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                                        {{ $label }}
                                    </dt>
                                    <dd class="text-sm">{{ $facts[$key] }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                @endif
            </aside>
        @endif

        <div class="min-w-0 space-y-4">
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

                    {{-- ⭐ ফরওয়ার্ড হলে কার কাছে গেল সেটা সারিতে।

                         ⚠️ নামটা ছাড়া সারিটা বলত *‹অন্যের কাছে পাঠানো›*, আর
                         প্রশ্নটা থেকে যেত: **কার** কাছে? ⛔ ঐ উত্তরটা হারালে
                         *‹কে সই করেছিল›* প্রশ্নের শিকলটা ছিঁড়ে যায়, আর ঐ
                         শিকলের জন্যই গোটা ব্যবস্থাটা আছে।

                         ⓘ মন্তব্যটা ট্যাগের বাইরে — কারণটা
                         [[NoBladeTagIsQuietlyLeftAsTextTest]]-এ।

                         ⓘ স্তরের ঘরে নাম থাকলে ‹২ · সুপারভাইজার›,
                         না থাকলে আগের মতোই ‹২›। --}}
                    <x-ui.table
                        :empty="__('approval::field.decisions')"
                        :rows="$approval->decisions"
                        :columns="[
                            ['key' => 'level', 'label' => __('approval::field.level'), 'width' => '9rem',
                             'render' => fn ($d) => isset($stepNames[$d->level])
                                 ? $d->level.' · '.$stepNames[$d->level]
                                 : $d->level],
                            ['key' => 'user', 'label' => __('approval::field.approver'),
                             'render' => fn ($d) => $d->user?->name],
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

        <div class="min-w-0 space-y-3 lg:sticky lg:top-4">
            @if ($approval->status === \App\Models\Approval::PENDING && $canDecide)
                {{-- ⛔ সইয়ের আগে একটা প্রশ্ন — আর শর্টকাট এটা এড়াতে পারে না।

                     ⓘ নিশ্চিতকরণটা [[actions.js]]-এর `data-confirm`-এ, অর্থাৎ
                     ফর্মটা যেখান থেকেই জমা হোক — বোতাম, Enter, বা
                     স্ক্রিপ্ট — প্রশ্নটা আসবেই। ⚠️ তাই নিচের কি-বোর্ড
                     কখনো `requestSubmit()` ডাকে না, আর ডাকলেও এড়ানো যেত না।

                     ⛔ প্রশ্নটা শুধু সইয়ে, ফেরতে নয়: ভুল "হ্যাঁ" টাকা
                     নড়িয়ে দেয়, আর ভুল "না" কেবল একটা কাগজ থামায় —
                     যেটা আবার পাঠানো যায়। --}}
                <form method="POST" action="{{ route('approval.inbox.approve', $approval->id) }}"
                      data-confirm="{{ $approval->amount === null
                          ? __('approval::message.approve_confirm_plain')
                          : __('approval::message.approve_confirm', [
                              'amount' => \App\Core\Support\Money::format($approval->amount),
                          ]) }}"
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    <label class="block">
                        <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.remarks') }}
                        </span>
                        <textarea name="remarks" rows="3" maxlength="500" data-approval-remarks
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

                    {{-- ⭐ কারণটা বাছাই করা হয়, শুধু লেখা নয় — ধাপ ১০।

                         ⓘ মুক্ত লেখাটাও থাকে, আর সেটাই মানুষটার জন্য।
                         ⚠️ কোডটা রিপোর্টের জন্য: *"দাম ভুল"* দশ বানানে
                         লেখা হলে *"কেন বাতিল হয়"* প্রশ্নের কোনো উত্তর
                         থাকত না — আর ওটাই সবচেয়ে কাজের প্রশ্ন।

                         ⓘ বাধ্যতামূলক নয়: না বাছলে ইঞ্জিন খালিই রাখে,
                         আর পুরনো সারিগুলোর মতোই সেটা "অব্যক্ত" হয়ে গোনা হয়। --}}
                    <x-ui.select name="reason_code"
                                 :label="__('approval::field.reason_code')"
                                 :options="collect(\App\Models\ApprovalDecision::REASONS)
                                     ->mapWithKeys(fn ($r) => [$r => __('approval::reason.'.$r)])"
                                 :placeholder="__('approval::field.reason_pick')" />

                    <label class="mt-3 block">
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

                {{-- ⭐ "সংশোধন করে আনুন" — বাতিল থেকে আলাদা পথ।

                     ── ⓘ কেন এটা পার্থক্য তৈরি করে ───────────────────
                     ⛔ *"না"* আর *"অল্প ভুল আছে, ঠিক করে আনুন"* এক কথা
                     নয়। ⓘ প্রথমটা একটা ব্যবসায়িক সিদ্ধান্ত, দ্বিতীয়টা
                     একটা কাগজের ভুল — আর *"কেন বাতিল হয়"* রিপোর্টে
                     দুইটা এক গোনা হলে সংখ্যাটা কোনো প্রশ্নের উত্তর দিত না।

                     ── ⚠️ কেন এটা একটা **চতুর্থ অবস্থা** নয় ────────────
                     ⓘ পার্থক্যটা কারণ-কোডেই ধরা পড়ে (`document`), আর পরের
                     পথটাও অবিকল এক: কাগজটা সংশোধন করলে ছাপ বদলায়, আর
                     [[DocumentApproval::stopping()]] নিজে নতুন অনুরোধ বসায়।

                     ⛔ নতুন একটা অবস্থা বসালে ইঞ্জিন, ছয়টা পর্দা, চারটা
                     রিপোর্ট আর bulk — সবগুলোর `match` শাখা বাড়াতে হত, আর
                     যেখানে বাড়ানো হত না সেখানে কাগজটা **নীরবে অদৃশ্য** হত। --}}
                <form method="POST" action="{{ route('approval.inbox.reject', $approval->id) }}"
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    {{-- ⓘ কারণ-কোডটা লুকানো ঘরে, বাহিরে নয়।

                         ⛔ ড্রপডাউনে রাখলে দুইটা ফর্মে একই প্রশ্ন দুইবার
                         থাকত, আর ব্যবহারকারী *"সংশোধনে ফেরত"* বেছে কারণে
                         *"দাম ভুল"* লিখতে পারতেন — দুইটা পরস্পরবিরোধী। --}}
                    <input type="hidden" name="reason_code" value="document">

                    <label class="block">
                        <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ __('approval::field.what_to_fix') }} *
                        </span>
                        <textarea name="remarks" rows="2" maxlength="500" required
                                  placeholder="{{ __('approval::message.send_back_hint') }}"
                                  class="w-full rounded-(--radius-field) border border-(--color-border)
                                         bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                    </label>

                    <x-ui.button type="submit" tone="warning" class="mt-3 w-full" data-shortcut="send-back">
                        {{ __('approval::action.send_back') }}
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
                {{-- ⭐ কেন পারছেন না — তিনটা কারণের মধ্যে কোনটা।

                     ── ⛔ আগে দুইটাই লেখা হত ───────────────────────
                     নিজের অনুরোধ, নাহলে *"আপনার পালা নয়"*। ⚠️ কিন্তু
                     কর্তৃত্বের সীমা পার হলেও সেই একই লেখা উঠত — আর সেটা
                     মিথ্যা: পালা তাঁরই, কেবল অঙ্কটা বড়।

                     ⛔ ফল নীরব: তিনি ধরে নিতেন কাগজটা অন্য কারো
                     কাছে আছে আর অপেক্ষা করতেন — অথচ কাগজটা কারো
                     কাছে নেই। --}}
                <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)
                          p-4 text-sm text-(--color-ink-muted)">
                    {{ $whyNot ?? __('approval::message.not_your_turn') }}
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

    {{-- ⭐ কি-বোর্ড — হাত যেন কি-বোর্ড না ছাড়ে।

         ── ⛔ যা এখানে ইচ্ছাকৃতভাবে **নেই** ────────────────────
         সই বা ফেরতের কোনো শর্টকাট নেই। ⚠️ একটা অক্ষরে পাঁচ
         লাখ টাকা পাস করা দ্রুততা নয়, দুর্ঘটনা — আর ওই ভুলটা
         ফেরানো যায় না। ⓘ শর্টকাটগুলো কেবল **পৌঁছানোর** —
         ঘরে যাওয়া, পাতা বদলানো — কখনো **করার** নয়।

         ── ⓘ কেন এখানে, actions.js-এ নয় ─────────────────────
         ঘরগুলো এই পর্দার নিজের, আর শেয়ার্ড ফাইলে পর্দা-নির্দিষ্ট
         সিলেক্টর জমলে একদিন কেউ পর্দাটা মুছে দেবেন আর কোডটা
         থেকে যাবে। --}}
    <script @nonce>
        (() => {
            /* ⚠️ লেখার ঘরে কার্সার থাকলে তীর দিয়ে পাতা বদলাবে না —
               নাহলে মন্তব্য লেখার সময় বাঁ তীরে কাগজটাই বদলে যেত। */
            const typing = (el) => el && (el.isContentEditable
                || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && typing(event.target)) {
                    event.target.blur()

                    return
                }

                if (event.ctrlKey || event.metaKey) {
                    if (event.key?.toLowerCase?.() !== 'k') {
                        return
                    }

                    const box = document.querySelector('[data-approval-remarks]')

                    if (box) {
                        event.preventDefault()
                        box.focus()
                    }

                    return
                }

                if (typing(event.target)) {
                    return
                }

                /* ⓘ তীর দুইটা কেবল তখনই চলে যখন লিংকটা সত্যি আছে।
                   ⛔ নাহলে শেষ কাগজে ডান তীর চাপলে কিছুই হত না, আর
                   মানুষ ভাবতেন কি-বোর্ডটাই কাজ করছে না। */
                const to = event.key === 'ArrowLeft'
                    ? document.querySelector('[data-approval-prev]')
                    : (event.key === 'ArrowRight'
                        ? document.querySelector('[data-approval-next]')
                        : null)

                if (to) {
                    event.preventDefault()
                    window.location.href = to.href
                }
            })
        })()
    </script>
</x-layouts.app>
