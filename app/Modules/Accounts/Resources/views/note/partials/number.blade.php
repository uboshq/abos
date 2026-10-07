{{-- নম্বরটা কাগজটাই খোলে — নিয়ম ১ --}}
<a href="{{ route('accounts.note.show', $note) }}"
   class="font-medium text-(--color-brand-600) underline-offset-2 hover:underline">{{ $note->document_no }}</a>
