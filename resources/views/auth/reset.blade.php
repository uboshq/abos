{{--
    নতুন পাসওয়ার্ড বসানোর পর্দা — দ্বিতীয় ও শেষ ধাপ।

    ── ⛔ যা এই পাতায় **নেই**, আর সেটাই সবচেয়ে জরুরি ────────────────────
    সফল হওয়ার পর মানুষটাকে **ঢুকিয়ে দেওয়া হয় না**। ⚠️ Laravel-এর নিজের
    starter kit ঠিক সেটাই করে, আর তাতে যার দুই ধাপের লগইন চালু তার
    **ইমেইল দখল করলেই ফোনের কোড এড়ানো যেত**। তিনি স্বাভাবিক দরজা দিয়ে
    ফেরেন, যেখানে MFA আগের মতোই চায় ([[PasswordResetController::store]])।

    ── ⓘ টোকেন ও ইমেইল লুকানো ঘরে ───────────────────────────────────────
    দুইটাই ঠিকানা থেকে আসে, আর দুইটাই জমা দেওয়ার সময় লাগে — ব্রোকার
    টোকেন যাচাই করে (ইমেইল + টোকেন) জোড়া ধরে। ⚠️ ইমেইলটা `readonly`
    দেখানো হয়, লুকানো নয়: মানুষ জানা দরকার **কোন অ্যাকাউন্টের** পাসওয়ার্ড
    বসাচ্ছেন, কারণ একই ব্রাউজারে দুইজনের ঠিকানা থাকতে পারে।
--}}
<x-auth.plain :title="__('auth.reset_title')" :lead="__('auth.reset_lead')">

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                    text-sm text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.store') }}"
          x-data="{ busy: false, show: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-(--color-ink)">
                {{ __('auth.email') }}
            </label>

            {{--
                ⚠️ `readonly`, `disabled` নয় — নিষ্ক্রিয় ঘর ফর্মের সাথে
                জমা হয় না, আর তখন ব্রোকার ইমেইল ছাড়া টোকেন মেলাতে গিয়ে
                সবসময় ব্যর্থ হত। ⓘ ভুলটা কোড পড়ে ধরা পড়ত না: পাতাটা
                ঠিকঠাক দেখাত, কেবল প্রতিটা রিসেট "লিংকটা অচল" বলত।
            --}}
            <input id="email" name="email" type="email" required readonly
                   autocomplete="username" maxlength="191"
                   value="{{ old('email', $email) }}"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-muted) px-3
                          text-(--color-ink-muted)">
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-(--color-ink)">
                {{ __('auth.new_password') }}
            </label>

            <div class="relative">
                {{--
                    ⓘ `autocomplete="new-password"` — এটা ছাড়া ব্রাউজার
                    পুরনো সংরক্ষিত পাসওয়ার্ডটাই বসিয়ে দিত, আর মানুষ
                    বুঝতেই পারতেন না কেন "নতুন" পাসওয়ার্ডটা পুরনোটাই।
                --}}
                <input id="password" name="password" required autofocus
                       autocomplete="new-password" maxlength="191"
                       :type="show ? 'text' : 'password'"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-3 pe-12
                              text-(--color-ink)">

                <button type="button" @click="show = ! show"
                        class="absolute inset-y-0 end-0 flex w-12 items-center justify-center
                               text-(--color-ink-muted)"
                        :aria-label="show ? '{{ __('auth.hide_password') }}' : '{{ __('auth.show_password') }}'">
                    <span x-show="! show"><x-ui.icon name="eye" :size="18" /></span>
                    <span x-show="show" x-cloak><x-ui.icon name="eye_off" :size="18" /></span>
                </button>
            </div>

            {{--
                ⭐ নিয়মটা **আগেই** লেখা, প্রত্যাখ্যানের পরে নয়।

                ⓘ না লিখলে মানুষ ছয় অক্ষরের একটা পাসওয়ার্ড দিতেন, ফর্ম
                ফেরত আসত, আর তিনি আবার টাইপ করতেন — দুইবারের কাজ, আর
                দ্বিতীয়বারে বিরক্তি। নিয়মটা কর্মীর দরজার সমান, আর
                [[NoDoorIsWeakerThanTheStaffDoorTest]] সেটা পাহারা দেয়।
            --}}
            <p class="mt-1.5 text-xs text-(--color-ink-muted)">
                {{ __('auth.password_rule') }}
            </p>
        </div>

        <div>
            <label for="password_confirmation" class="mb-1 block text-sm font-medium text-(--color-ink)">
                {{ __('auth.confirm_password') }}
            </label>

            <input id="password_confirmation" name="password_confirmation" type="password" required
                   autocomplete="new-password" maxlength="191"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-card) px-3
                          text-(--color-ink)">
        </div>

        <button type="submit"
                class="h-(--spacing-field) w-full rounded-(--radius-field)
                       bg-(--color-brand-600) font-medium text-white
                       transition-opacity hover:opacity-90">
            <span x-show="! busy">{{ __('auth.reset_submit') }}</span>
            <span x-show="busy" x-cloak>{{ __('auth.reset_saving') }}</span>
        </button>
    </form>

</x-auth.plain>
