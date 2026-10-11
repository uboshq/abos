{{--
    ⭐ প্রোফাইলের অনুরোধ — তথ্য বদল চাওয়া, কাগজ তোলা, আর নিজের অনুরোধের অবস্থা (গ্রাহক পোর্টাল, ধাপ ১)।

    ⛔ এখান থেকে রেকর্ড বদলায় না — অনুরোধ অফিসে যায় ([[ProfileRequestService]]); অফিস "হলো না" বললে কারণটা এখানেই দেখা যায়।
--}}
<section data-boxed data-portal-profile-request
         class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
    <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 text-sm font-semibold">
        {{ __('sales::portal_profile.requests_title') }}
    </h2>

    <form method="POST" action="{{ route('sales.portal.profile.request') }}" class="grid gap-3 p-4 sm:grid-cols-2">
        @csrf
        <p class="text-sm text-(--color-ink-muted) sm:col-span-2">{{ __('sales::portal_profile.request_hint') }}</p>
        @foreach (['owner_name' => 'text', 'phone' => 'tel', 'email' => 'email'] as $field => $type)
            <label class="block">
                <span class="mb-1 block text-sm">{{ __('sales::portal_profile.'.$field) }}</span>
                <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field, $customer->getAttribute($field)) }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3">
            </label>
        @endforeach
        @foreach (['address_bn', 'address_en'] as $field)
            <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm">{{ __('sales::portal_profile.'.$field) }}</span>
                <textarea name="{{ $field }}" rows="2"
                          class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 py-2">{{ old($field, $customer->getAttribute($field)) }}</textarea>
            </label>
        @endforeach
        <label class="block sm:col-span-2">
            <span class="mb-1 block text-sm">{{ __('sales::portal_profile.note') }}</span>
            <input type="text" name="note" value="{{ old('note') }}" maxlength="500"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3">
        </label>
        <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-4 text-sm font-medium text-white sm:col-span-2">
            {{ __('sales::portal_profile.send_request') }}
        </button>
    </form>

    {{-- কাগজ — ট্রেড লাইসেন্স / NID / BIN; ছবি বা PDF, ৫ MB (জমার স্লিপের সেই একই পাহারা) --}}
    <form method="POST" action="{{ route('sales.portal.profile.paper') }}" enctype="multipart/form-data"
          class="grid gap-3 border-t border-(--color-border) p-4 sm:grid-cols-2" data-portal-paper-upload>
        @csrf
        <h3 class="text-sm font-semibold sm:col-span-2">{{ __('sales::portal_profile.papers_title') }}</h3>
        <label class="block">
            <span class="mb-1 block text-sm">{{ __('sales::portal_profile.paper_kind') }}</span>
            <select name="paper_kind" required
                    class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3">
                @foreach (\App\Modules\Customer\Models\ProfileRequest::PAPER_KINDS as $kind)
                    <option value="{{ $kind }}" @selected(old('paper_kind') === $kind)>{{ __('customer::profile_request.paper_'.$kind) }}</option>
                @endforeach
            </select>
        </label>
        <div class="block">
            <span class="mb-1 block text-sm">{{ __('sales::portal_profile.paper_file') }}</span>
            {{-- ⓘ বাংলা বোতাম — ব্রাউজারের ইংরেজি "Choose File" নয় ([[ui/file-input]]) --}}
            <x-ui.file-input name="paper" required accept="image/jpeg,image/png,image/webp,application/pdf" />
        </div>
        <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-brand-500) px-4 text-sm font-medium text-(--color-brand-500) sm:col-span-2">
            {{ __('sales::portal_profile.send_paper') }}
        </button>
    </form>

    <div class="border-t border-(--color-border) p-4">
        <h3 class="mb-2 text-sm font-semibold">{{ __('sales::portal_profile.my_requests') }}</h3>
        @if ($requests->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::portal_profile.no_requests') }}</p>
        @else
            <ul class="divide-y divide-(--color-border) text-sm" data-portal-my-requests>
                @foreach ($requests as $request)
                    <li class="py-2">
                        <div class="flex items-start justify-between gap-2">
                            <span class="font-medium">
                                {{ $request->kind === \App\Modules\Customer\Models\ProfileRequest::PAPER
                                    ? __('customer::profile_request.paper_'.$request->paper_kind)
                                    : __('customer::profile_request.kind_contact') }}
                            </span>
                            <x-ui.badge :tone="match ($request->status) { 'done' => 'success', 'refused' => 'danger', default => 'pending' }">
                                {{ __('customer::profile_request.status_'.$request->status) }}
                            </x-ui.badge>
                        </div>
                        <div class="text-2xs text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::formatWithTime($request->created_at) }}</div>
                        @if ($request->kind === \App\Modules\Customer\Models\ProfileRequest::CONTACT)
                            <div class="mt-1 text-(--color-ink-muted)">
                                @foreach ((array) $request->asked as $field => $value)
                                    <span class="me-2">{{ __('customer::profile_request.field_'.$field) }}: <span class="text-(--color-ink)">{{ $value !== '' ? $value : '—' }}</span></span>
                                @endforeach
                            </div>
                        @else
                            <a href="{{ route('sales.portal.profile.paper.show', $request->public_id) }}" target="_blank" rel="noopener"
                               class="text-(--color-brand-500) hover:underline">{{ __('sales::portal_profile.open_paper') }}</a>
                        @endif
                        @if ($request->decision_note)
                            <div class="mt-1">{{ $request->decision_note }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
