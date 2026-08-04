@can('master_data.manage')
    <a href="{{ route('master_data.' . $spec['route'] . '.edit', $record->id) }}"
       class="num text-(--color-brand-500) underline-offset-2 hover:underline">{{ $record->code }}</a>
@else
    <span class="num">{{ $record->code }}</span>
@endcan
