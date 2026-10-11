{{-- ছকের একটা ঘর শেষ করা — শেষ দিনটা বাছাই, মোছা নয়। আগেই শেষ হওয়া ঘরে কিছু নেই। --}}
@if ($visit->effective_to === null || $visit->effective_to->gte($today))
    <form method="POST" action="{{ route('sales.route.visit.end', $visit) }}" class="flex items-center gap-2">
        @csrf
        <x-ui.date dense name="effective_to" :id="'effective-to-'.$visit->id" :required="true"
                   :value="$today->max($visit->effective_from)->toDateString()"
                   :min="$visit->effective_from->toDateString()"
                   aria-label="{{ __('sales::route.last_day') }}" />
        <x-ui.button type="submit" tone="secondary">{{ __('sales::route.end') }}</x-ui.button>
    </form>
@else
    <span class="text-(--color-ink-muted)">—</span>
@endif
