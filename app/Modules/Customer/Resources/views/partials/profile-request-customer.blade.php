{{-- ⓘ পোর্টালের অনুরোধ — কোন গ্রাহক; নামটা গ্রাহকের পাতায় নেয় ([[ProfileRequestController]]) --}}
@if ($row->customer)
    @include('customer::partials.name-link', ['customer' => $row->customer, 'text' => $row->customer->name()])
    <span class="block text-2xs text-(--color-ink-muted)">{{ $row->customer->code }}</span>
@endif
