{{--
    লগইনের ফর্ম — **দুইটা দরজার একটাই ফর্ম**।

    ── কেন আলাদা ফাইল (২ সেপ্টেম্বর ২০২৬) ───────────────────────────────
    ABOS-এর এখন দুইটা প্রবেশপথ: `/login` (পূর্ণ ব্র্যান্ড প্যানেলসহ) আর
    `/signin` (শান্ত, এক কলাম)। দুইটাতেই একই ঘর, একই যাচাই, একই MFA।

    কপি করে দুই জায়গায় রাখলে **একদিন দুইটা আলাদা হয়ে যেত** — কেউ
    একটাতে `autocomplete` ঠিক করত, অন্যটায় নয়; কেউ MFA-র ঘরটা একটাতে
    যোগ করত, অন্যটায় ভুলে যেত। আর ভুলটা ধরা পড়ত কেবল যে দরজাটা কম
    ব্যবহার হয় সেখানে, অর্থাৎ দেরিতে।

    ── এখানে কী নেই, আর কেন ─────────────────────────────────────────────
    জমিন, লোগো, শিরোনাম, পাদটীকা — কিছুই নেই। ওগুলো **দরজার**, ফর্মের
    নয়। দুইটা দরজা আলাদা দেখতে হবে বলেই তো দুইটা।
--}}
                @if ($errors->any())
                    <div role="alert"
                         class="mt-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                text-sm text-(--color-badge-danger-ink)">
                        {{ $errors->first() }}
                    </div>
                @endif

                {{-- ডাবল ক্লিক ঠেকানো form-এর submit ইভেন্টে, বোতামে নয়।

                     আগে বোতামে :disabled="busy" ছিল, আর Alpine ক্লিকের
                     মুহূর্তেই সেটা প্রয়োগ করত — নিষ্ক্রিয় বোতাম ফর্ম সাবমিট
                     করে না, তাই লগইন কাজই করত না। ব্রাউজারে চালিয়ে দেখতে
                     গিয়ে ধরা পড়েছে; কোড পড়ে ধরা পড়ত না। --}}
                <form method="POST" action="{{ route('login.store') }}"
                      x-data="{ busy: false }"
                      @submit="busy ? $event.preventDefault() : (busy = true)"
                      class="mt-6 space-y-4">
                    @csrf

                    <div>
                        <label for="identifier" class="mb-1 block text-sm font-medium text-(--color-ink)">
                            {{ __('auth.identifier') }}
                        </label>
                        {{-- autocomplete ছাড়া "Password Manager Support" কথাটা
                             লেখা থাকলেও বাস্তবে কাজ করে না (সেকশন ১৬.৭) --}}
                        <input id="identifier" name="identifier" type="text"
                               value="{{ old('identifier') }}"
                               autocomplete="username" required autofocus
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-3
                                      text-(--color-ink)">
                    </div>

                    <div x-data="{ show: false, caps: false }">
                        <label for="password" class="mb-1 block text-sm font-medium text-(--color-ink)">
                            {{ __('auth.password') }}
                        </label>
                        <div class="relative">
                            <input id="password" name="password"
                                   :type="show ? 'text' : 'password'"
                                   autocomplete="current-password" required
                                   @keyup="caps = $event.getModifierState && $event.getModifierState('CapsLock')"
                                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                          border-(--color-border) bg-(--color-surface-card) px-3 pe-12
                                          text-(--color-ink)">
                            <button type="button" @click="show = !show"
                                    class="absolute inset-y-0 end-0 flex w-12 items-center justify-center
                                           text-(--color-ink-muted)"
                                    :aria-label="show ? '{{ __('auth.hide_password') }}' : '{{ __('auth.show_password') }}'">
                                {{-- দুইটা আলাদা আঁকা, একটা ঘুরিয়ে নয়: কাটা-চোখ
                                     মানে "এখন লুকানো", আর সেটা চোখের উপর একটা
                                     দাগ — ইমোজিতে ওই দ্বিতীয় অবস্থাটা ছিলই না,
                                     তাই বোতামটা চাপার পর কিছু বদলাত না। --}}
                                <span x-show="! show"><x-ui.icon name="eye" :size="18" /></span>
                                <span x-show="show" x-cloak><x-ui.icon name="eye_off" :size="18" /></span>
                            </button>
                        </div>
                        <p x-show="caps" x-cloak
                           class="mt-1 text-xs text-(--color-badge-pending-ink)">
                            {{ __('auth.caps_lock_on') }}
                        </p>
                    </div>

                    {{--
                        দ্বিতীয় ধাপের কোড — কেবল যখন চাওয়া হয়েছে।

                        ── কেন সবসময় দেখানো হয় না ──────────────────
                        বেশিরভাগ ব্যবহারকারীর MFA চালু নেই। ঘরটা
                        সবসময় থাকলে তাঁরা প্রতিবার ভাবতেন কিছু একটা
                        লিখতে হবে, আর খালি রেখে জমা দিয়ে ভুল বার্তা
                        পাওয়ার ভয় পেতেন।

                        ── কেন `autofocus` ────────────────────────
                        দ্বিতীয় ধাপে মানুষ ফোন হাতে নিয়ে দাঁড়ানো —
                        কার্সার ঘরটাতেই থাকা উচিত, নাহলে তিনি ছয়টা
                        অঙ্ক টাইপ করে দেখতেন কোথাও কিছু বসেনি।
                    --}}
                    @if (session('mfa') || $errors->has('code'))
                        <div>
                            <label for="code" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                {{ __('auth.code') }}
                            </label>

                            <input id="code" name="code" type="text"
                                   inputmode="numeric" autocomplete="one-time-code"
                                   autofocus
                                   class="num h-(--spacing-field) w-full rounded-(--radius-field)
                                          border border-(--color-border) bg-(--color-surface-app)
                                          px-3 text-center tracking-[0.3em]">

                            <p class="mt-1 text-xs text-(--color-ink-muted)">
                                {{ __('auth.code_hint') }}
                            </p>

                            @error('code')
                                <p class="mt-1 text-xs text-(--color-danger)">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    {{--
                        মনে-রাখা আর ভুলে-যাওয়া — এক লাইনে, দুই প্রান্তে।

                        ── কেন লিংক নয়, লেখা ────────────────────────
                        পাসওয়ার্ড রিসেট এখনো নেই, আর কারণটা কোডের নয়:
                        `MAIL_MAILER=log` — এই মেশিনে আর লাইভ **দুই
                        জায়গাতেই**। ABOS থেকে আজ পর্যন্ত একটাও ইমেইল
                        বাইরে যায় না।

                        তাই `<a>` বসানো হয়নি। বসালে মানুষ চাপ দিতেন,
                        "ইমেইল পাঠানো হয়েছে" পড়তেন, আর ইনবক্স খুলে
                        অপেক্ষা করতেন যে চিঠি কোনোদিন আসবে না।
                        **কথাটা সৎভাবে বলে রাখা ভালো।**

                        ── কেন flex-wrap ────────────────────────────
                        বাংলায় "এই ডিভাইসটি মনে রাখুন" + "পাসওয়ার্ড
                        ভুলে গেছেন?" + ব্যাজ ৩৭৫px-এ এক লাইনে ধরে না।
                        wrap থাকলে ব্যাজটা নিচে নেমে যায়; `ms-auto`
                        দুই অবস্থাতেই ওটাকে ডান প্রান্তে রাখে।
                    --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                            <input type="checkbox" name="remember" value="1" class="size-4">
                            {{ __('auth.remember_device') }}
                        </label>

                        <span class="ms-auto flex shrink-0 items-center gap-1.5 text-xs
                                     text-(--color-ink-muted)"
                              aria-disabled="true">
                            {{ __('auth.forgot_password') }}

                            <span class="rounded-full border border-(--color-border)
                                         bg-(--color-surface-muted) px-1.5 py-px text-2xs">
                                {{ __('auth.coming_soon') }}
                            </span>
                        </span>
                    </div>

                    {{-- দ্বিতীয় ক্লিক pointer-events দিয়ে আটকানো, disabled
                         দিয়ে নয় — নাহলে প্রথম ক্লিকটাও হারিয়ে যায়
                         (সেকশন ১৬.৩)। --}}
                    {{--
                        ৬০০, ৫০০ নয় — আর কালিটাও রঙের নিজের।

                        ── কী ভাঙা ছিল (২ সেপ্টেম্বর ২০২৬) ──────────
                        এখানে `bg-(--color-brand-500)` আর সবসময়-সাদা
                        `text-(--color-ink-inverse)` বসানো ছিল। কিন্তু
                        **AA পাশ করে ৬০০ ধাপ, ৫০০ নয়** — [[Accent]]-এর
                        পুরো তালিকাটা ওই শর্তেই বাছা।
                        নীলে সাদা লেখার অনুপাত ছিল ৩.১৩, আর ABOS-এর
                        টিয়ায় দাঁড়াত **২.৪১** — অর্থাৎ যে বোতামে
                        "প্রবেশ করুন" লেখা, সেটাই পড়া যেত না।

                        ── কেন কেউ ধরেনি ────────────────────────────
                        [[AppearanceTest]] প্রতিটা রঙের ৬০০ ও ৭০০ ধাপ
                        মাপে, কিন্তু **কোন পর্দা কোন ধাপ ব্যবহার করে
                        তা দেখে না**। রংটা ঠিক ছিল, ব্যবহারটা ভুল।
                    --}}
                    <button type="submit"
                            :aria-busy="busy"
                            :class="busy && 'pointer-events-none opacity-70'"
                            class="h-(--spacing-field) w-full rounded-(--radius-field)
                                   bg-(--color-brand-600) font-medium text-(--color-brand-ink)
                                   transition-colors hover:bg-(--color-brand-700)">
                        <span x-show="!busy">{{ __('auth.sign_in') }}</span>
                        <span x-show="busy" x-cloak>{{ __('auth.authenticating') }}</span>
                    </button>
                </form>
