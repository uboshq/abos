{{--
    দুই ধাপের লগইন।

    ── কেন পুরো পাতাটা তিনটা অবস্থায় ভাগ ───────────────────────────────
    বন্ধ · বসানো চলছে · চালু — তিনটাতে মানুষের প্রশ্ন তিন রকম। একটাই
    পর্দায় সব দেখালে যিনি সবে শুরু করছেন তিনি পুনরুদ্ধার কোডের কথা পড়ে
    ঘাবড়ে যেতেন, আর যাঁর চালু আছে তিনি বসানোর নির্দেশ পড়তেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('auth.mfa_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('auth.mfa_title')" :subtitle="__('auth.mfa_subtitle')" />
    </x-slot:header>

    <div class="max-w-xl space-y-4">

        {{--
            সদ্য তৈরি পুনরুদ্ধার কোডগুলো — একবারই দেখা যায়।

            ডাটাবেজে কেবল হ্যাশ বসে, তাই এই একবারই। পাতাটা রিফ্রেশ করলে
            হারিয়ে যায়, আর সেটাই ঠিক: পর্দায় চিরকাল ঝুলে থাকা কোড কাঁধের
            উপর দিয়ে যে কেউ পড়ে নিতে পারতেন।
        --}}
        @if (session('recovery_codes'))
            <div class="rounded-(--radius-card) border border-(--color-danger)
                        bg-(--color-badge-danger-bg) p-4">
                <h2 class="font-medium text-(--color-badge-danger-ink)">
                    {{ __('auth.recovery_title') }}
                </h2>

                <p class="mt-1 text-2xs text-(--color-badge-danger-ink)">
                    {{ __('auth.recovery_note') }}
                </p>

                <div class="mt-3 grid grid-cols-2 gap-2">
                    @foreach (session('recovery_codes') as $code)
                        <code class="num rounded-(--radius-field) bg-(--color-surface-card)
                                     px-3 py-2 text-center text-sm">{{ $code }}</code>
                    @endforeach
                </div>
            </div>
        @endif

        @if (! $on && ! $secret)
            {{-- ── বন্ধ ───────────────────────────────────────────── --}}
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <p class="text-sm">{{ __('auth.mfa_why') }}</p>

                <form method="POST" action="{{ route('mfa.begin') }}" class="mt-4">
                    @csrf
                    <button type="submit"
                            class="min-h-(--spacing-touch) rounded-(--radius-field)
                                   bg-(--color-brand-600) px-4 text-sm font-medium text-white">
                        {{ __('auth.mfa_turn_on') }}
                    </button>
                </form>
            </div>

        @elseif (! $on)
            {{-- ── বসানো চলছে ─────────────────────────────────────── --}}
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <h2 class="font-medium">{{ __('auth.mfa_step_one') }}</h2>
                <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('auth.mfa_step_one_note') }}</p>

                {{--
                    চাবিটা চার-চার ভাগে — হাতে টাইপ করার জন্য।

                    ── কেন QR নয় ─────────────────────────────────────
                    QR আঁকতে একটা লাইব্রেরি লাগত। প্রতিটা অথেনটিকেটর
                    অ্যাপে হাতে বসানোর পথ আছে, আর ভাগ করা চাবি টাইপ
                    করা কঠিন নয়। লাইব্রেরিটা পরে যোগ করলে নিচের
                    ঠিকানাটাই QR-এ যাবে — কোড বদলাতে হবে না।
                --}}
                <code class="mt-3 block rounded-(--radius-field) bg-(--color-surface-app)
                             px-3 py-3 text-center text-lg tracking-widest">
                    {{ \App\Core\Security\Totp::readable($secret) }}
                </code>

                <p class="mt-2 break-all text-2xs text-(--color-ink-muted)">{{ $uri }}</p>

                <form method="POST" action="{{ route('mfa.confirm') }}" class="mt-4 space-y-2">
                    @csrf
                    <label for="code" class="block text-sm font-medium">{{ __('auth.mfa_step_two') }}</label>

                    <input id="code" name="code" type="text" inputmode="numeric" autofocus
                           class="num h-(--spacing-field) w-full rounded-(--radius-field)
                                  border border-(--color-border) bg-(--color-surface-app)
                                  px-3 text-center tracking-[0.3em]">

                    @error('code')
                        <p class="text-xs text-(--color-danger)">{{ $message }}</p>
                    @enderror

                    <button type="submit"
                            class="min-h-(--spacing-touch) w-full rounded-(--radius-field)
                                   bg-(--color-brand-600) text-sm font-medium text-white">
                        {{ __('auth.mfa_confirm') }}
                    </button>
                </form>
            </div>

        @else
            {{-- ── চালু ───────────────────────────────────────────── --}}
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <p class="flex items-center gap-2 text-sm">
                    <span class="text-(--color-success)"><x-ui.icon name="check-circle" :size="18" /></span>
                    {{ __('auth.mfa_is_on') }}
                </p>

                {{--
                    কয়টা পুনরুদ্ধার কোড বাকি।

                    ফুরিয়ে গেলে ফোন হারানোই যথেষ্ট — আর সেটা জানার
                    একমাত্র সময় হলো ফোনটা হারানোর আগে।
                --}}
                <p @class([
                    'mt-2 text-2xs',
                    'text-(--color-danger)' => $codesLeft <= 2,
                    'text-(--color-ink-muted)' => $codesLeft > 2,
                ])>
                    {{ trans_choice('auth.recovery_left', $codesLeft, ['count' => $codesLeft]) }}
                </p>

                <form method="POST" action="{{ route('mfa.destroy') }}" class="mt-4 space-y-2">
                    @csrf
                    @method('DELETE')

                    {{-- পাসওয়ার্ড আবার — MFA বন্ধ করা MFA পেরোনোর সমান
                         ক্ষমতা, তাই একই রকম প্রমাণ লাগে --}}
                    <label for="password" class="block text-sm">{{ __('auth.confirm_with_password') }}</label>

                    <input id="password" name="password" type="password" autocomplete="current-password"
                           class="h-(--spacing-field) w-full rounded-(--radius-field)
                                  border border-(--color-border) bg-(--color-surface-app) px-3">

                    @error('password')
                        <p class="text-xs text-(--color-danger)">{{ $message }}</p>
                    @enderror

                    <button type="submit"
                            class="min-h-(--spacing-touch) rounded-(--radius-field) border
                                   border-(--color-danger) px-4 text-sm text-(--color-danger)">
                        {{ __('auth.mfa_turn_off') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
