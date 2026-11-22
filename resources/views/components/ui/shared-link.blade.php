{{--
    গ্রাহককে পাঠানোর লিংক — "পাঠান" চাপার পরে এই বাক্সটা আসে।

    ── ⚠️ কেন লিংকটা পর্দায় দেখানো হয়, নিজে থেকে পাঠানো হয় না ──────────
    ⓘ হোয়াটসঅ্যাপে **ফাইল** পাঠানোর কোনো পথ ব্রাউজার থেকে নেই — যা পাঠানো
    যায় তা লেখা, আর লেখার ভেতরে লিংক। ⛔ তাই "পাঠিয়ে দিলাম" বলে ভান করা
    হয়নি: লিংকটা মানুষের হাতে দেওয়া হয়, আর তিনি যেখানে খুশি পাঠান।

    ⓘ ৩০ দিন পরে লিংকটা নিজে থেকে মরে যায়, আর সেটা এখানেই লেখা — যাতে
    কেউ ধরে না নেন এটা চিরকালের ঠিকানা।

    ── ⭐ পার্টির WhatsApp-এ (মালিকের "ok", ১০ নভেম্বর ২০২৬) ──────────────
    নম্বর পার্টির খাতা থেকে, লেখা বেছে নেওয়া যায় ("পার্টিকে বার্তা — লেখা"), ভাষা বাংলা বা English, আর পাঠানোর আগে
    খাতায় লেখা হয় কে কাকে কী পাঠাল ([[PaperShareController::whatsapp()]])।
    ⓘ কাগজটা নাগালের বাইরে বা লিংক মরা হলে পুরনো নম্বরহীন বোতামটাই থাকে।

    ── ⭐ ধাপ ৩ — "পার্টিকে পাঠান" পপআপ (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব ক) ──
    ⋯ মেনুর "WhatsApp-এ পাঠান" / "SMS পাঠান" পপআপটা ঐ পথ বাছা অবস্থায় খোলে (`shared_channel`); "লিংক কপি" কেবল লিংকের
    বাক্স দেখায়, পাশে দুই পথের বোতাম। পপআপে: পথ · ভাষা · নম্বর (পার্টির সব মোবাইল) · লেখা (পথ ধরে) · যা যাবে · SMS-এ অক্ষর ও
    ভাগ · কী ঘটবে · বাতিল / "WhatsApp খুলুন" বা "SMS পাঠান"। বন্ধ পথ ([[PartyMessageDispatcher]]) পপআপে নেই।
--}}
@if (session('shared_link'))
    @php
        $link = session('shared_link');
        $text = trim(($message ?? '').' '.$link);
        $wa = session('shared_share') ? app(\App\Core\Services\PartyMessages::class)->box((int) session('shared_share')) : null;
        $choices = $wa['choices'] ?? [];
        $smsChoices = $wa['sms_choices'] ?? [];
        $roads = $wa === null ? [] : array_values(array_filter([
            ($wa['wame'] ?? false) ? 'whatsapp' : null,
            ($wa['sms'] ?? false) ? 'sms' : null,
        ]));
        $phones = $wa['phones'] ?? [];
        $oldPhone = (string) old('phone', '');
        $paperNo = $wa === null ? '' : (string) ($wa['share']->document_no ?? '');
        $sendWords = [
            'hintWa' => __('notification::party.send.hint_wa'),
            'hintSms' => ($wa['office_phone'] ?? null) !== null
                ? __('notification::party.send.hint_sms', ['phone' => $wa['office_phone']])
                : __('notification::party.send.hint_sms_any'),
            'metaUnicode' => __('notification::party.send.meta_unicode', ['n' => '#n', 'k' => '#k']),
            'metaPlain' => __('notification::party.send.meta_plain', ['n' => '#n', 'k' => '#k']),
            'parts' => __('notification::sms.parts_note', ['n' => '#']),
        ];
        $field = 'h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
        $seg = 'px-3 py-1 text-sm font-semibold text-(--color-ink-muted) aria-pressed:bg-(--color-brand-500) aria-pressed:text-white';
    @endphp

    <div role="status" x-data="sharedLink({ url: @js($link), choices: @js($choices), smsChoices: @js($smsChoices), channel: @js((string) session('shared_channel', '')), roads: @js($roads), locale: @js((string) old('locale', $wa['locale'] ?? 'bn')), templateId: @js((string) old('template_id', '')), words: @js($sendWords) })"
         x-on:keydown.escape.window="close()"
         class="mb-4 rounded-(--radius-card) border border-(--color-brand-500)
                bg-(--color-surface-card) p-3" data-shared-link>
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
            @else
                {{-- ⓘ লিংক কপির পরেও পাঠানো এক চাপে — একই পপআপ, পথ বাছা অবস্থায় --}}
                @if (in_array('whatsapp', $roads, true))
                    <x-ui.button type="button" tone="secondary" x-on:click="show('whatsapp')" data-open-send="whatsapp">
                        <x-ui.icon name="chat" :size="15" class="text-(--color-badge-success-ink)" />{{ __('core.row_menu.send_whatsapp') }}
                    </x-ui.button>
                @endif
                @if (in_array('sms', $roads, true))
                    <x-ui.button type="button" tone="secondary" x-on:click="show('sms')" data-open-send="sms">
                        <x-ui.icon name="sms" :size="15" class="text-(--color-brand-600)" />{{ __('core.row_menu.send_sms') }}
                    </x-ui.button>
                @endif
            @endif
        </div>

        @if ($wa !== null && $roads !== [])
            {{-- ⭐ পার্টিকে পাঠান — পপআপ (নমুনা ট্যাব ক) --}}
            <div x-show="open" x-cloak x-on:click.self="close()" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center"
                 data-send-dialog>
                <div role="dialog" aria-modal="true" aria-labelledby="send-title"
                     class="w-full max-w-[560px] overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                    {{-- ⓘ ফর্মের নিজের ঠিকানা প্রথম খোলা পথের — প্রতিটা বোতাম নিজের ঠিকানায় পাঠায় (formaction) --}}
                    <form method="POST" action="{{ $roads[0] === 'sms' ? route('paper.sms', $wa['share']) : route('paper.whatsapp', $wa['share']) }}"
                          target="{{ $roads[0] === 'sms' ? '_self' : '_blank' }}" data-wa-form>
                        @csrf
                        <input type="hidden" name="locale" x-bind:value="locale" value="{{ old('locale', $wa['locale'] ?? 'bn') }}">

                        <div class="flex items-center gap-2 border-b border-(--color-border) px-3.5 py-2.5">
                            <h2 id="send-title" class="min-w-0 flex-1 truncate text-sm font-bold">{{ __('notification::party.send.title', ['no' => $paperNo]) }}</h2>
                            <button type="button" x-on:click="close()" aria-label="{{ __('notification::party.send.close') }}"
                                    class="grid size-7 place-items-center rounded-(--radius-field) text-(--color-ink-muted) hover:bg-(--color-surface-hover)">
                                <x-ui.icon name="x" :size="16" />
                            </button>
                        </div>

                        <div class="grid gap-2.5 px-3.5 py-3">
                            <div class="flex flex-wrap items-center gap-2.5">
                                @if (count($roads) > 1)
                                    <div role="group" aria-label="{{ __('notification::party.channel') }}" data-send-roads
                                         class="inline-flex overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                                        <button type="button" class="{{ $seg }}" x-on:click="pick('whatsapp')" x-bind:aria-pressed="isWa" data-road="whatsapp">WhatsApp</button>
                                        <button type="button" class="{{ $seg }}" x-on:click="pick('sms')" x-bind:aria-pressed="isSms" data-road="sms">SMS</button>
                                    </div>
                                @else
                                    <span class="text-sm font-semibold" data-send-roads>{{ __('notification::party.channels.'.$roads[0]) }}</span>
                                @endif
                                <span class="flex-1"></span>
                                <div role="group" aria-label="{{ __('notification::party.language') }}"
                                     class="inline-flex overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                                    <button type="button" class="{{ $seg }}" x-on:click="lang('bn')" x-bind:aria-pressed="locale === 'bn'">{{ __('core.print.wa_bn') }}</button>
                                    <button type="button" class="{{ $seg }}" x-on:click="lang('en')" x-bind:aria-pressed="locale === 'en'">{{ __('core.print.wa_en') }}</button>
                                </div>
                            </div>

                            <div class="grid gap-2.5 sm:grid-cols-2">
                                <label class="grid gap-1 text-xs font-semibold text-(--color-ink-muted)">
                                    {{ __('notification::party.phone') }}
                                    @if ($phones !== [])
                                        <select name="phone" required class="{{ $field }} tabular-nums" data-send-phone>
                                            @foreach ($phones as $p)
                                                <option value="{{ $p['shown'] }}" @selected($oldPhone !== '' && \App\Core\Services\PartyMessages::phone($oldPhone) === $p['phone'])>{{ $p['shown'] }}{{ $p['label'] !== '' ? ' · '.$p['label'] : '' }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        {{-- ⓘ পার্টির খাতায় মোবাইল নেই — এখানে লিখে দেওয়া যায় (দেশের 01…, বিদেশের + সহ) --}}
                                        <input type="tel" name="phone" required maxlength="30" inputmode="tel" value="{{ $oldPhone }}" placeholder="01XXXXXXXXX"
                                               class="{{ $field }} tabular-nums" data-send-phone>
                                        <span class="font-normal text-(--color-warning-ink)" data-wa-phone-missing>{{ __('core.print.wa_phone_missing') }}</span>
                                    @endif
                                </label>

                                <label class="grid gap-1 text-xs font-semibold text-(--color-ink-muted)">
                                    {{ __('notification::party.text') }}
                                    <select name="template_id" x-model="templateId" class="{{ $field }}" data-send-template>
                                        <template x-for="choice in options" :key="choice.id">
                                            <option x-bind:value="choice.id" x-bind:selected="isChosen(choice)" x-text="choice.name"></option>
                                        </template>
                                    </select>
                                </label>
                            </div>

                            <p x-show="empty" x-cloak class="text-sm text-(--color-ink-muted)" data-wa-no-template>{{ __('core.print.wa_no_template') }}</p>

                            <div class="grid gap-1 text-xs font-semibold text-(--color-ink-muted)" x-show="! empty">
                                {{ __('core.print.wa_preview') }}
                                <p x-text="preview" data-wa-preview
                                   class="whitespace-pre-wrap rounded-(--radius-field) border border-(--color-border) bg-(--color-badge-success-bg) px-3 py-2.5 text-sm font-normal leading-relaxed text-(--color-ink)">{{ $choices[0]['bn'] ?? '' }}</p>
                            </div>

                            <p x-show="isSms" x-cloak x-text="smsMeta" class="text-xs text-(--color-ink-muted) tabular-nums" data-sms-parts></p>

                            @if (session('sms_queued'))
                                <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)" data-sms-queued>{{ session('sms_queued') }}</p>
                            @endif
                            @if (session('sms_error'))
                                <p role="alert" class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)" data-sms-error>{{ session('sms_error') }}</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2 border-t border-(--color-border) px-3.5 py-2.5">
                            <span class="min-w-0 flex-1 text-xs text-(--color-ink-muted)" x-text="hint" data-send-hint></span>
                            <x-ui.button type="button" tone="secondary" x-on:click="close()">{{ __('notification::party.send.cancel') }}</x-ui.button>
                            @if (in_array('whatsapp', $roads, true))
                                {{-- ⓘ নতুন পাতায় খোলে: খাতায় লিখে সার্ভার wa.me-তে পাঠায়, আর এই পাতাটা যেমন ছিল তেমনই থাকে --}}
                                <x-ui.button type="submit" tone="success" x-show="isWa" x-bind:disabled="empty"
                                             formaction="{{ route('paper.whatsapp', $wa['share']) }}" formtarget="_blank" data-wa-send>
                                    <x-ui.icon name="chat" :size="15" />{{ __('notification::party.send.open_wa') }}
                                </x-ui.button>
                            @endif
                            @if (in_array('sms', $roads, true))
                                {{-- ⭐ অফিসের ফোনের সারিতে ([[PartyMessageDispatcher::viaSms()]]); এই পাতাতেই ফেরে, পপআপ খোলা থাকে --}}
                                <x-ui.button type="submit" tone="primary" x-show="isSms" x-cloak x-bind:disabled="empty"
                                             formaction="{{ route('paper.sms', $wa['share']) }}" formtarget="_self" data-sms-send>
                                    <x-ui.icon name="sms" :size="15" />{{ __('notification::sms.send') }}
                                </x-ui.button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endif
