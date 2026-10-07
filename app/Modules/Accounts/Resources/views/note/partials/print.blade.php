{{-- ছাপা — নতুন ট্যাবে, কোম্পানির বাছা মাপে ([[NotePrintController]]) --}}
<a href="{{ route('accounts.note.print', $note) }}" target="_blank" rel="noopener" data-note-print
   class="inline-flex items-center gap-1 text-(--color-brand-600) underline-offset-2 hover:underline">
    <x-ui.icon name="printer" class="size-4" />
    <span>{{ __('core.action.print') }}</span>
</a>
