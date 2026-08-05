{{-- সারির নিজের বাড়তি পর্দা — এখন কেবল মুদ্রার হারের ইতিহাস।

     দেখার অনুমতিতেই দেখা যায়, নিচের বোতামগুলোর মতো manage লাগে না:
     হার দেখা আর হার বসানো এক কাজ নয়। --}}
@if ($spec['extra_action'] ?? false)
    <a href="{{ route($spec['extra_action']['route'], ['id' => $record->id]) }}"
       class="mr-1 rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-brand-500)
              transition-colors hover:bg-(--color-surface-hover)">
        {{ __($spec['extra_action']['label']) }}
    </a>
@endif

@can('master_data.manage')
    <span class="flex flex-wrap justify-end gap-1">
        {{-- ডিফল্ট করা এক ক্লিকে — ফর্ম খুলে সেভ করতে হয় না --}}
        @if ($record::supportsDefault() && ! $record->is_default && $record->is_active)
            <form method="POST" action="{{ route('master_data.' . $spec['route'] . '.default', $record->id) }}">
                @csrf
                <button type="submit"
                        class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-brand-500)
                               transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('master_data::action.make_default') }}
                </button>
            </form>
        @endif

        @if ($record->is_active)
            <form method="POST" action="{{ route('master_data.' . $spec['route'] . '.destroy', $record->id) }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted)
                               transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('master_data::action.deactivate') }}
                </button>
            </form>
        @endif
    </span>
@endcan
