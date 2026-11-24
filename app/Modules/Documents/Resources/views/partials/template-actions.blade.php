<div class="flex flex-wrap justify-end gap-3">
    @if ($row->is_active)
        @can('documents.upload')
            <a href="{{ route('documents.templates.fill', $row) }}" class="text-(--color-link) hover:underline">{{ __('documents::action.use_template') }}</a>
        @endcan
    @endif
    @can('documents.templates')
        <a href="{{ route('documents.templates.edit', $row) }}" class="text-(--color-link) hover:underline">{{ __('documents::action.edit_template') }}</a>
    @endcan
</div>
