{{--
    আমার সিদ্ধান্তের অপেক্ষায়।

    পুরনোটা উপরে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই সবচেয়ে বেশি
    কাউকে আটকে রেখেছে। নতুনটা উপরে রাখলে পুরনো অনুরোধগুলো নিচে চাপা
    পড়ত, আর ঠিক ওগুলোই মানুষ ভুলে যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
        {{--
            অন্য কারো ইনবক্স দেখলে শিরোনামেই তাঁর নাম।

            ⚠️ নাহলে পাতাটা দেখতে হুবহু নিজের ইনবক্সের মতো, আর পাঠক
            ভাববেন **তাঁর নিজের** সইয়ের অপেক্ষায় বারোটা কাগজ ঝুলে আছে।
            ⓘ ভুল বোঝাটা নীরব: সংখ্যাটা সত্যি, কেবল কার সংখ্যা তা নয়।
        --}}
        {{-- ⚠️ গোনাটা `$visibleTotal`, `$approvals->count()` নয়।

             তালিকাটা এখন পঞ্চাশে বাঁধা, তাই সারি গুনলে উপরে লেখা উঠত
             "৫০টি রেকর্ড" — যেখানে সত্যিকারের সংখ্যা একশো সাঁইত্রিশ।
             আর কম দেখানোটা এখানে নিরীহ নয়: মানুষ ঠিক এই সংখ্যাটা দেখেই
             ঠিক করেন আজ বসে অনুমোদন করবেন কি না।

             ছাঁকনি দেওয়া থাকলে `$visibleTotal` ওই মডিউলের সংখ্যা, তাই
             শিরোনাম আর নিচের তালিকা একই কথা বলে। --}}
        {{-- ⭐ শিরোনাম আর গোনা এখন টুলবারে — আগে ছিল page-header-এ,
             তালিকার বাক্সের বাইরে। মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬:
             *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*।

             ⓘ খোঁজার ঘর নেই (:search="false") — কন্ট্রোলার কোনো `q`
             পড়ে না, আর যে ঘর কিছুই খোঁজে না সেটা মৃত বোতাম।

             ⚠️ মডিউলটা লুকানো ঘরে ফর্মের সাথে যায়: নাহলে ঘনত্ব বা
             রিফ্রেশ চাপলে ছাঁকনিটা নীরবে উঠে যেত, আর সংখ্যাটা বদলে
             যেত কোনো কারণ না দেখিয়ে। ঘরটা টুলবারের **বাইরে**, কারণ
             ভেতরে বসালে খালি ছাঁকনির প্যানেল খোলার একটা মৃত বোতাম
             উঠত। --}}
        @if ($selected !== '')
            <input type="hidden" name="module" value="{{ $selected }}">
        @endif

        <x-ui.toolbar :title="$person ? __('approval::menu.inbox_of', ['name' => $personName]) : __('approval::menu.inbox')"
                      :count="trans_choice('core.count.records', $visibleTotal, ['count' => $visibleTotal])"
                      :search="false"
                      :filter-labels="['person' => __('approval::field.whose_inbox'), 'module' => __('approval::field.module')]">
            {{--
                কার ইনবক্স — কেবল যাঁর অনুমতি আছে তাঁর জন্য।

                ── কেন ড্রপডাউন, চিপ নয় ─────────────────────────────────
                মডিউলের চিপে সংখ্যা বসে, কারণ গণনাটা **একই তালিকা থেকেই**
                পাওয়া যায় — বাড়তি কোনো কোয়েরি লাগে না। মানুষের বেলায়
                তা নয়: প্রত্যেকের সংখ্যা জানতে প্রত্যেকের জন্য আলাদা
                হিসাব করতে হত। ⚠️ আর সংখ্যা ছাড়া দশটা চিপ কেবল জায়গা
                নিত, তাই ওটা ড্রপডাউন।

                ⓘ "সবাই" বিকল্প নেই — সেটা রিপোর্টের প্রশ্ন, ইনবক্সের নয়।

                ⓘ ১৯ সেপ্টেম্বর ২০২৬ থেকে এটা টুলবারের ছাঁকনির ঘরে — নিজের
                আলাদা ফর্মে থাকলে টুলবারের ফর্মের ভেতরে ফর্ম বসাতে হত, যা
                HTML মানে না।
            --}}
            @if (count($signers) > 1)
                <label for="person" class="text-xs text-(--color-ink-muted)">
                    {{ __('approval::field.whose_inbox') }}
                </label>

                {{-- ⓘ "নিজের ইনবক্স"-এর মান খালি, "0" নয় — ফর্মটা এখন
                     ঘনত্ব আর রিফ্রেশেও জমা পড়ে, আর "0" গেলে টুলবার সেটাকে
                     চালু ছাঁকনি ভেবে একটা অর্থহীন চিপ আঁকত। কন্ট্রোলার
                     খালিকে শূন্যই পড়ে। --}}
                <select id="person" name="person" data-action="submit-form"
                        class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('approval::field.my_inbox') }}</option>
                    @foreach ($signers as $id => $name)
                        <option value="{{ $id }}" @selected($person === $id)>{{ $name }}</option>
                    @endforeach
                </select>

                {{-- JS বন্ধ থাকলেও যেন কাজ করে — নিয়ম নয়, সৌজন্য নয়, শর্ত --}}
                <noscript>
                    <button type="submit" class="text-xs underline">{{ __('core.action.apply') }}</button>
                </noscript>
            @endif
        </x-ui.toolbar>
        </form>

    {{--
        মডিউল ধরে ছাঁকনি — §২.২।

        ── কেন সংখ্যাটা চিপের গায়েই ────────────────────────────────────
        "ক্রয়" লেখা একটা চিপ চেপে খালি তালিকা পাওয়ার চেয়ে খারাপ কিছু
        নেই। সংখ্যাটা আগে থেকে দেখা গেলে মানুষ জানেন কোথায় কাজ আছে, আর
        যেখানে কিছু নেই সেই চিপটা দেখানোই হয় না।

        ── একটার বেশি মডিউল না থাকলে সারিটাই থাকে না ────────────────────
        একটা মাত্র বিকল্পের ছাঁকনি ছাঁকে না, শুধু জায়গা নেয় — আর নতুন
        প্রতিষ্ঠানে শুরুর দিনগুলোতে ঠিক তা-ই হত।
    --}}
        {{-- ⓘ ১৯ সেপ্টেম্বর ২০২৬ থেকে চিপগুলো বাক্সের ভেতরে, টুলবারের ঠিক
             নিচে — ছাঁকনির প্যানেলে ঢোকানো হয়নি, কারণ ওটা বোতাম চেপে
             খোলে, আর তখন "কোথায় কাজ আছে" সংখ্যাগুলো আড়ালে চলে যেত। --}}
        @if (count($modules) > 1)
                <div class="flex flex-wrap items-center gap-2 border-b border-(--color-border) px-3 py-2" role="group"
                     aria-label="{{ __('approval::field.module') }}">
                    @php
                        $chip = 'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium
                                 transition-colors hover:bg-(--color-surface-hover)';
                        $on = 'border-(--color-border) bg-(--color-surface-selected) text-(--color-ink)';
                        $off = 'border-(--color-border) bg-(--color-surface-card) text-(--color-ink-body)';

                        /*
                            ⚠️ চিপের লিংকে ব্যক্তিটা সাথে যায়।

                            না গেলে রহিমের তালিকায় "ক্রয়" চাপলে নিজের
                            ইনবক্সে ফিরে আসতেন — আর সংখ্যাটা বদলে যেত
                            বলে মনে হত ছাঁকনিটা কাজ করেছে।
                        */
                        $keep = $person ? ['person' => $person] : [];
                    @endphp

                    <a href="{{ route('approval.inbox.index', $keep) }}"
                       @class([$chip, $selected === '' ? $on : $off])
                       @if ($selected === '') aria-current="true" @endif>
                        {{ __('approval::field.all_modules') }}
                        <span class="text-(--color-ink-muted)">{{ $total }}</span>
                    </a>

                    @foreach ($modules as $code => $one)
                        <a href="{{ route('approval.inbox.index', $keep + ['module' => $code]) }}"
                           @class([$chip, $selected === $code ? $on : $off])
                           @if ($selected === $code) aria-current="true" @endif>
                            {{ $one['label'] }}
                            <span class="text-(--color-ink-muted)">{{ $one['count'] }}</span>
                        </a>
                    @endforeach
                </div>
        @endif

        {{-- ⭐ দেরির চিপ — ২৪ সেপ্টেম্বর ২০২৬।

             ⓘ সংখ্যাটা শূন্য হলে চিপটাই থাকে না, মডিউলের চিপগুলোর মতোই:
             ⛔ "দেরি ০" লেখা একটা চিপ চেপে খালি তালিকা পাওয়ার চেয়ে
             খারাপ কিছু নেই, আর ওটা ঠিক ভালো দিনগুলোতেই জায়গা নিত।

             ⚠️ লিংকে ব্যক্তি আর মডিউল দুইটাই সাথে যায় — নাহলে "দেরি"
             চাপলে বাকি ছাঁকনিগুলো নীরবে উঠে যেত, আর সংখ্যাটা বদলে
             যাওয়ায় মনে হত ছাঁকনিটা কাজ করেছে। --}}
        @if ($lateCount > 0)
            @php
                $keepAll = array_filter([
                    'person' => $person ?: null,
                    'module' => $selected !== '' ? $selected : null,
                ]);
            @endphp

            <div class="flex flex-wrap items-center gap-2 border-b border-(--color-border) px-3 py-2">
                <a href="{{ route('approval.inbox.index', $late ? $keepAll : $keepAll + ['late' => 1]) }}"
                   @class([
                       'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium
                        transition-colors hover:bg-(--color-surface-hover)',
                       'border-(--color-badge-danger-ink) bg-(--color-badge-danger-bg)
                        text-(--color-badge-danger-ink)' => $late,
                       'border-(--color-border) bg-(--color-surface-card) text-(--color-ink-body)' => ! $late,
                   ])
                   @if ($late) aria-current="true" @endif>
                    {{ __('approval::field.late') }}
                    <span>{{ $lateCount }}</span>
                </a>
            </div>
        @endif

        {{-- কাটা পড়েছে কি না, আর কতটা — কেবল সত্যিই কাটা পড়লে।

             নিচে যা দেখা যাচ্ছে সেটাই সবটা নয়, আর সেটা না বললে পাতাটা
             সম্পূর্ণ দেখাত অথচ বাকিগুলো চুপচাপ লুকিয়ে রাখত। লম্বা সারিটা
             নিজেই একটা সংকেত — কেউ অনুমোদন করছেন না — তাই সংখ্যাটা
             লুকানোর মানে হত না। --}}
        @if ($visibleTotal > $approvals->count())
            <p role="status"
               class="border-b border-(--color-border) bg-(--color-badge-warning-bg) px-4 py-2
                      text-xs text-(--color-badge-warning-ink)">
                {{ __('approval::message.inbox_capped', [
                    'shown' => $approvals->count(),
                    'total' => $visibleTotal,
                ]) }}
            </p>
        @endif

        {{-- ⭐ একসাথে সই — মালিকের সিদ্ধান্ত ৫, ২৪ সেপ্টেম্বর ২০২৬।

             ⛔ টাকা নড়ে এমন কাজে চেকবক্সটা **আঁকাই হয় না** — মালিকের
             সিদ্ধান্ত: ওগুলো একটা একটা করে খুলে দেখতে হবে। ⓘ তবু
             [[BulkApproval]] সার্ভারেও আলাদা করে দেখে, কারণ একটা লুকানো
             চেকবক্স হাতে বানিয়ে পাঠানো যায় আর পর্দা কখনো শেষ কথা নয়।

             ⚠️ ফর্মটা টেবিলের **বাইরে** মোড়ানো, কারণ `x-ui.table` নিজে
             একটা `<table>` আঁকে — ভেতরে `<form>` বসালে HTML ওটাকে
             টেবিলের বাইরে ঠেলে দিত, আর চেকবক্সগুলো কোনো ফর্মেই থাকত না। --}}
        <form method="POST" action="{{ route('approval.inbox.bulk') }}">
            @csrf

            @php
                /*
                 * ⓘ `in_array` নয়, চাবি ধরে খোঁজা — তালিকাটা পঞ্চাশ সারি,
                 * আর প্রতিটা সারিতে পঞ্চাশটা মিলানো মানে আড়াই হাজার তুলনা।
                 */
                $canPick = array_flip($bulkable);
            @endphp

        <x-ui.table
            :empty="__('approval::message.nothing_waiting')"
            :rows="$approvals"
            :compact="request()->boolean('compact')"
            :columns="[
                ['key' => 'pick', 'label' => __('approval::field.pick'), 'width' => '3rem',
                 'render' => fn ($a) => isset($canPick[$a->id])
                     ? view('approval::inbox.partials.pick', ['approval' => $a])
                     : ''],
                ['key' => 'requested_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requested_at?->format('d M Y, H:i')],
                ['key' => 'sla', 'label' => __('approval::field.sla'), 'width' => '9rem',
                 'render' => fn ($a) => view('approval::inbox.partials.clock', [
                     'approval' => $a, 'state' => $sla[$a->id] ?? 'none',
                 ])],
                ['key' => 'module', 'label' => __('approval::field.action'),
                 'render' => fn ($a) => view('approval::inbox.partials.what', ['approval' => $a, 'labels' => $labels])],
                ['key' => 'party', 'label' => __('approval::field.party'), 'width' => '11rem',
                 'render' => fn ($a) => ($facts[$a->id]['party'] ?? null) ?: '—'],
                ['key' => 'about', 'label' => __('approval::field.what_for'),
                 'render' => fn ($a) => ($facts[$a->id]['about'] ?? null) ?: '—'],
                ['key' => 'where', 'label' => __('approval::field.where_money'), 'width' => '11rem',
                 'render' => fn ($a) => ($facts[$a->id]['where'] ?? null) ?: '—'],
                ['key' => 'requested_by', 'label' => __('approval::field.requested_by'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requester?->name],
                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($a) => $a->amount === null ? '—' : \App\Core\Support\Money::format($a->amount)],
                ['key' => 'open', 'label' => '', 'width' => '7rem',
                 'render' => fn ($a) => view('approval::inbox.partials.open', ['approval' => $a])],
            ]" />

            {{-- ⓘ বোতামটা কেবল তখনই, যখন সত্যিই বাছাই করার কিছু আছে।

                 ⛔ সবসময় দেখালে টাকার কাগজভরা একটা ইনবক্সে বোতামটা
                 জীবন্ত দেখাত, চাপলে "বাছাই করা কিছু নেই" — আর পাঠক
                 ভাবতেন ব্যবস্থাটা ভাঙা। --}}
            @if ($bulkable !== [])
                <div class="flex flex-wrap items-center gap-3 border-t border-(--color-border) px-4 py-3">
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" id="pick-all" class="size-4">
                        {{ __('approval::action.pick_all') }}
                    </label>

                    <x-ui.button type="submit" tone="primary">
                        {{ __('approval::action.bulk_approve') }}
                    </x-ui.button>

                    {{-- ⚠️ সীমাটা লেখা থাকে, লুকানো হয় না।

                         ⓘ চেকবক্সটা যেখানে নেই সেখানে পাঠক ভাবতে পারেন
                         সারিটা ভাঙা। ⛔ কারণটা না লিখলে তিনি একই কাগজে
                         বারবার চেষ্টা করতেন। --}}
                    <p class="text-2xs text-(--color-ink-muted)">
                        {{ __('approval::message.bulk_only_paper') }}
                    </p>
                </div>
            @endif
        </form>
    </div>

    {{-- "সব বাছাই" — JavaScript বন্ধ থাকলে ঘরটা কিছুই করে না, আর
         প্রতিটা সারির নিজের চেকবক্স তখনও কাজ করে। ⓘ তাই এটা সুবিধা,
         শর্ত নয় — ঠিক সেভাবেই বসানো। --}}
    <script @nonce>
        (() => {
            const all = document.getElementById('pick-all');

            if (! all) {
                return;
            }

            all.addEventListener('change', () => {
                document.querySelectorAll('input[name="ids[]"]').forEach((box) => {
                    box.checked = all.checked;
                });
            });
        })();
    </script>
</x-layouts.app>
