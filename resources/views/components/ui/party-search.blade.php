@props([
    /* ফর্মে যে নামে মানটা যায় — আগের `<select>`-এর নামই */
    'name',

    /* [[PartyRegistry::forPicker()]]-এর একটা দলের `options`: id, label, hint, find */
    'options' => [],

    /* বাছা id — ডাকার জায়গা `old()` মিলিয়ে দেয় */
    'selected' => null,

    'label' => null,
    'required' => false,

    /*
     * ⭐ জাবেদার সারি (৫ অক্টোবর ২০২৬) — কয়েক ধরনের পক্ষ এক ঘরে, দল ধরে:
     * `groups` = [[PartyRegistry::forPicker()]]-এর পুরো ফল ([type, label, options]); মান তখন "type:id", আগের
     * `<select>`-এর হুবহু। ⓘ `clearable` — ঐচ্ছিক ঘর, মাথায় "—"; `ariaLabel` — লেবেলহীন সারির ঘরের নাম;
     * `wide` — সরু কলামেও খোঁজার প্যানেল অন্তত ১৮rem, যাতে নাম আর কোড কাটা না পড়ে।
     */
    'groups' => null,
    'clearable' => false,
    'ariaLabel' => null,
    'wide' => false,

    /* ⓘ খোঁজার ঘরের লেখা — না দিলে পক্ষের ("নাম, কোড, মোবাইল…"); গ্রাহকের পয়েন্টে নিজের (মালিক, ৬ অক্টোবর ২০২৬) */
    'placeholder' => null,

    /*
     * ⓘ দুই লাইনে — বাছা নাম উপরে, তার নিচে ছোট লাইনে hint (গ্রাহকের পয়েন্ট · শাখা)। সরু ঘরে এক লাইনে লেখা তীরের নিচে কাটত
     * (আদায়ের ১৩৬৬-এর ছবি, ২৪ অক্টোবর ২০২৬); মালিকের ১৯ অক্টোবরের নিয়মে নামের সাথে পয়েন্ট আর শাখা থাকেই।
     */
    'stacked' => false,

    /*
     * ⭐ "লেখা নাম যোগ" — তালিকায় নেই এমন নাম হাতে লেখা (মালিক, ৩ অক্টোবর ২০২৬; রসিদ/পরিশোধের পুরনো পিকারের হুবহু আচরণ, fe আর
     * cb, ২৪ অক্টোবর ২০২৬): খোঁজার প্যানেলে "+ নতুন নাম হিসেবে রাখুন", কিছু না মিললে Enter-ও তাই; নামের পাশে "+"; নিচে নতুন
     * নাম আর মোবাইলের ঘর। ⓘ ঘরের নাম `addName` / `addMobile` — রসিদ/পরিশোধে `party_new` / `party_mobile`, সার্ভার সেখান থেকেই
     * তাঁকে ব্যক্তি বানায় ([[VoucherRequest]] · [[TurnsATypedNameIntoAParty]])। `addMobile` = false দিলে মোবাইলের ঘর নেই — ⛔ null নয়: @props-এর পূর্বনির্ধারিত মান
     * `??` দিয়ে বসে, তাই null দিলে আবার 'party_mobile' হয়ে যেত (cb-ma-2, ২৫ অক্টোবর ২০২৬)।
     */
    'addable' => false,
    'addName' => 'party_new',
    'addMobile' => 'party_mobile',
])

{{--
    খোঁজা যায় এমন পক্ষের তালিকা — লুকানো ঘর + বোতাম + ভাসমান খোঁজার প্যানেল।

    ── ⭐ কেন, ৩ অক্টোবর ২০২৬ ─────────────────────────────────────────────
    মালিক: *"ডেবিট নোট পার্টি সার্চ দেয়ার অপশন নাই"*। নোটের "কাকে" ঘরটা একটা
    লম্বা সাধারণ `<select>` — ইউবি-তে ~৪১৪ জন গ্রাহক, খোঁজার ঘর নেই, আর একই
    নামের দুইটা দোকান ("M/S. Bismillah Store") নাম দেখে আলাদা করা যায় না।

    ⓘ ছাঁচ রসিদ / পরিশোধের নামের ঘর থেকে (b41d5036,
    [[accounts::voucher.partials.party-fields]]): বোতাম → ভাসমান প্যানেল → উপরে
    খোঁজার ঘর → নিচে নাম, আর প্রতিটা নামের নিচে কোড · পয়েন্ট · মোবাইল।
    ⭐ কীবোর্ড: ↑ ↓ সরায়, Enter বাছে, Esc বন্ধ করে; বোতামে কোনো অক্ষর টাইপ
    করলে সরাসরি খোঁজা শুরু। মোবাইলে এক চাপে তালিকা খোলে। যুক্তিটা
    [[party-search.js]]-এ, CSP-Alpine-এর জন্য — টেমপ্লেটে কেবল নাম ডাকা।

    ── ⛔ যা বদলায় না ───────────────────────────────────────────────────────
    ফর্ম আগের নামেই আগের মান পাঠায়, এখন লুকানো ঘরে। ⚠️ খোঁজার ঘরের কোনো
    `name` নেই — থাকলে লেখাটাও ফর্মের সাথে চলে যেত।

    ⓘ লেখাগুলো Accounts-এর অনুবাদ থেকে — রসিদের পিকার আর এই ঘর একই কথা বলে,
    দুই জায়গায় দুই অনুবাদ রাখলে একদিন আলাদা হয়ে যেত।
--}}
@php
    $uid = 'ps-'.trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-').'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(5));

    $rows = $groups === null
        ? collect($options)
            ->map(fn (array $o) => [
                'id' => (int) $o['id'],
                'label' => (string) $o['label'],
                'hint' => (string) ($o['hint'] ?? ''),
                'find' => (string) ($o['find'] ?? ''),
            ])
            ->values()
        : collect($groups)
            ->flatMap(fn (array $g) => collect($g['options'])->map(fn (array $o) => [
                'id' => $g['type'].':'.$o['id'],
                'label' => (string) $o['label'],
                'hint' => (string) ($o['hint'] ?? ''),
                // ⓘ দলের নামেও খোঁজা যায় — "সরবরাহকারী রহিম"
                'find' => mb_strtolower((string) ($o['find'] ?? $o['label']).' '.$g['label']),
                'group' => (string) $g['label'],
            ]))
            ->values();

    $value = $selected === null ? '' : (string) $selected;
    $hasError = $errors->has($name);
    // ⓘ ফেরানো জমায় আগের লেখা নাম খোলা থাকে — ভুলের কারণ ঐ ঘরেই দেখা যায়
    $addingNow = $addable && ($errors->has($addName) || filled(old($addName)));
@endphp

<div {{ $attributes->class('min-w-0') }}
     x-data="partySearch({ value: @js($value), options: @js($rows), uid: @js($uid), clearable: @js((bool) $clearable), addable: @js((bool) $addable), adding: @js($addingNow), newName: @js($addable ? (string) old($addName, '') : '') })">
    @if ($label)
        <span id="{{ $uid }}-label" class="mb-1 block text-sm font-medium">
            {{ $label }}
            @if ($required)
                <span class="text-(--color-danger)" aria-hidden="true">*</span>
                <span class="sr-only">({{ __('core.form.required') }})</span>
            @endif
        </span>
    @endif

    <div @class(['relative', 'flex gap-2' => $addable])
         x-on:click.outside="closeList()"
         x-on:keydown.escape.prevent.stop="escape()">
        <input type="hidden" name="{{ $name }}" value="{{ $value }}"
               x-ref="input" x-bind:value="value">

        {{-- ⓘ নাম আর তার নিচে কোড · পয়েন্ট · মোবাইল — একই নামের দুই দোকান বাছার পরেও আলাদা দেখা যায় --}}
        <button type="button" x-ref="trigger"
                x-on:click="toggleList()"
                x-on:keydown="triggerKey($event)"
                aria-haspopup="listbox"
                x-bind:aria-expanded="listOpen ? 'true' : 'false'"
                {{-- ⓘ নামে লেবেল আর বাছা লেখা দুটোই — কেবল লেবেল দিলে পর্দা-পাঠক "গ্রাহক" পড়ত, কাকে বাছা হলো তা নয় --}}
                @if ($label) aria-labelledby="{{ $uid }}-label {{ $uid }}-picked" @elseif ($ariaLabel) aria-label="{{ $ariaLabel }}" @endif
                @if ($hasError) aria-invalid="true" @endif
                x-bind:title="pickedHint"
                data-party-picker
                @if ($stacked) data-party-stacked @endif
                @class([
                    'flex min-w-0 items-center gap-2 rounded-(--radius-field)
                     border bg-(--color-surface-card) px-3 text-start text-sm',
                    'w-full' => ! $addable,
                    'flex-1' => $addable,
                    'h-(--spacing-field)' => ! $stacked,
                    'min-h-(--spacing-field) py-1' => $stacked,
                    'border-(--color-danger)' => $hasError,
                    'border-(--color-border)' => ! $hasError,
                ])>
            <span id="{{ $uid }}-picked" @class(['min-w-0 flex-1', 'truncate' => ! $stacked, 'flex flex-col leading-tight' => $stacked])>
                <span x-text="pickedLabel" @class(['truncate' => $stacked])>—</span>
                <span @class(['num text-2xs text-(--color-ink-muted)', 'ms-2' => ! $stacked, 'truncate' => $stacked])
                      x-show="hasPickedHint" x-cloak x-text="pickedHint"></span>
            </span>
            <span class="text-(--color-ink-muted)" aria-hidden="true">▾</span>
        </button>

        @if ($addable)
            {{-- ⭐ নামের পাশে "+" — মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"তালিকায় নেই? নাম লিখুন … + bosalei hoy"* --}}
            <x-ui.button type="button" tone="secondary" icon="plus" data-party-add-toggle
                         x-on:click="toggleAdding()"
                         title="{{ __('accounts::field.party_not_listed') }}"
                         aria-label="{{ __('accounts::field.party_not_listed') }}" />
        @endif

        {{-- ⓘ ভাসমান, প্রবাহের ভিতরে নয় — খুললে নিচের ঘরগুলো ঠেলে নামে না। ⚠️ `inset-x-0`
             — ঘরটার সমান চওড়া, তাই ১৯২০×১০৮০-তে ডান কলামে বসেও কিছু কাটা পড়ে না
             (মালিক, ৩ অক্টোবর: *"sob porda 1080p korbe mendetory"*)। --}}
        <div x-show="listOpen" x-cloak
             @class(['absolute inset-x-0 top-full z-30 mt-1 rounded-(--radius-card) border-2 border-(--color-brand-500)
                      bg-(--color-surface-card) p-1.5 text-(--color-ink) shadow-lg', 'min-w-72' => $wide])>
            <input type="search" x-ref="search" x-model="search"
                   x-on:input="searched()"
                   x-on:keydown.arrow-down.prevent="moveDown()"
                   x-on:keydown.arrow-up.prevent="moveUp()"
                   x-on:keydown.enter.prevent="pickCursor()"
                   role="combobox" aria-autocomplete="list" aria-controls="{{ $uid }}-options"
                   x-bind:aria-expanded="listOpen ? 'true' : 'false'"
                   x-bind:aria-activedescendant="activeOption"
                   autocomplete="off" data-party-search
                   placeholder="{{ $placeholder ?? __('accounts::field.party_search') }}"
                   aria-label="{{ $placeholder ?? __('accounts::field.party_search') }}"
                   class="h-(--spacing-field-dense) w-full rounded-(--radius-field)
                          border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">

            <ul id="{{ $uid }}-options" role="listbox" x-ref="list"
                @if ($label) aria-labelledby="{{ $uid }}-label" @endif
                class="mt-1.5 max-h-72 overflow-y-auto">
                <template x-for="(p, i) in shown" :key="p.id">
                    <li role="option" :id="optionId(i)"
                        :aria-selected="isPicked(p) ? 'true' : 'false'"
                        x-on:click="pick(p.id)"
                        x-on:mousemove="hover(i)"
                        :class="isCursor(i) ? 'bg-(--color-surface-hover)' : ''"
                        class="cursor-pointer rounded-(--radius-field) px-2 py-1.5">
                        {{-- ⓘ দলের প্রথম সারির মাথায় দলের নাম (গ্রাহক · সরবরাহকারী · ব্যক্তি · কর্মী) --}}
                        <span class="-mx-2 -mt-1.5 mb-1 block border-b border-(--color-border) px-2 pb-0.5 pt-1.5 text-2xs font-semibold
                                     uppercase text-(--color-ink-muted)"
                              x-show="groupLabel(i) !== ''" x-text="groupLabel(i)" data-party-group></span>
                        <span class="block truncate text-sm"
                              :class="isPicked(p) ? 'font-semibold' : ''"
                              x-text="p.label"></span>
                        <span class="num block truncate text-2xs text-(--color-ink-muted)"
                              x-show="p.hint !== ''" x-text="p.hint"></span>
                    </li>
                </template>

                <li x-show="moreHidden" x-cloak
                    class="px-2 py-1.5 text-2xs text-(--color-ink-muted)">
                    {{ __('accounts::message.party_more') }}
                </li>

                <li x-show="noMatch" x-cloak
                    class="px-2 py-1.5 text-2xs text-(--color-ink-muted)">
                    {{ __('accounts::message.party_no_match') }}
                </li>
            </ul>

            @if ($addable)
                {{-- ⓘ তালিকায় নেই — লেখাটাই নতুন নাম হয়ে নিচের ঘরে বসে; কিছু না মিললে Enter-ও তাই করে --}}
                <button type="button" x-show="canAddTyped" x-cloak data-party-add-typed
                        x-on:click="addTyped()"
                        class="mt-1 w-full truncate rounded-(--radius-field) border border-dashed border-(--color-border)
                               px-2 py-1.5 text-start text-xs hover:bg-(--color-surface-hover)">
                    + {{ __('accounts::field.party_add_typed') }}
                    <b x-text="search"></b>
                </button>
            @endif
        </div>
    </div>

    @error($name)
        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
    @enderror

    @if ($addable)
        {{-- ⓘ নামটা বসে ব্যক্তি হিসেবে (`mdm_people`) — পরের বার তালিকাতেই পাওয়া যায়; একই মানুষ তিন বানানে তিনজন হন না --}}
        {{-- ⓘ নাম আর মোবাইল পাশাপাশি কেবল ঘরের নিজের জায়গায় কুলালে (`@container`, ২০rem = ১৩৬৬-এ ৪০০px) — রসিদ-পরিশোধের সরু পক্ষের
             ঘরে (~২৪০px) পর্দা-মাপের `sm:` দুই কলাম দিত, আর "নতুন দোকান রহিম" কাটা পড়ত, মোবাইলের ঘর আঙুলের মাপও নয় (মাপা, ২৫ অক্টোবর ২০২৬) --}}
        <div class="@container mt-2" x-show="adding" x-cloak data-party-new>
            <div @class(['grid gap-2', '@xs:grid-cols-2' => $addMobile !== false])>
                <x-ui.field :name="$addName" :id="$uid.'-new'"
                            :label="__('accounts::field.party_new_name')"
                            :value="old($addName)"
                            :hint="__('accounts::field.party_new_hint')"
                            x-model="newName" x-on:input="newNameTyped()" />

                @if ($addMobile !== false)
                    <x-ui.field :name="$addMobile" :id="$uid.'-mobile'"
                                :label="__('master_data::field.mobile')"
                                :value="old($addMobile)"
                                x-ref="newMobile" />
                @endif
            </div>
        </div>
    @endif
</div>
