{{--
    আমার সিদ্ধান্তের অপেক্ষায়।

    পুরনোটা উপরে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই সবচেয়ে বেশি
    কাউকে আটকে রেখেছে। নতুনটা উপরে রাখলে পুরনো অনুরোধগুলো নিচে চাপা
    পড়ত, আর ঠিক ওগুলোই মানুষ ভুলে যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    <x-slot:header>
        {{--
            অন্য কারো ইনবক্স দেখলে শিরোনামেই তাঁর নাম।

            ⚠️ নাহলে পাতাটা দেখতে হুবহু নিজের ইনবক্সের মতো, আর পাঠক
            ভাববেন **তাঁর নিজের** সইয়ের অপেক্ষায় বারোটা কাগজ ঝুলে আছে।
            ⓘ ভুল বোঝাটা নীরব: সংখ্যাটা সত্যি, কেবল কার সংখ্যা তা নয়।
        --}}
        <x-ui.page-header
            :title="$person ? __('approval::menu.inbox_of', ['name' => $personName]) : __('approval::menu.inbox')"
            :subtitle="trans_choice('core.count.records', $approvals->count(), ['count' => $approvals->count()])" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

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
    @if (count($modules) > 1 || count($signers) > 1)
        <div class="mb-3 flex flex-wrap items-center gap-2">
            {{--
                কার ইনবক্স — কেবল যাঁর অনুমতি আছে তাঁর জন্য।

                ── কেন ড্রপডাউন, চিপ নয় ─────────────────────────────────
                মডিউলের চিপে সংখ্যা বসে, কারণ গণনাটা **একই তালিকা থেকেই**
                পাওয়া যায় — বাড়তি কোনো কোয়েরি লাগে না। মানুষের বেলায়
                তা নয়: প্রত্যেকের সংখ্যা জানতে প্রত্যেকের জন্য আলাদা
                হিসাব করতে হত। ⚠️ আর সংখ্যা ছাড়া দশটা চিপ কেবল জায়গা
                নিত, তাই ওটা ড্রপডাউন।

                ⓘ "সবাই" বিকল্প নেই — সেটা রিপোর্টের প্রশ্ন, ইনবক্সের নয়।
            --}}
            @if (count($signers) > 1)
                <form method="GET" class="flex items-center gap-2">
                    @if ($selected !== '')
                        <input type="hidden" name="module" value="{{ $selected }}">
                    @endif

                    <label for="person" class="text-xs text-(--color-ink-muted)">
                        {{ __('approval::field.whose_inbox') }}
                    </label>

                    <select id="person" name="person" onchange="this.form.submit()"
                            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2 text-sm">
                        <option value="0">{{ __('approval::field.my_inbox') }}</option>
                        @foreach ($signers as $id => $name)
                            <option value="{{ $id }}" @selected($person === $id)>{{ $name }}</option>
                        @endforeach
                    </select>

                    {{-- JS বন্ধ থাকলেও যেন কাজ করে — নিয়ম নয়, সৌজন্য নয়, শর্ত --}}
                    <noscript>
                        <button type="submit" class="text-xs underline">{{ __('core.action.apply') }}</button>
                    </noscript>
                </form>
            @endif

            @if (count($modules) > 1)
                <div class="flex flex-wrap items-center gap-2" role="group"
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
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('approval::message.nothing_waiting')"
            :rows="$approvals"
            :compact="request()->boolean('compact')"
            :columns="[
                ['key' => 'requested_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requested_at?->format('d M Y, H:i')],
                ['key' => 'module', 'label' => __('approval::field.action'),
                 'render' => fn ($a) => view('approval::inbox.partials.what', ['approval' => $a, 'labels' => $labels])],
                ['key' => 'requested_by', 'label' => __('approval::field.requested_by'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requester?->name],
                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($a) => $a->amount === null ? '—' : \App\Core\Support\Money::format($a->amount)],
                ['key' => 'open', 'label' => '', 'width' => '7rem',
                 'render' => fn ($a) => view('approval::inbox.partials.open', ['approval' => $a])],
            ]" />
    </div>
</x-layouts.app>
