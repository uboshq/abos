{{--
    গ্রাহককে পাঠানোর লিংক — "পাঠান" চাপার পরে এই বাক্সটা আসে।

    ── ⚠️ কেন লিংকটা পর্দায় দেখানো হয়, নিজে থেকে পাঠানো হয় না ──────────
    ⓘ হোয়াটসঅ্যাপে **ফাইল** পাঠানোর কোনো পথ ব্রাউজার থেকে নেই — যা পাঠানো
    যায় তা লেখা, আর লেখার ভেতরে লিংক। ⛔ তাই "পাঠিয়ে দিলাম" বলে ভান করা
    হয়নি: লিংকটা মানুষের হাতে দেওয়া হয়, আর তিনি যেখানে খুশি পাঠান।

    ⓘ ৩০ দিন পরে লিংকটা নিজে থেকে মরে যায়, আর সেটা এখানেই লেখা — যাতে
    কেউ ধরে না নেন এটা চিরকালের ঠিকানা।

    ── ⭐ পার্টির WhatsApp-এ (মালিকের "ok", ১০ নভেম্বর ২০২৬) ──────────────
    আগে বোতামটা খুলত নম্বর ছাড়া — প্রতিবার পার্টিকে খুঁজতে হত। ⓘ এখন নম্বর পার্টির খাতা থেকে বসানো (বদলানো যায়),
    লেখা বেছে নেওয়া যায় (কন্ট্রোল প্যানেলের "পার্টিকে বার্তা — লেখা"), ভাষা বাংলা বা English, আর পাঠানোর আগে
    খাতায় লেখা হয় কে কাকে কী পাঠাল ([[PaperShareController::whatsapp()]])।
    ⓘ কাগজটা নাগালের বাইরে বা লিংক মরা হলে পুরনো নম্বরহীন বোতামটাই থাকে।
--}}
@if (session('shared_link'))
    @php
        $link = session('shared_link');
        $text = trim(($message ?? '').' '.$link);
        $wa = session('shared_share') ? app(\App\Core\Services\PartyMessages::class)->box((int) session('shared_share')) : null;
        $choices = $wa['choices'] ?? [];
    @endphp

    <div role="status" x-data="sharedLink({ url: @js($link), choices: @js($choices), partsWord: @js(__('notification::sms.parts_note', ['n' => '#'])) })"
         class="mb-4 rounded-(--radius-card) border border-(--color-brand-500)
                bg-(--color-surface-card) p-3">
        <p class="mb-2 text-sm font-medium">{{ __('core.print.link_ready') }}</p>

        <div class="flex flex-wrap items-center gap-2">
            {{-- ⓘ ঘরটা পড়ার জন্য, লেখার জন্য নয় — কিন্তু বেছে নিয়ে কপি করা যায়,
                 কারণ ক্লিপবোর্ডের অনুমতি সব ব্রাউজারে সমান নয়। --}}
            <input type="text" readonly value="{{ $link }}" x-on:focus="$el.select()"
                   class="h-(--spacing-field) min-w-0 flex-1 rounded-(--radius-field)
                          border border-(--color-border) bg-(--color-surface-muted) px-3 text-sm">

            <x-ui.button type="button" tone="secondary" x-on:click="copy()">
                <span x-show="! copied">{{ __('core.print.copy_link') }}</span>
                <span x-show="copied" x-cloak>{{ __('core.action.copied') }}</span>
            </x-ui.button>

            @if ($wa === null)
                <x-ui.button tone="primary" target="_blank" rel="noopener"
                             :href="'https://wa.me/?text='.urlencode($text)">
                    {{ __('core.print.whatsapp') }}
                </x-ui.button>
            @endif
        </div>

        @if ($wa !== null)
            @if ($choices === [])
                <p class="mt-3 text-sm text-(--color-ink-muted)" data-wa-no-template>{{ __('core.print.wa_no_template') }}</p>
            @else
                {{-- ⓘ নতুন পাতায় খোলে: খাতায় লিখে সার্ভার wa.me-তে পাঠায়, আর এই পাতাটা যেমন ছিল তেমনই থাকে --}}
                <form method="POST" action="{{ route('paper.whatsapp', $wa['share']) }}" target="_blank"
                      class="mt-3 grid gap-3 border-t border-(--color-border) pt-3 md:grid-cols-3" data-wa-form>
                    @csrf
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('core.print.wa_phone') }}
                        <input type="tel" name="phone" required maxlength="30" inputmode="tel"
                               {{-- ⓘ দেশের নম্বর যেভাবে মানুষ লেখেন (01…); বিদেশের নম্বর + সহ — পাঠানোর সময় আবার আন্তর্জাতিক রূপে --}}
                               value="{{ ($p = $wa['party']['phone'] ?? null) === null ? '' : (str_starts_with($p, '880') ? substr($p, 2) : '+'.$p) }}"
                               placeholder="01XXXXXXXXX"
                               class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm tabular-nums">
                        @if (($wa['party']['phone'] ?? null) === null)
                            <span class="text-(--color-warning-ink)" data-wa-phone-missing>{{ __('core.print.wa_phone_missing') }}</span>
                        @elseif ($wa['party']['name'] !== '')
                            <span>{{ $wa['party']['name'] }}</span>
                        @endif
                    </label>

                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('core.print.wa_template') }}
                        <select name="template_id" x-model="templateId"
                                class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            @foreach ($choices as $choice)
                                <option value="{{ $choice['id'] }}">{{ $choice['name'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <fieldset class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        <legend class="mb-1">{{ __('core.print.wa_language') }}</legend>
                        <div class="flex gap-4 text-sm text-(--color-ink)">
                            <label class="flex items-center gap-1.5"><input type="radio" name="locale" value="bn" x-model="locale" checked> {{ __('core.print.wa_bn') }}</label>
                            <label class="flex items-center gap-1.5"><input type="radio" name="locale" value="en" x-model="locale"> {{ __('core.print.wa_en') }}</label>
                        </div>
                    </fieldset>

                    <div class="grid gap-1 text-2xs text-(--color-ink-muted) md:col-span-3">
                        {{ __('core.print.wa_preview') }}
                        <p x-text="preview" data-wa-preview
                           class="whitespace-pre-line rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-muted) p-2 text-sm text-(--color-ink)">{{ $choices[0]['bn'] }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 md:col-span-3">
                        <x-ui.button type="submit" tone="primary">{{ __('core.print.whatsapp') }}</x-ui.button>
                        {{-- ⭐ একই লেখা SMS-এ — অফিসের ফোনের সারিতে (নমুনা ধাপ ২); এই পাতাতেই ফেরে, তাই নতুন পাতা নয় --}}
                        @if ($wa['sms'] ?? false)
                            <x-ui.button type="submit" tone="secondary" formaction="{{ route('paper.sms', $wa['share']) }}" formtarget="_self" data-sms-send>{{ __('notification::sms.send') }}</x-ui.button>
                            <span class="text-2xs text-(--color-ink-muted)" x-text="smsParts" data-sms-parts></span>
                        @else
                            <span class="text-2xs text-(--color-ink-muted)" data-sms-off>{{ __('notification::sms.no_gateway') }}</span>
                        @endif
                    </div>
                </form>
            @endif
        @endif

        @if (session('sms_queued'))
            <p role="status" class="mt-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)" data-sms-queued>{{ session('sms_queued') }}</p>
        @endif
        @if (session('sms_error'))
            <p role="alert" class="mt-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)" data-sms-error>{{ session('sms_error') }}</p>
        @endif
    </div>
@endif
