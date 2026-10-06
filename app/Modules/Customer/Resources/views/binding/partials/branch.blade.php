{{-- ⓘ গাছের একটা স্তর — নিজেকে ডেকে নিচে নামে; `$seen` চক্রে ঘুরতে দেয় না --}}
<ul class="{{ $depth > 0 ? 'ms-3 border-s border-(--color-border) ps-3' : '' }} space-y-1">
    @foreach ($ids as $id)
        @continue(in_array($id, $seen, true) || ! isset($preview[$id]))
        @php $row = $preview[$id]; @endphp
        <li data-person="{{ $id }}" class="text-sm">
            <div class="font-medium">{{ $row['user']->name }}</div>
            <div class="text-xs text-(--color-ink-muted)">
                @if ($row['walled'])
                    {{ __('customer::binding.sees_count', ['count' => (int) $row['dealers']]) }}
                @else
                    {{ __('customer::binding.sees_all') }}
                @endif
            </div>

            @if (! empty($children[$id]))
                @include('customer::binding.partials.branch', ['ids' => $children[$id], 'depth' => $depth + 1, 'seen' => [...$seen, $id]])
            @endif
        </li>
    @endforeach
</ul>
