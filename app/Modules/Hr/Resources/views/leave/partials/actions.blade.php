{{-- সিদ্ধান্ত হয়ে গেলে বোতামগুলো আর থাকে না — চাপা যায় অথচ কিছু হয় না
     এমন বোতাম রাখা মানে ব্যবহারকারীকে দিয়ে ভুল করানো। --}}
@if ($application->isPending())
    {{-- ⭐ সেবা যা সই নেবে, বোতামও কেবল সেখানে — চাবি, নিজের নয়, নিজে বসানো নয়, কর্মীর নিজের ব্যবস্থাপক বা HR (ঘাটতির তালিকা #১৫; [[LeaveSigner::maySign()]]) --}}
    @php($signer ??= app(\App\Modules\Hr\Support\LeaveSigner::class))
    @if ($signer->maySign(auth()->user(), $application))
        <span class="flex flex-wrap items-center justify-end gap-1">
            <form method="POST" action="{{ route('hr.leave.approve', $application->id) }}">
                @csrf
                <button type="submit"
                        class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-success)
                               transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('hr::action.approve') }}
                </button>
            </form>

            {{-- নামঞ্জুরে কারণ বাধ্যতামূলক: কেন হল না তা না জানলে কর্মী
                 একই আবেদন আবার করবেন, আর দুইজনেরই সময় যাবে। --}}
            <form method="POST" action="{{ route('hr.leave.reject', $application->id) }}"
                  class="flex items-center gap-1">
                @csrf
                <input type="text" name="remarks" required
                       placeholder="{{ __('hr::field.reason') }}"
                       class="w-24 rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-1.5 py-1 text-2xs">
                <button type="submit"
                        class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)
                               transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('hr::action.reject') }}
                </button>
            </form>
        </span>
    @elseif ($signer->fallsToHr(auth()->user(), $application))
        {{-- ⭐ নিজের দলের কর্মী, কিন্তু নাগালের বাইরে — সই HR-এর (fe, ২১ অক্টোবর ২০২৬) --}}
        <span class="text-2xs text-(--color-ink-muted)">{{ __('hr::message.leave_falls_to_hr') }}</span>
    @endif
@elseif ($application->isApproved())
    {{-- ⭐ মাতৃত্বকালীন ছুটি — প্রসবের আসল দিন লেখা, আর অপেক্ষার অনুরোধে "সই দিন" (ধারা ৪৫; [[MaternityDeliveryService]]) --}}
    @if ($application->leaveType?->isMaternity())
        @include('hr::leave.partials.delivery', ['application' => $application])
    @endif
    @can('hr.leave.manage')
        <form method="POST" action="{{ route('hr.leave.cancel', $application->id) }}" class="text-end">
            @csrf
            <button type="submit"
                    class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)
                           transition-colors hover:bg-(--color-surface-hover)">
                {{ __('hr::action.withdraw') }}
            </button>
        </form>
    @endcan
@endif
