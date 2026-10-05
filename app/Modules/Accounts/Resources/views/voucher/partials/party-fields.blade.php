{{--
    রসিদ ও পরিশোধের পর্দা — নমুনার s-party অংশের হুবহু ক্রমে।

    ── ⛔ কেন নতুন করে লেখা হলো, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────
    মালিক নমুনা আর পর্দা পাশাপাশি রেখে দেখালেন। ঘরগুলো নামে মিলছিল,
    কিন্তু পর্দাটা নমুনার মতো দেখাচ্ছিল না — ক্রম আলাদা, লেবেল আলাদা,
    আর সবচেয়ে বড় অংশটা (কোন বিলের বিপরীতে) একেবারেই ছিল না।

    ⚠️ আগের মাপটা ছিল name="..." গুনে, আর সেটা ভুল জিনিস মাপা: ঘরটা
    পর্দার যেখানেই থাকুক, যে লেবেলেই থাকুক, গোনাটা সবুজ বলত।

    ⭐ মালিকের নিয়ম: ১০০% মানে ১০০%, ৯৯.৯৯%-ও নয়। আর "নমুনাই চূড়ান্ত" —
    নমুনায় নেই এমন ঘর (টাকার শ্রেণি, উপশ্রেণি, কাগজের তারিখ) তুলে
    দেওয়া হয়েছে, তাঁর নিজের সিদ্ধান্তে।

    ── নমুনার ক্রম ─────────────────────────────────────────────────────
        সারি ১   লেনদেনের তারিখ
                 (নমুনায় "কার মাধ্যমে" ও "কখন"-ও ছিল — মালিকের
                 সিদ্ধান্তে বাদ, কারণ কেউ ওগুলো পড়ত না)
        সারি ২   ডিপোজিটরের ধরন · ডিপোজিটরের নাম (পাশে পাওনা)
        ভাঁজ     কোন বিলের বিপরীতে
        চিপ      পেমেন্ট মেথড
        ব্লক     নোটের হিসাব · মোবাইল ব্যাংকিং · ব্যাংক ট্রান্সফার · চেক
        নিচে     যে খাতে জমা · গৃহীত টাকা · বিবরণ
--}}
@php
    $isReceipt = $voucher->type === \App\Modules\Accounts\Models\Voucher::RECEIPT;

    $was = fn (string $field, $fallback = null) => old($field, $voucher->{$field} ?? $fallback);

    /*
     * ⭐ ধরনটা আগে থেকেই বসানো — ২০ সেপ্টেম্বর ২০২৬, মালিক: *"ডিপোজিটরের
     * ধরন আদায় ভাউচার e grahok, payment e supplyer, defolt koro"*।
     *
     * ⓘ টাকা আসে প্রায় সবসময় গ্রাহকের কাছ থেকে, আর যায় সরবরাহকারীর কাছে —
     * তাই ঐ দুইটাই ধরা থাকে, আর অন্য কিছু হলে বদলানো যায়। ⛔ ফাঁকা রাখলে
     * প্রতিটা আদায়ে একটা বাড়তি ক্লিক, আর নামের তালিকাটা ততক্ষণ খালি।
     *
     * ⚠️ কেবল নতুন কাগজে: পুরনো ভাউচার খুললে তার নিজের ধরনই থাকে, আর
     * ভুল জমার পরে old() যা ছিল তাই ফেরে।
     */
    $partyDefault = $isReceipt ? 'customer' : 'supplier';

    $knownTypes = collect($parties)->pluck('type')->all();

    $partyType = (string) ($was('party_type') ?? '');

    if ($partyType === '' && in_array($partyDefault, $knownTypes, true)) {
        $partyType = $partyDefault;
    }
@endphp

<section data-boxed
         class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
         x-data="partyVoucher({
             partyType: @js($partyType),
             partyId: @js((string) ($was('party_id') ?? '')),
             parties: @js(collect($parties)->flatMap(fn (array $g) => collect($g['options'])
                 ->map(fn (array $o) => ['type' => $g['type'], 'id' => (int) $o['id'], 'label' => $o['label'],
                     'hint' => $o['hint'] ?? '', 'find' => $o['find'] ?? '']))
                 ->values()),
             dueUrl: @js(route('accounts.voucher.due')),
             // ⓘ কোন বিলের বিপরীতে — একটাই (অডিট ম১); অন্য মডিউলের আগাম-ভরা "বিপরীতে"-ও এখান দিয়েই যায়
             pickedType: @js((string) ($was('against_type') ?? '')),
             pickedId: @js((string) ($was('against_id') ?? '')),
             adding: @js($errors->has('party_new') || filled(old('party_new'))),
             newName: @js((string) old('party_new', '')),
             texts: @js([
                 'owed' => __('accounts::field.owed'),
                 'overDue' => __('accounts::message.more_than_the_bill_owes'),
                 'noBills' => __('accounts::message.no_open_bill'),
             ]),
         })">

    {{--
        ── সারি ১ — তারিখ · কখন · ডিপোজিটরের ধরন · ডিপোজিটরের নাম ───────

        ⭐ চারটা ঘর এক লাইনে — মালিকের নির্দেশ, ১৮ সেপ্টেম্বর ২০২৬।
        তিনি পর্দার ছবিতে লিখে দিলেন: *"লেনদেনের তারিখ, কখন,
        ডিপোজিটরের ধরন, ডিপোজিটরের নাম egulo ek line daw"*।

        ⓘ আগে দুই সারি ছিল (তিন ঘর + দুই ঘর), আর মাঝখানে "কার মাধ্যমে"।
        ⚠️ সেটা এখন নিচের সারিতে, বিবরণের ঠিক আগে — মালিকের নির্দেশেই:
        *"কার মাধ্যমে ei boxta বিবরণ er age niye aso"*।

        ⛔ ঘরটা এখান থেকে **মুছে** ফেলতে হয়েছে, কেবল সরানো নয় — একই
        নামের দুইটা সক্রিয় ঘর থাকলে ব্রাউজার শেষেরটার মান পাঠাত, আর
        উপরের বাছাইটা নীরবে হারাত।
    --}}
    <div class="grid gap-3 sm:grid-cols-4">
        <x-ui.field name="trx_date" type="date"
                    :label="__('accounts::field.trx_date_long')"
                    :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                    required />

        <x-ui.field name="moved_at" type="time"
                    :label="__('accounts::field.moved_at')"
                    :value="$was('moved_at')" />


        {{--
            ⛔ "কার মাধ্যমে" ও "কখন" — মালিকের সিদ্ধান্তে বাদ, ১৫ সেপ্টেম্বর ২০২৬।

            ── কেন নমুনায় থাকা সত্ত্বেও ─────────────────────────────────
            গুনে দেখা গেল ঘর দুইটা **কেউ পড়ে না**: মাইগ্রেশন, fillable,
            ভ্যালিডেশন আর ফর্ম ছাড়া একটাও পর্দা, রিপোর্ট বা কোয়েরি
            ওগুলো ছোঁয় না।

            ⚠️ অর্থাৎ ওগুলো কেবল টাইপ করার খরচ ছিল, কোনো প্রশ্নের উত্তর
            দিত না। ⓘ মালিককে তিনটা পথ দেখানো হয় — রেখে পড়ার ব্যবস্থা
            করা, কেবল বাহকটা রাখা, বা দুইটাই বাদ — আর তিনি বাদ বেছেছেন।

            ⭐ কলাম দুইটা থেকে যাচ্ছে, মুছছে না: কন্ট্রা ভাউচারে
            "কে হাতে নিয়ে গেল" ঘরটা ঐ একই `carried_by` ব্যবহার করে, আর
            নমুনায় সেটা আছে।
        --}}

        {{--
            ⛔ `<x-ui.select>` ভিতরের `<option>` পড়ে না — ১৫ সেপ্টেম্বর ২০২৬।

            কম্পোনেন্টটা তালিকাটা `:options` প্রপ থেকে নেয়, আর স্লটে লেখা
            `<option>`গুলো **চুপচাপ ফেলে দেয়**।

            ⚠️ ফল: ড্রপডাউনটা পর্দায় থাকত, লেবেলও থাকত, কিন্তু **ভিতরে
            একটাও অপশন নেই** — ব্যবহারকারী ধরনটাই বাছতে পারতেন না, আর
            নামের ঘরটাও ভরত না (সে ধরনের উপর দাঁড়িয়ে)।

            ⛔ আমার গোনার পাহারা এটা ধরেনি: সে `name="party_type"` খুঁজত,
            আর ঘরটা সত্যিই ছিল। ⓘ **অপশন গোনা হয়নি।** মালিক পর্দা দেখে
            ধরিয়ে দেন।
        --}}
        <x-ui.select name="party_type"
                     :label="$isReceipt ? __('accounts::field.depositor_type') : __('accounts::field.payee_type')"
                     :options="collect($parties)->pluck('label', 'type')->all()"
                     :selected="$partyType"
                     x-model="partyType" x-on:change="resetParty()" />

        {{-- ⓘ `adding` এখন [[party-voucher.js]]-এ, আলাদা ছোট x-data-য় নয় — খোঁজার
             তালিকা থেকে "নতুন নাম হিসেবে যোগ করুন" চাপলে "+" ঘরটা খুলতে হয়, আর
             দুইটা আলাদা x-data একে অন্যের অবস্থা দেখে না। --}}
        <div>
            <div class="mb-1 flex items-baseline justify-between gap-2">
                <span class="text-sm font-medium">
                    {{ $isReceipt ? __('accounts::field.depositor_name') : __('accounts::field.payee_name_party') }}
                </span>

                {{-- পাওনা — নামের পাশে, রঙিন পটভূমিতে।

                     ⭐ মালিক, ২০ সেপ্টেম্বর ২০২৬: *"পাওনা -280.56 brafground e
                     ekta color daw"*। ⓘ ধূসর ছোট লেখায় সংখ্যাটা চোখ এড়িয়ে যেত,
                     অথচ টাকা নেওয়ার আগে এটাই দেখার জিনিস।

                     ⚠️ রং দুই রকম, আর অর্থও দুই রকম: ধনাত্মক মানে তিনি দেবেন
                     (লাল), ঋণাত্মক মানে তাঁর টাকা আমাদের কাছে জমা আছে (সবুজ) —
                     ⛔ ঋণাত্মক পাওনা দেখে আরেকবার টাকা চাইলে সেটা ভুল আদায়।
                     ⓘ রং একা কিছু বলে না, তাই লেখাটাও সাথে থাকে। --}}
                <span class="rounded-(--radius-pill) px-2 py-0.5 text-xs" x-show="due !== null" x-cloak
                      x-bind:class="due > 0
                          ? 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)'
                          : 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'">
                    <span x-text="texts.owed"></span>
                    <b class="num ms-1" x-text="due"></b>
                </span>
            </div>

            {{-- ⓘ `aria-label` — ঘরটার নিজের `<label for>` নেই, কারণ নামটা
                 উপরের সারিতে পাওনার সাথে এক লাইনে বসে। ⚠️ চোখে নামটা
                 দেখা যায়, কিন্তু স্ক্রিন-রিডার কেবল "combo box" বলত। --}}
            {{-- ⭐ নামের পাশে "+" — ১৯ সেপ্টেম্বর ২০২৬, মালিক: *"তালিকায় নেই? নাম লিখুন
                 eta ডিপোজিটরের নাম er box er pase + bosalei hoy"*। ⓘ চাপলে নিচে নতুন
                 নাম আর মোবাইলের ঘর খোলে; আবার চাপলে বন্ধ। --}}
            {{--
                ── ⭐ খোঁজা যায় এমন নামের তালিকা — ৩ অক্টোবর ২০২৬ ─────────────

                মালিকের অভিযোগ: রসিদের "ডিপোজিটরের নাম" আর পরিশোধের "প্রাপকের
                নাম" একটা লম্বা সাধারণ `<select>` — খোঁজার ঘর নেই, আর ইউবি-র ৪১৪
                জন গ্রাহকের ভিতরে *নাম খুঁজে পাওয়া যায় না*। ⚠️ একই নামের দুইটা
                দোকানও ছিল (দুইটা "M/S. Bismillah Store"), আর তালিকা তাঁদের আলাদা
                করার কোনো উপায় দিত না।

                ⓘ ছাঁচ কাউন্টারের ক্রেতা বাছাই থেকে ([[sales::direct.partials.party]]):
                বোতাম → ভাসমান প্যানেল → উপরে খোঁজার ঘর → নিচে নাম, আর প্রতিটা
                নামের নিচে কোড · পয়েন্ট · মোবাইল। ⭐ কীবোর্ড: ↑ ↓ সরায়, Enter বাছে,
                Esc বন্ধ করে; বোতামে কোনো অক্ষর টাইপ করলে সরাসরি খোঁজা শুরু।
                মোবাইলে এক চাপে তালিকা খোলে, খোঁজার ঘর উপরে।

                ⛔ সার্ভারের দিকে কিছু বদলায়নি: `party_id` আগের নামেই যায়, এখন
                লুকানো ঘরে। ⚠️ খোঁজার ঘরের কোনো `name` নেই — থাকলে লেখাটাও ফর্মের
                সাথে চলে যেত।
            --}}
            <div class="relative flex gap-2"
                 x-on:click.outside="closeList()"
                 x-on:keydown.escape.prevent.stop="escape()">
                <input type="hidden" name="party_id"
                       value="{{ (string) ($was('party_id') ?? '') }}"
                       x-bind:value="partyId">

                <button type="button" x-ref="trigger"
                        x-on:click="toggleList()"
                        x-on:keydown="triggerKey($event)"
                        aria-haspopup="listbox"
                        x-bind:aria-expanded="listOpen ? 'true' : 'false'"
                        aria-label="{{ __('accounts::field.party') }}"
                        x-bind:title="pickedHint"
                        data-party-picker
                        class="flex h-(--spacing-field) min-w-0 flex-1 items-center gap-2 rounded-(--radius-field)
                               border border-(--color-border) bg-(--color-surface-card) px-3 text-start">
                    {{-- ⓘ কোড · পয়েন্ট বোতামে কেবল `title`-এ: ঘরটা সারির এক-চতুর্থাংশ, আর
                         পাশে বসালে নামটাই কেটে যেত — নামই এখানে আসল কথা। --}}
                    <span class="min-w-0 flex-1 truncate" x-text="pickedLabel">—</span>
                    <span class="text-(--color-ink-muted)" aria-hidden="true">▾</span>
                </button>

                <x-ui.button type="button" tone="secondary" icon="plus"
                             x-on:click="toggleAdding()"
                             title="{{ __('accounts::field.party_not_listed') }}"
                             aria-label="{{ __('accounts::field.party_not_listed') }}" />

                {{-- ⓘ ভাসমান, প্রবাহের ভিতরে নয় — কাউন্টারের একই পাঠ: জায়গা দখল করলে
                     খুলতেই নিচের সব ঘর ঠেলে নামত। ⚠️ `inset-x-0` — ঘরটার সমান চওড়া,
                     তাই ১৯২০×১০৮০-তে চতুর্থ কলামে বসেও ডান দিকে কাটা পড়ে না। --}}
                <div x-show="listOpen" x-cloak
                     class="absolute inset-x-0 top-full z-30 mt-1 rounded-(--radius-card)
                            border-2 border-(--color-brand-500) bg-(--color-surface-card)
                            p-1.5 text-(--color-ink) shadow-lg">
                    <input type="search" x-ref="search" x-model="search"
                           x-on:input="searched()"
                           x-on:keydown.arrow-down.prevent="moveDown()"
                           x-on:keydown.arrow-up.prevent="moveUp()"
                           x-on:keydown.enter.prevent="pickCursor()"
                           role="combobox" aria-autocomplete="list" aria-controls="party-options"
                           x-bind:aria-expanded="listOpen ? 'true' : 'false'"
                           x-bind:aria-activedescendant="activeOption"
                           autocomplete="off" data-party-search
                           placeholder="{{ __('accounts::field.party_search') }}"
                           aria-label="{{ __('accounts::field.party_search') }}"
                           class="h-(--spacing-field-dense) w-full rounded-(--radius-field)
                                  border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">

                    <ul id="party-options" role="listbox" x-ref="list"
                        aria-label="{{ __('accounts::field.party') }}"
                        class="mt-1.5 max-h-72 overflow-y-auto">
                        <template x-for="(p, i) in shown" :key="p.id">
                            <li role="option" :id="'party-opt-' + i"
                                :aria-selected="isPicked(p) ? 'true' : 'false'"
                                x-on:click="pickParty(p.id)"
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

                    {{-- ⭐ তালিকায় নেই — লেখাটাই নতুন নাম হয়ে "+" ঘরে বসে, সমন্বয়কের মাধ্যমে
                         মালিকের চাওয়া (৩ অক্টোবর ২০২৬)। ⓘ কিছু না মিললে Enter-ও তাই করে। --}}
                    <button type="button" x-show="hasSearch" x-cloak
                            x-on:click="addTyped()"
                            class="mt-1 w-full truncate rounded-(--radius-field) border border-dashed
                                   border-(--color-border) px-2 py-1.5 text-start text-xs
                                   hover:bg-(--color-surface-hover)">
                        + {{ __('accounts::field.party_add_typed') }}
                        <b x-text="search"></b>
                    </button>
                </div>
            </div>

            @error('party_id')
                <span class="mt-1 block text-2xs text-(--color-danger)">{{ $message }}</span>
            @enderror

            {{-- ⭐ তালিকায় নেই? নাম লিখুন — ১৮ সেপ্টেম্বর ২০২৬।

                 ── ⛔ কেন দরকার ────────────────────────────────────────
                 মালিকের কথা: *"ডিপোজিটরের নাম / প্রাপকের নাম হাতে লিখতে
                 পারতে হবে।"* ⚠️ এতদিন ঘরটা কেবল ড্রপডাউন ছিল, তাই
                 কাউন্টারে একজন অচেনা লোক টাকা দিয়ে গেলে **রসিদই কাটা
                 যেত না** — আগে মাস্টার ডেটায় গিয়ে তাঁকে বসিয়ে আসতে হত।

                 ── ⚠️ কেন পঞ্চম একটা "অন্যান্য" ধরন বানানো হয়নি ────────
                 ⛔ তাহলে ঐ নামগুলো কোনো তালিকায় থাকত না, পরের বার আবার
                 টাইপ করতে হত, আর একই মানুষ তিন বানানে তিনজন হয়ে যেতেন।

                 ⭐ বদলে নামটা **ব্যক্তি** হিসেবে বসে (`mdm_people`) —
                 মালিকের নিজের নির্দেশে ওখানেই ঋণদাতা, বাড়িওয়ালা, বাহক
                 সবাই থাকেন। ⓘ তাই পরের বার নামটা তালিকাতেই পাওয়া যায়।

                 ⓘ ছাঁচটা Finance-এর [[finance::components.person-picker]]
                 থেকে নেওয়া, আর পিছনে একই [[PersonResolver]]। --}}
            <div class="mt-2" x-show="adding" x-cloak>
                <div class="grid gap-2 sm:grid-cols-2">
                    {{-- ⓘ `x-model` — তালিকা থেকে "নতুন নাম হিসেবে যোগ করুন" চাপলে লেখাটা
                         এখানে বসে, আর এখানে লিখলে বোতামেও "+ নাম" দেখা যায়। --}}
                    <x-ui.field name="party_new"
                                :label="__('accounts::field.party_new_name')"
                                :value="old('party_new')"
                                :hint="__('accounts::field.party_new_hint')"
                                x-model="newName" x-on:input="newNameTyped()" />

                    <x-ui.field name="party_mobile"
                                :label="__('master_data::field.mobile')"
                                :value="old('party_mobile')"
                                x-ref="newMobile" />
                </div>
            </div>
        </div>
    </div>

    {{--
        ── কোন খাতের টাকা — গ্রাহক ছাড়া অন্য কারও কাছ থেকে রসিদ ────────
        ⛔ কী ঘটছিল, ১৯ সেপ্টেম্বর ২০২৬: মালিক একজন ব্যক্তির কাছ থেকে
        মূলধনের রসিদ কাটতে গেলেন, আর পর্দা বলল *"যে খাত থেকে দিতেই
        হবে"* — অথচ ঐ ঘরটা পর্দায় কোথাও নেই। তিনি জিজ্ঞেস করলেন:
        *"eta capital entry nicchena keno"*।

        ⓘ দুইটা ঠিক সিদ্ধান্ত একসাথে ভুল ফল দিচ্ছিল:
        ১. নমুনা মেনে রসিদে "কার কাছ থেকে" খাতের ঘরটা লুকানো ও নিষ্ক্রিয়
           (উপরে ডিপোজিটরই সেটা বলে)।
        ২. 5e7d508c: পক্ষ থেকে খাত আন্দাজ কেবল গ্রাহকের বেলায় (→ AR),
           কারণ আগে ব্যক্তির টাকাও AR-এ বসত — ২৫ লাখের মূলধনটা ঠিক তাই।
        ⛔ ফল: গ্রাহক ছাড়া কারও কাছ থেকে **কোনো রসিদই** কাটা যেত না।

        ⭐ তাই ডিপোজিটর গ্রাহক না হলে ঘরটা এখানে দেখা দেয়। গ্রাহক হলে
        লুকানো ও নিষ্ক্রিয়, তাই আগের মতোই AR নিজে বসে, আর আগে বাছা
        কোনো খাত ভুল করে জমা পড়ে না।
    --}}
    @if ($isReceipt)
        <div class="mt-3 max-w-xl" x-show="partyType !== '' && partyType !== 'customer'" x-cloak>
            <label class="block">
                <span class="mb-1 block text-sm font-medium">
                    {{ __('accounts::field.received_on_account') }}
                    <span class="text-(--color-danger)" aria-hidden="true">*</span>
                </span>
                @php
                    /*
                        যাঁরা আগে মূলধন দিয়েছেন — তাঁদের বাছলে ঘরটায় মূলধন (3100) নিজে বসে।

                        ⚠️ ২১ সেপ্টেম্বর ২০২৬: আগে এখানে সরাসরি Finance-এর মডেল ডাকা হত,
                        আর তাতে নির্ভরতার তীরটা উল্টো ছিল — Finance accounts চেনে, উল্টোটা
                        নয়। ⓘ এখন চুক্তিটা জিজ্ঞেস করা হয়; কে উত্তর দিচ্ছে, দিচ্ছে কি না,
                        এই পর্দা কিছুই জানে না ([[KnowsWhereAPersonsMoneyBelongs]])।
                    */
                    $contributors = app(\App\Core\Contracts\KnowsWhereAPersonsMoneyBelongs::class)->peopleItKnowsAbout();
                    $capitalAccount = (string) \App\Modules\Accounts\Models\Account::query()
                        ->where('code', \App\Modules\Accounts\Services\StandardChart::OWNER_CAPITAL)->value('id');

                    /*
                     * ⛔ "কী বাবদ" প্রশ্নে পুরো হিসাবের তালিকা নয় — ১৯ সেপ্টেম্বর ২০২৬,
                     * মালিক: *"'টাকাটা কী বাবদ' zodi hoy tahole full hisab talika
                     * dewa keno"*। ⓘ নগদ, ব্যাংক, মজুদ হলো টাকা **রাখার** জায়গা, আসার
                     * কারণ নয়; গ্রাহক-সরবরাহকারীর বাকি তাঁদের নিজের পথে আসে; খরচের
                     * খাতে টাকা ঢোকে না। ⭐ থাকে চার দল, মূলধন আগে:
                     *   মূলধন · ঋণ ও দায় (সরবরাহকারীর বাকি বাদে) · আয় ·
                     *   ফেরত পাওয়া (অগ্রিম, জামানত, দাবি, বিনিয়োগ, দেওয়া হাতধার — ১১৩০–১১৭০)
                     */
                    $returnable = ['113', '114', '115', '116', '117'];
                    $pool = collect($optionsFor('party_or_income'))
                        ->filter(fn ($a) => $a->money_kind === null);
                    $sourceGroups = array_filter([
                        __('accounts::field.source_equity') => $pool->where('type', \App\Modules\Accounts\Models\Account::EQUITY),
                        __('accounts::field.source_liability') => $pool->where('type', \App\Modules\Accounts\Models\Account::LIABILITY)
                            ->reject(fn ($a) => str_starts_with((string) $a->code, \App\Modules\Accounts\Services\StandardChart::PAYABLE_GROUP)),
                        __('accounts::field.source_income') => $pool->where('type', \App\Modules\Accounts\Models\Account::INCOME),
                        __('accounts::field.source_returned') => $pool->where('type', \App\Modules\Accounts\Models\Account::ASSET)
                            ->filter(fn ($a) => in_array(substr((string) $a->code, 0, 3), $returnable, true)),
                    ], fn ($group) => $group->isNotEmpty());
                @endphp
                <select name="from_account_id" x-bind:disabled="partyType === '' || partyType === 'customer'"
                        x-bind:value="partyType === 'person' && @js($contributors).includes(partyId) ? @js($capitalAccount) : $el.value"
                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                               border-(--color-border) bg-(--color-surface-card) px-3">
                    <option value="">&mdash;</option>
                    @foreach ($sourceGroups as $groupLabel => $accounts)
                        <optgroup label="{{ $groupLabel }}">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('from_account_id') == $account->id)>
                                    {{ $account->label() }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('accounts::message.received_on_account_hint') }}</p>
        </div>
    @endif

    {{--
        ── কোন বিলের বিপরীতে ──────────────────────────────────────────
        ⭐ রসিদের সবচেয়ে বড় প্রশ্ন, আর এতদিন পর্দায় ছিলই না।

        ⚠️ মোট বকেয়া জানা আর কোন বিলের বিপরীতে জানা এক নয়। ⓘ না জানলে
        পুরনো বিলটা চিরকাল খোলা থাকত আর নতুনটা শোধ দেখাত — টাকাটা একই,
        অথচ বয়স ধরে বকেয়ার তালিকা মিথ্যা বলত।

        ⓘ ভাঁজটা নিজে থেকেই খোলে যখন পক্ষ বাছা হয় আর বিল আছে।
    --}}
    {{-- ⓘ বাছা বিল রসিদের "বিপরীতে" ঘরে — বাছা না থাকলে ঘর দুটো যায়ই না --}}
    <input type="hidden" name="against_type" x-bind:value="pickedType" x-bind:disabled="pickedId === ''">
    <input type="hidden" name="against_id" x-bind:value="pickedId" x-bind:disabled="pickedId === ''">

    {{--
        ⭐ একটা বিলই বাছা যায় — Accounts-Finance অডিট ম১, ৪ অক্টোবর ২০২৬।
        ⛔ আগে প্রতিটা বিলে টিক আর অঙ্কের ঘর ছিল, অথচ সার্ভার ভাগগুলো কোথাও রাখত না — আর তালিকাটাই সবসময় খালি আসত।
        ⓘ এক টাকায় বহু বিল আর "পুরনো বিল আগে" বিক্রয়ের "আদায়" পর্দায়; এখানে বাছা বিলের অঙ্ক, পক্ষ আর খোলা থাকা
        পোস্টের মুহূর্তে মাপা হয় ([[VoucherService::assertAgainstFits()]])।
    --}}
    <details class="mt-4 rounded-(--radius-card) border border-(--color-border) p-3"
             x-bind:open="bills.length > 0">
        <summary class="cursor-pointer text-sm font-semibold">
            {{ __('accounts::field.against_which_invoice') }}
            <span class="ms-2 text-xs font-normal text-(--color-ink-muted)"
                  x-show="pickedBill" x-cloak x-text="pickedBill ? pickedBill.no : ''"></span>
        </summary>

        <template x-if="bills.length === 0">
            <p class="mt-3 rounded-(--radius-field) bg-(--color-surface-app) p-3 text-sm text-(--color-ink-muted)"
               x-text="texts.noBills"></p>
        </template>

        <template x-if="bills.length > 0">
            <div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-(--color-ink-muted)">
                            <tr class="border-b border-(--color-border)">
                                <th class="w-8"></th>
                                <th class="p-2 text-start">{{ __('accounts::field.invoice') }}</th>
                                <th class="p-2 text-start">{{ __('accounts::field.date') }}</th>
                                <th class="p-2 text-end">{{ __('accounts::field.age') }}</th>
                                <th class="p-2 text-end">{{ __('accounts::field.outstanding') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="b in bills" :key="b.against_type + '-' + b.id">
                                <tr class="border-b border-(--color-border)">
                                    <td class="p-2">
                                        <input type="radio" name="bill_pick"
                                               :aria-label="b.no"
                                               :checked="String(b.id) === pickedId && b.against_type === pickedType"
                                               x-on:change="pick(b)">
                                    </td>
                                    <td class="p-2 font-medium" x-text="b.no"></td>
                                    <td class="p-2" x-text="b.date"></td>
                                    <td class="num p-2 text-end" x-text="b.age"></td>
                                    <td class="num p-2 text-end" x-text="$fixed(b.outstanding)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" x-show="pickedId !== ''" x-cloak
                            class="rounded-full border border-(--color-border) px-3 py-1 text-xs"
                            x-on:click="unpick()">
                        {{ __('accounts::field.no_bill') }}
                    </button>
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::message.many_bills_on_collection') }}</span>
                </div>

                {{-- ⚠️ গৃহীত টাকা বাছা বিলের বাকির চেয়ে বেশি — পোস্টে সার্ভার থামাবে, আগেই বলা --}}
                <p class="mt-1 text-xs text-(--color-danger)" x-show="overDue" x-cloak x-text="texts.overDue"></p>
            </div>
        </template>
    </details>
</section>
