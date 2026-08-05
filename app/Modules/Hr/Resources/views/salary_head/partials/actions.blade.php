{{-- মূল বেতনের খাতে নিষ্ক্রিয় করার বোতামটাই নেই।

     সেবা স্তর এমনিতেই আটকায়, কিন্তু চাপা যায় অথচ ব্যর্থ হয় এমন বোতাম
     রাখা মানে ব্যবহারকারীকে দিয়ে ভুল করানো। --}}
@if ($head->is_active && ! $head->is_basic)
    <form method="POST" action="{{ route('hr.salary_head.destroy', $head->id) }}" class="text-end">
        @csrf
        @method('DELETE')
        <button type="submit"
                class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)
                       transition-colors hover:bg-(--color-surface-hover)">
            {{ __('hr::action.deactivate') }}
        </button>
    </form>
@endif
