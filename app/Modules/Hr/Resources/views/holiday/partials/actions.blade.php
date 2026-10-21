{{-- ⓘ বদল আর মোছা — কেবল লেখার চাবিতে; নাগালের বাইরের সারিতে নিয়ামক ৪০৩ দেয় ([[HolidayController::writable()]]) --}}
@can('hr.attendance.manage')
    <div class="flex justify-end gap-1">
        <a href="{{ route('hr.holiday.edit', $holiday->id) }}"
           class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)
                  transition-colors hover:bg-(--color-surface-hover)">{{ __('hr::action.edit') }}</a>
        <form method="POST" action="{{ route('hr.holiday.destroy', $holiday->id) }}">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)
                           transition-colors hover:bg-(--color-surface-hover)">
                {{ __('hr::action.delete') }}
            </button>
        </form>
    </div>
@endcan
