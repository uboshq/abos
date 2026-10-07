{{-- সম্পাদনা · চালু-বন্ধ · মোছা।

     ⛔ মোছার বোতামটা কেবল তখন, যখন এই ধরনে একটাও জমা খোলা হয়নি।
     ⚠️ ব্যবহৃত ধরন মুছলে পুরনো কাগজ অনাথ হত — তখন নিষ্ক্রিয় করাই পথ,
     আর সেটা পাশের বোতামেই আছে ([[DepositKindController::destroy()]])। --}}
@can('finance.deposit_kind.manage')
    <span class="inline-flex flex-wrap items-center gap-1 print-hide">
        <a href="{{ route('finance.deposit_kind.edit', $kind) }}"
           class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm
                  text-(--color-link) transition-colors hover:bg-(--color-surface-hover)">
            {{ __('core.action.edit') }}
        </a>

        <form method="POST" action="{{ route('finance.deposit_kind.toggle', $kind) }}">
            @csrf
            <button type="submit"
                    class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm
                           text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)">
                {{ __($kind->is_active ? 'finance::action.kind_off' : 'finance::action.kind_on') }}
            </button>
        </form>

        @if ($kind->deposits_count === 0)
            <form method="POST" action="{{ route('finance.deposit_kind.destroy', $kind) }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm
                               text-(--color-badge-danger-ink) transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('core.action.delete') }}
                </button>
            </form>
        @endif
    </span>
@endcan
