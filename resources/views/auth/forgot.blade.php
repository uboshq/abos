{{--
    ঠিকানা চাওয়ার পর্দা — "পাসওয়ার্ড ভুলে গেছি"-র প্রথম ধাপ।

    ── ⛔ এই পাতার একমাত্র কঠিন সিদ্ধান্তটা ─────────────────────────────
    জমা দেওয়ার পর **সবসময় একই বার্তা** — ঠিকানাটা খাতায় থাকুক বা না
    থাকুক। ⚠️ আলাদা বললে বাইরের যে কেউ ঠিকানা বসিয়ে বসিয়ে কর্মীদের
    তালিকা গুনে নিতে পারতেন, আর লগইনের দরজায় ঠিক এই সিদ্ধান্তটাই আগে
    থেকেই নেওয়া আছে ([[CredentialCheck]] — এক বার্তা, সব ক্ষেত্রে)।

    ⓘ দামটা সত্যি: যিনি ভুল ঠিকানা টাইপ করেছেন তিনিও "পাঠানো হয়েছে"
    দেখবেন আর অপেক্ষা করবেন। ⭐ সেজন্যই নিচের দ্বিতীয় বাক্যটা — ওটা
    ঐ মানুষটার জন্যই লেখা।
--}}
<x-auth.plain :title="__('auth.forgot_title')" :lead="__('auth.forgot_lead')">

    @if (session('sent'))
        {{--
            ⭐ সফল বার্তাটা শর্তসাপেক্ষ ভাষায় — "থাকলে"।

            ⓘ "পাঠানো হয়েছে" বললে সেটা নিজেই একটা তথ্য হত: ঠিকানাটা
            আছে। "ঠিকানাটা আমাদের খাতায় থাকলে পাঠানো হয়েছে" একই
            সান্ত্বনা দেয়, কিন্তু কিছু ফাঁস করে না।
        --}}
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2.5
                    text-sm text-(--color-badge-success-ink)">
            {{ __('auth.forgot_sent') }}
        </div>

        {{--
            ⛔ দ্বিতীয় বাক্য — ইমেইল নেই এমন ব্যবহারকারীর একমাত্র সৎ পথ।

            ── কেন এটা ছাড়া ফিচারটা অর্ধেক ──────────────────────────
            ⚠️ ডিপোর কর্মীর ইমেইল প্রায়ই কাগজে-কলমে — `sales@abos.test`
            ধরনের একটা ঠিকানা যেটা কেউ কোনোদিন খোলে না। ⓘ তাঁর বেলায়
            চিঠিটা কোথাও যায় না, আর উপরের বার্তাটা তাঁকে **সারাদিন
            ইনবক্স খুলে বসিয়ে রাখত**।

            ⭐ তাঁর জন্য পথটা আগের মতোই আছে — মালিক হাতে বসিয়ে দেন
            (`UserController`, `password_set`)। সেটা লুকিয়ে রাখার কোনো
            কারণ নেই; বরং না লিখলে তিনি কখনো জানতেন না।
        --}}
        <p class="text-sm text-(--color-ink-muted)">
            {{ __('auth.forgot_no_mail') }}
        </p>
    @else
        @if ($errors->any())
            <div role="alert"
                 class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                        text-sm text-(--color-badge-danger-ink)">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}"
              x-data="{ busy: false }"
              @submit="busy ? $event.preventDefault() : (busy = true)"
              class="space-y-4">
            @csrf

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-(--color-ink)">
                    {{ __('auth.email') }}
                </label>

                {{--
                    ⓘ `type="email"` — ফোনের কিবোর্ডে `@` সামনে আসে।
                    আর `autofocus`: এই পাতায় ঘর একটাই, তাই কার্সার
                    অন্য কোথাও থাকার কোনো কারণ নেই।

                    ⚠️ এখানে "ব্যবহারকারীর নাম" চাওয়া হয় না, যদিও
                    লগইনে নাম দিয়েও ঢোকা যায় — চিঠি কেবল একটা ঠিকানায়
                    যেতে পারে, আর নাম চেয়ে নিলে মানুষ নাম লিখে
                    অপেক্ষা করতেন।
                --}}
                <input id="email" name="email" type="email" required autofocus
                       autocomplete="email" maxlength="191"
                       value="{{ old('email') }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-3
                              text-(--color-ink)">
            </div>

            <button type="submit"
                    class="h-(--spacing-field) w-full rounded-(--radius-field)
                           bg-(--color-brand-600) font-medium text-white
                           transition-opacity hover:opacity-90">
                <span x-show="! busy">{{ __('auth.forgot_submit') }}</span>
                <span x-show="busy" x-cloak>{{ __('auth.forgot_sending') }}</span>
            </button>
        </form>
    @endif

</x-auth.plain>
