<a href="{{ route('hr.payslip.print', $slip->id) }}"
   class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-brand-500)
          transition-colors hover:bg-(--color-surface-hover)">
    {{ __('hr::action.print_one') }}
</a>
