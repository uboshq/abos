{{-- সিদ্ধান্ত হয়ে গেলে বোতামগুলো আর থাকে না — চাপা যায় অথচ কিছু হয় না
     এমন বোতাম রাখা মানে ব্যবহারকারীকে দিয়ে ভুল করানো। --}}
@if ($application->isPending())
    {{-- ⭐ চাবির উপরে "কার" প্রশ্ন: কর্মীর নিজের ব্যবস্থাপক বা HR — সেবা যা মানে, বোতামও তাই (ঘাটতির তালিকা #১৫; [[LeaveSigner]]) --}}
    @if (auth()->user()?->can('hr.leave.approve') && ($signer ?? app(\App\Modules\Hr\Support\LeaveSigner::class))->mayDecide(auth()->user(), $application))
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
    @endif
@elseif ($application->isApproved())
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
