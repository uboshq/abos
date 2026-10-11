{{--
    ⓘ পোর্টালের অনুরোধ — সিদ্ধান্ত সারি থেকেই। "বসানো হয়েছে" কেবল চিহ্ন: বদলটা আগে গ্রাহকের সম্পাদনায় বসান।
    "হলো না"-তে কারণ বাধ্যতামূলক — গ্রাহক পোর্টালে দেখেন।
--}}
@if ($row->isOpen())
    <div class="flex flex-wrap items-end gap-2">
        @if ($row->kind === \App\Modules\Customer\Models\ProfileRequest::CONTACT && $row->customer)
            <a href="{{ route('customer.edit', $row->customer) }}" class="text-sm text-(--color-brand-500) hover:underline">
                {{ __('customer::profile_request.edit_customer') }}
            </a>
        @endif
        <form method="POST" action="{{ route('customer.profile_request.decide', $row) }}">
            @csrf
            <input type="hidden" name="status" value="done">
            <x-ui.button type="submit" tone="primary">{{ __('customer::profile_request.mark_done') }}</x-ui.button>
        </form>
        <form method="POST" action="{{ route('customer.profile_request.decide', $row) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <input type="hidden" name="status" value="refused">
            <input type="text" name="decision_note" required placeholder="{{ __('customer::profile_request.decision_note') }}"
                   class="h-(--spacing-field-compact) w-40 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
            <x-ui.button type="submit">{{ __('customer::profile_request.mark_refused') }}</x-ui.button>
        </form>
    </div>
@else
    <x-ui.badge :tone="$row->status === 'done' ? 'success' : 'danger'">{{ __('customer::profile_request.status_'.$row->status) }}</x-ui.badge>
    <span class="block text-2xs text-(--color-ink-muted)">
        {{ $row->decider?->name }} · {{ \App\Core\Support\DateFormat::formatWithTime($row->decided_at) }}
    </span>
    @if ($row->decision_note)
        <span class="block text-sm">{{ $row->decision_note }}</span>
    @endif
@endif
