{{-- ⓘ গাছের একটা স্তর — নিজেকে ডেকে নিচে নামে; `$seen` চক্রে ঘুরতে দেয় না --}}
<ul class="{{ $depth > 0 ? 'ff-branch mt-2' : '' }} space-y-2">
    @foreach ($ids as $id)
        @continue(in_array($id, $seen, true) || ! isset($people[$id]))
        @php
            $p = $people[$id];
            $kids = $children[$id] ?? [];
            $areaCount = $p['areas']->count();
        @endphp
        <li data-person="{{ $id }}">
            <a href="{{ route('customer.fieldforce.index', ['person' => $id]) }}" class="ff-node" @if ($picked?->id === $id) aria-current="true" @endif>
                @if ($p['position'])
                    <span class="ff-pos" data-pos="{{ $p['position'] }}">{{ $p['position'] }}</span>
                @endif
                <span class="font-semibold">{{ $p['user']->name }}</span>

                @if ($p['walled'] && $p['depots'] === [])
                    <span class="ff-chip" data-tone="danger">{{ __('customer::fieldforce.no_depot_chip') }}</span>
                @endif
                @foreach ($p['depots'] as $depotId)
                    @if ($depotNames->has($depotId))
                        <span class="ff-chip" data-tone="depot">{{ $depotNames[$depotId] }}</span>
                    @endif
                @endforeach
                @if ($areaCount > 2)
                    <span class="ff-chip">{{ __('customer::fieldforce.areas_count', ['count' => $areaCount]) }}</span>
                @else
                    @foreach ($p['areas'] as $area)
                        <span class="ff-chip">{{ $areaName($area->location) }}</span>
                    @endforeach
                @endif

                <span class="ms-auto text-sm text-(--color-ink-muted) tabular-nums">
                    {{ $kids !== [] ? __('customer::fieldforce.below_all', ['count' => $p['dealers']]) : __('customer::fieldforce.dealers', ['count' => $p['dealers']]) }}
                </span>
            </a>

            @if ($kids !== [])
                @include('customer::fieldforce.partials.node', ['ids' => $kids, 'depth' => $depth + 1, 'seen' => [...$seen, $id]])
            @endif

            @if ($picked?->id === $id)
                <a href="{{ route('customer.fieldforce.index', ['under' => $id]) }}" class="ms-6 mt-1 inline-block text-sm text-(--color-brand-600) underline-offset-2 hover:underline">
                    {{ __('customer::fieldforce.add_under', ['name' => $p['user']->name]) }}
                </a>
            @endif
        </li>
    @endforeach
</ul>
