@props([
    /* ফর্মে যে নামে মানটা যায় — আগের `<select>`-এর নামই */
    'name',

    /* [[PartyRegistry::forPicker()]]-এর একটা দলের `options`: id, label, hint, find */
    'options' => [],

    /* বাছা id — ডাকার জায়গা `old()` মিলিয়ে দেয় */
    'selected' => null,

    'label' => null,
    'required' => false,
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

    $rows = collect($options)
        ->map(fn (array $o) => [
            'id' => (int) $o['id'],
            'label' => (string) $o['label'],
            'hint' => (string) ($o['hint'] ?? ''),
            'find' => (string) ($o['find'] ?? ''),
        ])
        ->values();

    $value = $selected === null ? '' : (string) $selected;
    $hasError = $errors->has($name);
@endphp

<div {{ $attributes->class('min-w-0') }}
     x-data="partySearch({ value: @js($value), options: @js($rows), uid: @js($uid) })">
    @if ($label)
        <span id="{{ $uid }}-label" class="mb-1 block text-sm font-medium">
            {{ $label }}
            @if ($required)
                <span class="text-(--color-danger)" aria-hidden="true">*</span>
                <span class="sr-only">({{ __('core.form.required') }})</span>
            @endif
        </span>
    @endif

    <div class="relative"
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
                @if ($label) aria-labelledby="{{ $uid }}-label" @endif
                @if ($hasError) aria-invalid="true" @endif
                x-bind:title="pickedHint"
                data-party-picker
                @class([
                    'flex h-(--spacing-field) w-full min-w-0 items-center gap-2 rounded-(--radius-field)
                     border bg-(--color-surface-card) px-3 text-start text-sm',
                    'border-(--color-danger)' => $hasError,
                    'border-(--color-border)' => ! $hasError,
                ])>
            <span class="min-w-0 flex-1 truncate">
                <span x-text="pickedLabel">—</span>
                <span class="num ms-2 text-2xs text-(--color-ink-muted)"
                      x-show="hasPickedHint" x-cloak x-text="pickedHint"></span>
            </span>
            <span class="text-(--color-ink-muted)" aria-hidden="true">▾</span>
        </button>

        {{-- ⓘ ভাসমান, প্রবাহের ভিতরে নয় — খুললে নিচের ঘরগুলো ঠেলে নামে না। ⚠️ `inset-x-0`
             — ঘরটার সমান চওড়া, তাই ১৯২০×১০৮০-তে ডান কলামে বসেও কিছু কাটা পড়ে না
             (মালিক, ৩ অক্টোবর: *"sob porda 1080p korbe mendetory"*)। --}}
        <div x-show="listOpen" x-cloak
             class="absolute inset-x-0 top-full z-30 mt-1 rounded-(--radius-card)
                    border-2 border-(--color-brand-500) bg-(--color-surface-card)
                    p-1.5 text-(--color-ink) shadow-lg">
            <input type="search" x-ref="search" x-model="search"
                   x-on:input="searched()"
                   x-on:keydown.arrow-down.prevent="moveDown()"
                   x-on:keydown.arrow-up.prevent="moveUp()"
                   x-on:keydown.enter.prevent="pickCursor()"
                   role="combobox" aria-autocomplete="list" aria-controls="{{ $uid }}-options"
                   x-bind:aria-expanded="listOpen ? 'true' : 'false'"
                   x-bind:aria-activedescendant="activeOption"
                   autocomplete="off" data-party-search
                   placeholder="{{ __('accounts::field.party_search') }}"
                   aria-label="{{ __('accounts::field.party_search') }}"
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
        </div>
    </div>

    @error($name)
        <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
    @enderror
</div>
