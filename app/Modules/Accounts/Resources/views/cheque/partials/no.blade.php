{{ $cheque->cheque_no }}
<span class="block text-2xs text-(--color-ink-muted)">
    {{ __('accounts::field.cheque_'.$cheque->direction) }}
</span>

{{-- উৎস — কোন আদায়ের কাগজ থেকে এসেছে (মালিকের নিয়ম: প্রতিটা সংখ্যা→উৎস)।
     view রুট-নাম চেনে, Sales-ক্লাস নয় — স্তর অক্ষত। --}}
@if ($cheque->collection_id)
    @can('sales.collection.view')
        <a href="{{ route('sales.collection.show', $cheque->collection_id) }}"
           class="mt-0.5 block text-2xs text-(--color-brand-600) hover:underline">
            {{ __('accounts::field.cheque_source') }}
        </a>
    @endcan
@endif
