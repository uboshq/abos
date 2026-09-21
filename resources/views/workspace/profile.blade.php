{{--
    নিজের প্রোফাইল — পরিচয়, যোগাযোগ, ছবি ও পাসওয়ার্ড।

    ── ⭐ ১৪ সেপ্টেম্বর ২০২৬: পাতাটা প্রায় খালি ছিল ─────────────────────
    মালিক পাতাটা খুলে সাতটা জিনিস অনুপস্থিত বলেছেন: পদবি, ব্যবহারকারী
    আইডি, মোবাইল, বিকল্প মোবাইল, ঠিকানা, পাসওয়ার্ড বদল — আর ছবি আপলোড
    কাজ না করা। ⓘ এর মধ্যে তিনটার ঘরই ডাটাবেজে ছিল না।

    ── কেন চারটা ফর্ম, একটা নয় ──────────────────────────────────────────
    ছবি বসানো, ছবি মোছা, তথ্য বদলানো আর পাসওয়ার্ড বদলানো — চারটা আলাদা
    কাজ। ⛔ একটা ফর্মে জুড়লে নাম ঠিক করতে গিয়ে ছবি না দিলে ছবিটা মুছে
    যেত, আর পাসওয়ার্ডের ঘর খালি থাকলে হয় ফর্ম আটকাত নয়তো চাবিটা মুছত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.profile.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.profile.title')"
                          :subtitle="__('core.profile.subtitle')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ __('core.profile.saved') }}
        </div>
    @endif

    {{-- ⓘ পাসওয়ার্ডের নিজের বার্তা, সাধারণ "সংরক্ষিত" নয় — ⚠️ চাবি
         বদলানো এমন একটা কাজ যেটা ভুল করে হয়ে গেলে মানুষ লগইন করতে
         পারেন না। তাই কী বদলেছে সেটা নাম ধরে বলা হয়। --}}
    @if (session('password_saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ __('core.profile.password_saved') }}
        </div>
    @endif

    @if (session('email_sent'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-info-bg) px-3 py-2 text-sm
                    text-(--color-badge-info-ink)">
            {{ __('core.profile.email_sent', ['email' => session('email_sent')]) }}
        </div>
    @endif

    @if (session('email_changed'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ __('core.profile.email_changed', ['email' => session('email_changed')]) }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        /* ⓘ একই ক্লাসগুলো ছয়টা ঘরে — একবার লেখা, নাহলে একদিন একটা ঘর
           অন্যগুলোর চেয়ে আলাদা উঁচু হয়ে বসত। */
        $field = 'h-(--spacing-field) w-full rounded-(--radius-field) border
                  border-(--color-border) bg-(--color-surface-card) px-3';

        /* ⚠️ যে ঘর বদলানো যায় না তার জমিন আলাদা — কালি হালকা করাই যথেষ্ট
           নয়, কারণ হালকা কালি দেখতে "নিষ্ক্রিয়" নয়, "ফাঁকা" লাগে। */
        $fieldReadOnly = 'h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-app) px-3
                          text-(--color-ink-muted)';
    @endphp

    <div class="max-w-2xl space-y-4">

        {{-- ছবি --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.profile.photo') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('core.profile.photo_note', ['mb' => $maxMb]) }}
            </p>

            <div class="flex flex-wrap items-center gap-4">
                <x-ui.avatar :user="$user" size="lg" class="ring-1 ring-(--color-border)" />

                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('profile.avatar') }}"
                          enctype="multipart/form-data"
                          x-data="{ busy: false }"
                          @submit="busy ? $event.preventDefault() : (busy = true)">
                        @csrf

                        {{-- ফাইল ইনপুটটা লেবেলের ভেতরে: ব্রাউজারের নিজের
                             "Choose file" বোতামটা আমাদের বোতামের মতো
                             দেখানো যায় না, আর দুটো আলাদা ভাষা মেনে চলে। --}}
                        <label class="inline-flex min-h-(--spacing-touch) cursor-pointer items-center gap-2
                                      rounded-(--radius-field) bg-(--color-brand-700) px-4 text-sm font-medium
                                      text-(--color-ink-inverse) transition-opacity hover:opacity-90"
                               :class="busy && 'pointer-events-none opacity-70'">
                            {{-- ⚠️ এখানে আগে `onchange="this.form.requestSubmit()"`
                                 ছিল, অর্থাৎ ছবি বাছার সাথে সাথেই ফর্ম চলে
                                 যেত। ⛔ ক্রপের পর্দা খোলার কোনো ফাঁক
                                 থাকত না। ⭐ এখন জমা দেওয়ার কাজটা পর্দার
                                 হাতে (`submit: true`), আর ব্যবহারকারী বাদ
                                 দিলেও ফর্ম ঠিকই জমা হয়। --}}
                            <input type="file" name="avatar" class="sr-only"
                                   accept="image/jpeg,image/png,image/webp"
                                   x-on:change="$store.scanner.begin($el, 'face', { submit: true })">
                            {{ $user->avatarUrl()
                                ? __('core.profile.change_photo')
                                : __('core.profile.upload_photo') }}
                        </label>

                        {{--
                            ⭐ হাতে চাপার বোতামটা — মালিকের অভিযোগ,
                            ১৪ সেপ্টেম্বর ২০২৬: *"Profile pic uploads kora
                            zay na"*।

                            ── ⛔ কেন এটা দরকার ──────────────────────────
                            ⚠️ এই ফর্মটা জমা দেওয়ার **একমাত্র পথ ছিল
                            JavaScript**: ছবি বাছলে স্ক্যানারের পর্দা খুলত,
                            আর সে-ই শেষে `requestSubmit()` ডাকত। ⛔ ঐ
                            শৃঙ্খলের যেকোনো একটা কড়া ছিঁড়লে — স্ক্রিপ্ট
                            না নামলে, পুরনো ব্রাউজার হলে, বা বিল্ড বাসি
                            থাকলে — ছবি বাছার পর **কিছুই হত না**, আর
                            কোনো ভুলের বার্তাও আসত না।

                            ⓘ লক্ষণটা হুবহু যা মালিক বলেছেন: "আপলোড করা
                            যায় না"। ⭐ তাই এখন একটা সাধারণ HTML বোতাম
                            আছে, যেটা JavaScript ছাড়াও কাজ করে। স্ক্যানার
                            থাকলে সে আগেই জমা দিয়ে দেয়, আর তখন এই বোতামে
                            চাপার দরকারই পড়ে না।

                            ⚠️ লোকালে আমি ভাঙাটা ধরতে পারিনি — এখানে
                            আপলোড কাজ করে। তাই এটা রোগ সারানো নয়,
                            **রোগটাকে অসম্ভব করা**: জমা দেওয়ার একটা পথ
                            রইল যেটা স্ক্রিপ্টের উপর দাঁড়িয়ে নেই।
                        --}}
                        <x-ui.button type="submit" tone="secondary" class="ms-2">
                            {{ __('core.profile.upload_now') }}
                        </x-ui.button>
                    </form>

                    @if ($user->avatarUrl())
                        <form method="POST" action="{{ route('profile.avatar.remove') }}"
                              data-confirm="{{ __('core.profile.remove_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('core.profile.remove_photo') }}
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        {{-- পরিচয় ও যোগাযোগ — একটাই ফর্ম, দুইটা ভাগ।

             ⓘ দুইটা আলাদা সেকশন দেখালেও জমা হয় একসাথে: কেউ নাম ঠিক করতে
             এসে নম্বরটাও ঠিক করেন, আর তখন দুইবার সংরক্ষণ করা অকারণ। --}}
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('core.profile.identity') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.name') }}</span>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                               class="{{ $field }}">
                    </label>

                    {{-- ⭐ লগইন আইডি — মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬।

                         ── ⛔ কেন এটা এলো, আর নামটা কী হারাল ──────────
                         ⚠️ এতদিন **নাম দিয়েও লগইন করা যেত**, অথচ
                         `users.name`-এ unique সূচক নেই আর নামটা যে কেউ
                         এই পাতা থেকেই বদলাতে পারেন। ⛔ অর্থাৎ একজন
                         নিজের নামের ঘরে আরেকজনের ইমেইল বসিয়ে দিলে ঐ
                         ঠিকানায় দুইটা সারি মিলত, আর আসল মানুষটা নিজের
                         অ্যাকাউন্টে ঢুকতে পারতেন না — পর্দায় লেখা উঠত
                         "ভুল পাসওয়ার্ড"।

                         ⭐ এখন পরিচয় তিনটা আর তিনটাই নিজস্ব: এই আইডি,
                         ইমেইল, আর মোবাইল। ⓘ নাম আবার শুধু নাম। --}}
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.login_id') }}</span>
                        <input type="text" name="login_id" value="{{ old('login_id', $user->login_id) }}" required
                               autocapitalize="none" spellcheck="false"
                               class="{{ $field }} font-mono">
                        <span class="mt-1 block text-xs text-(--color-ink-muted)">
                            {{ __('core.profile.login_id_note') }}
                        </span>
                    </label>

                    {{-- ইমেইল দেখানো হয়, বদলানো যায় না: এটা লগইনের পরিচয়,
                         আর নিজে থেকে বদলাতে দিলে যাচাই ছাড়া অ্যাকাউন্ট
                         অন্য ঠিকানায় সরে যেত। প্রশাসক বদলাবে। --}}
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.email') }}</span>
                        <input type="email" value="{{ $user->email }}" disabled
                               class="{{ $fieldReadOnly }}">

                        {{-- ⓘ অপেক্ষমাণ ঠিকানাটা এখানেই বলা হয় — ⚠️ নাহলে
                             কেউ অনুরোধ করে চিঠিটা না পেয়ে ভাবতেন কিছুই
                             হয়নি, আর বারবার অনুরোধ করতেন। --}}
                        @if ($user->pending_email)
                            <span class="mt-1 block text-xs text-(--color-badge-pending-ink)">
                                {{ __('core.profile.email_pending', ['email' => $user->pending_email]) }}
                            </span>
                        @endif
                    </label>

                    {{-- ⛔ "ব্যবহারকারী আইডি" (`public_id`) এখানে আর নেই —
                         ১৯ সেপ্টেম্বর ২০২৬।

                         ⓘ ১৪ তারিখে মালিক "user id" চেয়েছিলেন, আর আমি
                         বসিয়েছিলাম সিস্টেমের ভেতরের স্থায়ী নম্বরটা
                         (`01a09a9b-cdcb-…`)। ⚠️ মালিক পাতাটা দেখে জিজ্ঞেস
                         করলেন *"User ID diye kaj ki ekane?"* — আর প্রশ্নটা
                         ন্যায্য: ঐ নম্বর কেউ মুখে বলতে পারে না, মনে রাখতে
                         পারে না, আর তা দিয়ে কোথাও ঢোকাও যায় না।

                         ⭐ তিনি আসলে চেয়েছিলেন **লগইন আইডি** — নিজের বাছা,
                         মনে রাখার মতো, যেটা দিয়ে ঢোকা যায়। সেটা এখন উপরের
                         ঘরেই আছে। দুইটা "আইডি" পাশাপাশি থাকায় কোনটা কী,
                         সেটাই গুলিয়ে যাচ্ছিল।

                         ⓘ `public_id` নিজে মোছা হয়নি — drill-এর পথ আর
                         অডিট ঐ নম্বরের উপর দাঁড়িয়ে। কেবল পর্দা থেকে সরানো। --}}

                    {{-- ⭐ পদবি — দেখানো হয়, বদলানো যায় না।

                         ⓘ পদবি থাকে HR-এর কর্মী-রেকর্ডে, আর এই পাতাটা
                         কোরের। উত্তরটা আসে ফ্যাক্ট-রেজিস্ট্রি দিয়ে,
                         কন্ট্রোলারে — কোর HR-কে চেনে না।

                         ⛔ সম্পাদনার ঘর করা হয়নি: কেউ নিজের পদবি
                         "ব্যবস্থাপনা পরিচালক" লিখে দিলে ওটা পর্দায়
                         সত্যির মতোই দেখাত। ⚠️ যাঁর কর্মী-রেকর্ড নেই
                         তাঁর ঘরটা ফাঁকা থাকে, আর ঘরটা কেন ফাঁকা সেটাও
                         লেখা — নাহলে মানুষ ভাবতেন ব্যবস্থাটা ভাঙা। --}}
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.designation') }}</span>
                        <input type="text" readonly
                               value="{{ $designation ?? __('core.profile.designation_none') }}"
                               class="{{ $fieldReadOnly }}">
                    </label>
                </div>
            </section>

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('core.profile.contact') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    {{-- ⓘ `type="tel"` — ফোনে সংখ্যার কিবোর্ড খোলে। ⚠️
                         `type="number"` নয়: ওটা `+880` আর ফাঁকা জায়গা
                         মানে না, আর তীর চাপলে নম্বর এক বেড়ে যায়। --}}
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.mobile') }}</span>
                        <input type="tel" name="mobile" value="{{ old('mobile', $user->mobile) }}"
                               autocomplete="tel" class="{{ $field }}">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.mobile_alt') }}</span>
                        <input type="tel" name="mobile_alt" value="{{ old('mobile_alt', $user->mobile_alt) }}"
                               class="{{ $field }}">
                        <span class="mt-1 block text-xs text-(--color-ink-muted)">
                            {{ __('core.profile.mobile_alt_note') }}
                        </span>
                    </label>

                    {{-- ⓘ ঠিকানা দুই কলাম জুড়ে, আর `textarea` — এক লাইনের
                         ঘরে গ্রামের ঠিকানা লিখতে গেলে শুরুটা আর দেখা যায়
                         না, অথচ ঠিকানা পড়া হয় পুরোটা একসাথে। --}}
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.address') }}</span>
                        <textarea name="address" rows="2"
                                  class="w-full rounded-(--radius-field) border border-(--color-border)
                                         bg-(--color-surface-card) px-3 py-2">{{ old('address', $user->address) }}</textarea>
                    </label>
                </div>

                <div class="mt-3">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                </div>
            </section>
        </form>

        {{-- ⭐ ইমেইল বদলানো — মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬।

             ── ⓘ কেন এটা উপরের ফর্মে নেই ───────────────────────────────
             উপরের ফর্মটা জমা দিলে সাথে সাথে বদলে যায়। ⛔ ইমেইল ওভাবে
             বদলানো যায় না: ওটা লগইনের পরিচয় **আর** পাসওয়ার্ড ফিরে
             পাওয়ার একমাত্র পথ। একটা অক্ষর ভুল লিখলেই মানুষ নিজের
             অ্যাকাউন্ট থেকে চিরতরে বেরিয়ে যেতেন।

             ⭐ তাই এটা দুই ধাপের: এখানে অনুরোধ, আর নতুন ঠিকানায় যাওয়া
             চিঠির লিংকে চাপ দিলে তবেই বদল। ⓘ ততক্ষণ পুরনো ঠিকানাটা
             কাজ করতেই থাকে।

             ⚠️ পাসওয়ার্ড চাওয়া হয় — ডিপোতে একজন লগআউট না করে উঠে গেলে
             খোলা সেশনটা যে কেউ পান, আর ইমেইল বদলে নেওয়া মানে
             অ্যাকাউন্টটাই নিয়ে নেওয়া। --}}
        <form method="POST" action="{{ route('profile.email.request') }}">
            @csrf

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('core.profile.email_change') }}</h2>
                <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('core.profile.email_change_note') }}
                </p>

                {{-- ⛔ চিঠি না গেলে সেটা **চাপার আগেই** বলা হয়।

                     ⚠️ কন্ট্রোলারও আটকায়, তাই এটা নিরাপত্তার জন্য নয় —
                     ⓘ ভদ্রতার জন্য। বোতামটা চাপার পর "হলো না" বলা আর
                     চাপার আগে "এখন হবে না" বলা এক জিনিস নয়: প্রথমটায়
                     মানুষ নতুন ঠিকানা আর পাসওয়ার্ড টাইপ করে তবে জানেন।

                     ⭐ SMTP বসানোর দিন এটা নিজে থেকেই মিলিয়ে যাবে;
                     কোনো কোড বদলাতে হবে না। --}}
                @if (\App\Core\Support\MailReach::silent())
                    <p role="status"
                       class="mb-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm
                              text-(--color-badge-pending-ink)">
                        {{ __('core.profile.email_no_mailer') }}
                    </p>
                @endif

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.email_new') }}</span>
                        <input type="email" name="email" value="{{ old('email') }}" required
                               autocomplete="email" class="{{ $field }}">
                    </label>

                    {{-- ⓘ `autocomplete="current-password"` — পাসওয়ার্ড
                         ব্যবস্থাপককে বলে দেয় এটা **এখনকার** চাবি, নতুন
                         নয়। ⚠️ না দিলে সে এখানে একটা নতুন চাবি বানিয়ে
                         বসাতে চাইত। --}}
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.current_password') }}</span>
                        <input type="password" name="current_password" required
                               autocomplete="current-password" class="{{ $field }}">
                    </label>
                </div>

                <div class="mt-3">
                    <x-ui.button type="submit" tone="secondary">
                        {{ __('core.profile.email_change') }}
                    </x-ui.button>
                </div>
            </section>
        </form>

        {{-- ⭐ পাসওয়ার্ড — মালিকের নির্দেশ, ১৪ সেপ্টেম্বর ২০২৬:
             *"Passworad poriborton nai"*।

             ⚠️ নিজের ফর্ম, উপরেরটার সাথে জোড়া নয় — ⛔ জুড়লে নাম ঠিক
             করতে গিয়ে পাসওয়ার্ডের ঘর খালি থাকলে হয় ফর্ম আটকাত, নয়তো
             চাবিটা মুছে যেত। --}}
        <form method="POST" action="{{ route('profile.password') }}">
            @csrf
            @method('PUT')

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('core.profile.password') }}</h2>
                <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('core.profile.password_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    {{-- ⓘ `autocomplete` তিনটাই আলাদা — ⚠️ না দিলে
                         পাসওয়ার্ড-ব্যবস্থাপকগুলো তিনটা ঘরেই পুরনো চাবি
                         বসিয়ে দিত, আর নতুনটা সংরক্ষণও করত না। --}}
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.current_password') }}</span>
                        <input type="password" name="current_password" required
                               autocomplete="current-password" class="{{ $field }}">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.new_password') }}</span>
                        <input type="password" name="password" required
                               autocomplete="new-password" class="{{ $field }}">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.confirm_password') }}</span>
                        <input type="password" name="password_confirmation" required
                               autocomplete="new-password" class="{{ $field }}">
                    </label>
                </div>

                <div class="mt-3">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                </div>
            </section>
        </form>
    </div>
</x-layouts.app>
