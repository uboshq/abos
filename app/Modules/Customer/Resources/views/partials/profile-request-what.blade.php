{{--
    ⓘ পোর্টালের অনুরোধ — কী চাওয়া হলো। তথ্য বদলে "ছিল → চাওয়া হলো" পাশাপাশি; কাগজে ধরন আর ফাইল।
--}}
@if ($row->kind === \App\Modules\Customer\Models\ProfileRequest::PAPER)
    <span class="font-medium">{{ __('customer::profile_request.paper_'.$row->paper_kind) }}</span>
    <a href="{{ route('customer.profile_request.paper', $row) }}" target="_blank" rel="noopener" data-profile-request-paper
       class="ms-2 text-sm text-(--color-brand-500) hover:underline">{{ __('customer::profile_request.open_paper') }}</a>
@else
    <ul class="text-sm" data-profile-request-changes>
        @foreach ((array) $row->asked as $field => $value)
            <li>
                <span class="text-(--color-ink-muted)">{{ __('customer::profile_request.field_'.$field) }}:</span>
                <span class="line-through text-(--color-ink-muted)">{{ ($row->was[$field] ?? '') !== '' ? $row->was[$field] : '—' }}</span>
                → <span class="font-medium">{{ $value !== '' ? $value : '—' }}</span>
            </li>
        @endforeach
    </ul>
@endif
@if ($row->note)
    <span class="block text-2xs text-(--color-ink-muted)">{{ $row->note }}</span>
@endif
