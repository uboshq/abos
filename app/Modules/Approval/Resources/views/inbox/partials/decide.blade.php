{{--
    ⭐ মন্তব্য আর চারটা কাজ, পাশাপাশি — অনুমোদন পর্দা ভাগ ২, দফা ১১ (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; পরিকল্পনার পাতা ২ আর ৩):
    ফরোয়ার্ড করুন · প্রত্যাখ্যান করুন (লাল) · সংশোধনে ফেরত পাঠান · অনুমোদন দিন। ⓘ শব্দ ২৫ অক্টোবরের ERP মানের — পরিকল্পনার পুরনো
    "সই দিন / অন্যকে দিন" নয় (নতুন সিদ্ধান্তই খাটে)।

    ── ⓘ কেন ট্যাব নয় ─────────────────────────────────────────────────────
    ⛔ আগে চারটা কাজ ছিল চারটা ট্যাবে — "প্রত্যাখ্যান" বেছে নিতে আগে ট্যাব চাপতে হত, আর কোন বোতাম কী করবে তা ট্যাবের লেখা দেখে
    বুঝতে হত। এখন সবগুলো চোখের সামনে, প্রতিটার নিজের রং।

    ── ⛔ ফেরানো-কঠিন কাজ জিজ্ঞাসা ছাড়া হয় না (দফা ১২) ──────────────────────
    অনুমোদন: ব্রাউজারের "নিশ্চিত?" (`data-confirm`, অঙ্কসহ)। প্রত্যাখ্যান, ফেরত, ফরোয়ার্ড: নিজের পপ-আপ — কারণ আর মন্তব্য ছাড়া
    এগোয় না, আর পপ-আপের লেখাটাই জিজ্ঞাসা (`data-asks-in-dialog`, [[TheApprovalAsksBeforeEveryHardActionTest]])।

    ── ⓘ কীবোর্ড (দফা ২০, এগিয়ে আনা) ─────────────────────────────────────
    A · S · R কেবল **খোলে** — অনুমোদনের "নিশ্চিত?" বা পপ-আপ; ⛔ একটা অক্ষরে কখনো কিছু জমা পড়ে না। লেখার ঘরে থাকলে কিছুই নয়।
--}}
@php
    $from ??= '';
    $nextId ??= null;
    $isBundle = $bundle !== null;
@endphp

<section data-boxed data-approval-decide x-data="{ ask: '' }" x-on:keydown.escape.window="ask = ''"
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">

    {{-- ⓘ অনুমোদনের ফর্মটাই মন্তব্যের ঘর ধরে — প্রত্যাখ্যান আর ফেরতের পপ-আপে নিজের বাধ্যতামূলক ঘর --}}
    <form method="POST" id="approve-{{ $approval->id }}"
          action="{{ $isBundle ? route('approval.inbox.confirm_all', $approval->id) : route('approval.inbox.approve', $approval->id) }}"
          data-confirm="{{ $isBundle
              ? __('approval::message.bundle_confirm', ['count' => collect($bundle['approvals'])->where('status', \App\Models\Approval::PENDING)->count()])
              : ($approval->amount === null
                  ? __('approval::message.approve_confirm_plain')
                  : __('approval::message.approve_confirm', ['amount' => \App\Core\Support\Money::format($approval->amount)])) }}">
        @csrf
        <input type="hidden" name="next" value="{{ $nextId }}">
        <input type="hidden" name="from" value="{{ $from }}">

        <label class="block">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('approval::field.comment') }}</span>
            <textarea name="remarks" rows="2" maxlength="500" data-approval-remarks
                      class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
        </label>
    </form>

    <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
        @if ($forwardTo !== [] && ! $isBundle)
            <x-ui.button type="button" tone="ghost" x-on:click="ask = 'forward'" data-ask="forward">
                {{ __('approval::action.forward') }}
            </x-ui.button>
        @endif

        <x-ui.button type="button" tone="secondary" x-on:click="ask = 'reject'" data-ask="reject" data-key="r"
                     class="border-(--color-danger) text-(--color-danger)">
            {{ __('approval::action.reject') }}
        </x-ui.button>

        <x-ui.button type="button" tone="secondary" x-on:click="ask = 'fix'" data-ask="fix" data-key="s" data-shortcut="send-back">
            {{ __('approval::action.send_back') }}
        </x-ui.button>

        <x-ui.button type="submit" tone="primary" form="approve-{{ $approval->id }}" data-key="a">
            {{ $isBundle ? __('approval::action.confirm_all') : __('approval::action.approve') }}
        </x-ui.button>
    </div>

    <p class="mt-2 text-end text-2xs text-(--color-ink-muted)">{{ __('approval::message.next_opens') }}</p>

    {{-- ── প্রত্যাখ্যান — লাল, কারণ আর মন্তব্য বাধ্যতামূলক, "চূড়ান্ত" সতর্কতা ─────────── --}}
    <div x-show="ask === 'reject'" x-cloak role="dialog" aria-modal="true" aria-labelledby="reject-title-{{ $approval->id }}"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 text-start">
        <form method="POST" action="{{ route('approval.inbox.reject', $approval->id) }}" x-on:click.outside="ask = ''"
              data-asks-in-dialog="{{ __('approval::message.confirm_reject') }}"
              class="w-full max-w-lg rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-lg">
            @csrf
            <input type="hidden" name="next" value="{{ $nextId }}">
            <input type="hidden" name="from" value="{{ $from }}">

            <h3 id="reject-title-{{ $approval->id }}" class="border-b border-(--color-border) px-5 py-3 font-semibold">
                {{ __('approval::action.reject') }}? — <span class="num">{{ $facts['no'] ?? '#'.$approval->id }}</span>
            </h3>

            <div class="space-y-3 px-5 py-4">
                <p class="border-s-4 border-(--color-danger) bg-(--color-badge-danger-bg) px-3 py-2 text-xs text-(--color-badge-danger-ink)">
                    {{ __('approval::message.reject_final') }}
                </p>

                {{-- ⓘ কারণ রিপোর্টের জন্য ("কেন প্রত্যাখ্যাত হয়"), মন্তব্য মানুষটার জন্য — দুইটাই লাগে --}}
                <x-ui.select name="reason_code" required
                             :label="__('approval::field.reason_code').' *'"
                             :options="collect(\App\Models\ApprovalDecision::REASONS)
                                 ->mapWithKeys(fn ($r) => [$r => __('approval::reason.'.$r)])"
                             :placeholder="__('approval::field.reason_pick')" />

                <label class="block">
                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('approval::field.remarks') }} *</span>
                    <textarea name="remarks" rows="3" maxlength="500" required
                              class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                </label>
            </div>

            <div class="flex justify-end gap-2 border-t border-(--color-border) px-5 py-3">
                <x-ui.button type="button" tone="ghost" x-on:click="ask = ''">{{ __('approval::action.go_back') }}</x-ui.button>
                <x-ui.button type="submit" tone="danger">{{ __('approval::action.reject') }}</x-ui.button>
            </div>
        </form>
    </div>

    {{-- ── সংশোধনে ফেরত — "কী ঠিক করতে হবে" বাধ্যতামূলক ─────────────────────────── --}}
    {{-- ⭐ খসড়া কাগজে "সংশোধনে ফেরত" আলাদা অবস্থায় যায় (১৪ অক্টোবর ২০২৬, [[ApprovalEngine::sendBack()]]); বাকিতে আগের পথ --}}
    <div x-show="ask === 'fix'" x-cloak role="dialog" aria-modal="true" aria-labelledby="fix-title-{{ $approval->id }}"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 text-start">
        <form method="POST" x-on:click.outside="ask = ''"
              action="{{ ($canSendBack ?? false) ? route('approval.inbox.send_back', $approval->id) : route('approval.inbox.reject', $approval->id) }}"
              data-asks-in-dialog="{{ __('approval::message.confirm_send_back') }}"
              class="w-full max-w-lg rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-lg">
            @csrf
            <input type="hidden" name="next" value="{{ $nextId }}">
            <input type="hidden" name="from" value="{{ $from }}">
            {{-- ⓘ কারণ-কোড লুকানো — "সংশোধনে ফেরত" মানেই কাগজের ভুল; ড্রপডাউনে রাখলে "দাম ভুল" লিখে ফেরত পাঠানো যেত, পরস্পরবিরোধী --}}
            <input type="hidden" name="reason_code" value="document">

            <h3 id="fix-title-{{ $approval->id }}" class="border-b border-(--color-border) px-5 py-3 font-semibold">
                {{ __('approval::action.send_back') }} — <span class="num">{{ $facts['no'] ?? '#'.$approval->id }}</span>
            </h3>

            <div class="space-y-2 px-5 py-4">
                <label class="block">
                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('approval::field.what_to_fix') }} *</span>
                    <textarea name="remarks" rows="3" maxlength="500" required
                              placeholder="{{ __('approval::message.send_back_hint') }}"
                              class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                </label>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('approval::message.send_back_goes') }}</p>
            </div>

            <div class="flex justify-end gap-2 border-t border-(--color-border) px-5 py-3">
                <x-ui.button type="button" tone="ghost" x-on:click="ask = ''">{{ __('approval::action.go_back') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary">{{ __('approval::action.send_back_go') }}</x-ui.button>
            </div>
        </form>
    </div>

    {{-- ── ফরোয়ার্ড — কেবল ছকে নাম থাকা অনুমোদনকারী, কারণসহ ──────────────────────── --}}
    @if ($forwardTo !== [] && ! $isBundle)
        <div x-show="ask === 'forward'" x-cloak role="dialog" aria-modal="true" aria-labelledby="forward-title-{{ $approval->id }}"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 text-start">
            <form method="POST" action="{{ route('approval.inbox.forward', $approval->id) }}" x-on:click.outside="ask = ''"
                  data-asks-in-dialog="{{ __('approval::message.confirm_forward') }}"
                  class="w-full max-w-lg rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                @csrf
                <input type="hidden" name="next" value="{{ $nextId }}">
                <input type="hidden" name="from" value="{{ $from }}">

                <h3 id="forward-title-{{ $approval->id }}" class="border-b border-(--color-border) px-5 py-3 font-semibold">
                    {{ __('approval::action.forward') }} — <span class="num">{{ $facts['no'] ?? '#'.$approval->id }}</span>
                </h3>

                <div class="space-y-3 px-5 py-4">
                    <p class="text-xs text-(--color-ink-muted)">{{ __('approval::message.forward_goes') }}</p>

                    <x-ui.select name="to" required
                                 :label="__('approval::field.forward_to').' *'"
                                 :options="$forwardTo"
                                 :placeholder="__('approval::field.forward_pick')" />

                    <label class="block">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('approval::field.remarks') }} *</span>
                        <textarea name="remarks" rows="2" maxlength="500" required
                                  class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                    </label>
                </div>

                <div class="flex justify-end gap-2 border-t border-(--color-border) px-5 py-3">
                    <x-ui.button type="button" tone="ghost" x-on:click="ask = ''">{{ __('approval::action.go_back') }}</x-ui.button>
                    <x-ui.button type="submit" tone="primary">{{ __('approval::action.forward') }}</x-ui.button>
                </div>
            </form>
        </div>
    @endif
</section>
