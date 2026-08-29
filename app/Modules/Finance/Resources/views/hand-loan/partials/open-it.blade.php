{{-- তার নিজের পাতায় — ওখানেই টাকা দেওয়া-নেওয়া আর পুরো ইতিহাস --}}
<a href="{{ route('finance.hand_loan.show', $row['account']) }}"
   class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm
          text-(--color-link) transition-colors hover:bg-(--color-surface-hover) print-hide">
    {{ __('core.action.view') }}
</a>
