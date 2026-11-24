{{-- খসড়া · অনুমোদনের অপেক্ষায় · বইয়ে বসেছে · বাতিল — ⓘ `$waiting` তালিকা দেয় (খসড়া + অনুমোদনের খোলা সারি); না দিলে খসড়া.
     ⓘ নোট আর স্টক ছাড়া ভাউচার দুজনেই এই ঘর ডাকে (`$note` = যেকোনো কাগজ, `isCancelled()`/`isConfirmed()` থাকলেই) --}}
@if ($note->isCancelled())
    <x-ui.badge tone="danger">{{ __('core.status.cancelled') }}</x-ui.badge>
@elseif ($note->isConfirmed() || $note->status === \App\Core\Support\DocumentStatus::CLOSED)
    <x-ui.badge tone="success">{{ __('core.status.confirmed') }}</x-ui.badge>
@elseif ($waiting ?? false)
    <x-ui.badge tone="info" data-waiting>{{ __('accounts::note_list.stage_waiting') }}</x-ui.badge>
@else
    <x-ui.badge tone="pending">{{ __('core.status.draft') }}</x-ui.badge>
@endif
