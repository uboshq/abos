{{-- রিসাইকেল বিনের সারির দুই কাজ (§১৯) — ফেরানো, আর নিজের চাবিতে চিরতরে মোছা --}}
<div class="flex flex-wrap justify-end gap-2">
    @can('restore', $document)
        <form method="POST" action="{{ route('documents.bin.restore', $document->id) }}">
            @csrf
            <button type="submit" class="text-(--color-link) hover:underline">{{ __('documents::action.restore_from_bin') }}</button>
        </form>
    @endcan

    @can('forceDelete', $document)
        <form method="POST" action="{{ route('documents.bin.purge', $document->id) }}"
              data-confirm="{{ __('documents::message.purge_confirm') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-(--color-danger) hover:underline">{{ __('documents::action.purge') }}</button>
        </form>
    @endcan
</div>
