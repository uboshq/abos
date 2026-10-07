{{--
    এই ব্যবহারকারীর দুই ধাপ — অবস্থা, আর বদলানোর বোতাম।

    ── ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
    *"ব্যবহারকারী লিস্টে ২ স্টেপের অন-অফ বোতাম দাও।"*

    ── ⓘ তিনটা অবস্থা, আর তিনটাই আলাদা কথা ──────────────────────────────
    ⭐ চালু — লাগে, আর তিনি বসিয়েও ফেলেছেন।
    ⭐ সেট হয়নি — লাগে, কিন্তু এখনো বসাননি; পরের অনুরোধেই বসানোর পর্দা।
    ⭐ বন্ধ — লাগে না।

    ⚠️ "চালু" আর "সেট হয়নি" এক করে দেখালে প্রশাসক ভাবতেন কাজ শেষ, অথচ
    ঐ অ্যাকাউন্টে তখনো দ্বিতীয় তালা পড়েনি।

    ── ⛔ বন্ধ করার ফর্মে কারণের ঘর, চালুর ফর্মে নয় ─────────────────────
    ⓘ চালু করায় নিরাপত্তা বাড়ে, তাই কারণ লাগে না। ⛔ বন্ধ করা মানে তালা
    খোলা — কারণ বাধ্যতামূলক, আর সেটা নিরীক্ষার খাতায় বসে।

    ── ⚠️ কেন নিজের সারিতে বন্ধের বোতাম আঁকা হয় না ─────────────────────
    সার্ভার এমনিতেই নিজের তালা নিজে খুলতে দেয় না
    ([[UserController::setTwoStep()]])। ⓘ তবু বোতামটা না দেখানো ভালো:
    একটা বোতাম যা চাপলে ভুল বলে, সেটা পর্দার মিথ্যা।
--}}
@php
    $required = App\Http\Middleware\SuperAdminMustHaveTwoSteps::isRequiredFor($user);
    $isOn = app(App\Core\Security\MfaService::class)->isOn($user);
    $isSelf = (int) auth()->id() === (int) $user->id;

    [$label, $tone] = match (true) {
        $required && $isOn => [__('auth.two_step_state_on'), 'success'],
        $required => [__('auth.two_step_state_pending'), 'warning'],
        default => [__('auth.two_step_state_off'), 'danger'],
    };
@endphp

<div class="space-y-1">
    <span data-two-step-state
          class="inline-block rounded-(--radius-field) bg-(--color-badge-{{ $tone }}-bg)
                 px-2 py-0.5 text-2xs text-(--color-badge-{{ $tone }}-ink)">
        {{ $label }}
    </span>

    @if (! $required)
        <form method="POST" action="{{ route('system_admin.user.two_step.set', $user) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="required" value="1">

            <button type="submit" class="text-2xs text-(--color-link) underline">
                {{ __('auth.two_step_turn_on') }}
            </button>
        </form>
    @elseif (! $isSelf)
        <form method="POST" action="{{ route('system_admin.user.two_step.set', $user) }}"
              class="space-y-1">
            @csrf
            @method('PUT')
            <input type="hidden" name="required" value="0">

            {{-- ⓘ কারণের ঘরটা এখানেই, আলাদা পর্দায় নয় — প্রশাসক তালিকা
                 ছেড়ে না গিয়েই লিখতে পারেন, আর ঘরটা খালি রেখে চাপলে
                 সার্ভার থামায়। --}}
            <input name="reason" type="text" maxlength="500"
                   placeholder="{{ __('auth.two_step_reset_needs_reason') }}"
                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field)
                          border border-(--color-border) bg-(--color-surface-app) px-2 text-2xs">

            <button type="submit" class="text-2xs text-(--color-danger) underline">
                {{ __('auth.two_step_turn_off') }}
            </button>
        </form>
    @endif

    @error('reason')
        <p class="text-2xs text-(--color-danger)">{{ $message }}</p>
    @enderror
</div>
