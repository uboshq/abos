{{--
    গাছের একটা সারি, আর তার নিচেরগুলো।

    ইন্ডেন্ট padding দিয়ে, নেস্টেড টেবিল দিয়ে নয় — হিসাবের ছকে একই
    সিদ্ধান্ত, একই কারণে: এক টেবিল, এক কলাম-প্রস্থ।

    "+" বোতামটা পরের স্তরের নাম বলে ("এরিয়া যোগ করুন"), শুধু "+" নয়:
    সাত স্তরের মইয়ে কোনটার নিচে কী বসে তা মনে রাখতে বলা অন্যায্য।
--}}
@php
    $childLevel = \App\Modules\MasterData\Models\Location::childLevelOf($location->level);
@endphp

<tr class="border-b border-(--color-border) transition-colors hover:bg-(--color-surface-hover)">
    <td>
        <span class="flex items-center gap-2" style="padding-inline-start: {{ $depth * 1.25 }}rem">
            @if ($location->children->isNotEmpty())
                <span aria-hidden="true" class="text-(--color-ink-placeholder)">▾</span>
            @else
                <span aria-hidden="true" class="w-3"></span>
            @endif

            <a href="{{ route('master_data.location.show', $location) }}"
               @class([
                   'min-w-0 truncate underline-offset-2 hover:underline',
                   'font-semibold' => $depth < 2,
                   'text-(--color-ink-muted)' => ! $location->is_active,
               ])>
                <span class="num text-(--color-ink-muted)">{{ $location->code }}</span>
                {{ $location->name() }}
            </a>

            @unless ($location->is_active)
                <x-ui.badge tone="neutral">{{ __('customer::state.inactive') }}</x-ui.badge>
            @endunless
        </span>
    </td>

    <td class="hidden whitespace-nowrap text-(--color-ink-muted) sm:table-cell">
        {{ __('master_data::level.' . $location->level) }}
    </td>

    {{-- রুটে কে যায় — রোজকার কাজে এই কলামটাই সবচেয়ে বেশি দেখা হয় --}}
    <td class="hidden text-(--color-ink-muted) lg:table-cell">
        {{ $location->assignee?->name ?? '' }}
    </td>

    <td class="text-end">
        @if ($childLevel !== null)
            @can('master_data.manage')
                <a href="{{ route('master_data.location.create', ['level' => $childLevel, 'parent' => $location->id]) }}"
                   class="inline-flex size-8 items-center justify-center rounded-(--radius-field)
                          text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)"
                   aria-label="{{ __('master_data::action.new') }} — {{ __('master_data::level.' . $childLevel) }}"
                   title="{{ __('master_data::level.' . $childLevel) }}">
                    <span aria-hidden="true">+</span>
                </a>
            @endcan
        @endif
    </td>
</tr>

@foreach ($location->children as $child)
    @include('master_data::location.partials.row', ['location' => $child, 'depth' => $depth + 1])
@endforeach
