{{--
    ⭐ কোন কর্মী কোন ডিলারের — বাঁধা, হাতবদল, উপরওয়ালা, আর আগাম দেখা (⛔১৬, ২ অক্টোবর ২০২৬)।

    ⓘ মালিকের নিয়ম (১ অক্টোবর ২০২৬): টেবিল নয়, প্রতি লাইনে একটা জিনিস, ছোট লাইন — তাঁর
    পর্দায় ডান দিক কাটা পড়ে। তাই প্রতিটা সারি একটা কার্ড, নিচে নিচে।

    ⚠️ Alpine নেই, ইচ্ছাকৃত: সাধারণ ফর্ম, CSP-র ফাঁদে পড়ার মতো কিছু নেই।
--}}
@php
    $date = fn ($value) => \App\Core\Support\DateFormat::format($value);
    $card = 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4';
    $field = 'h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2';
    $label = 'mb-1 block text-sm text-(--color-ink-muted)';
    $customerName = fn ($c) => $c === null ? '—' : $c->code.' · '.(app()->getLocale() === 'bn' && filled($c->name_bn) ? $c->name_bn : $c->name_en);
    $areaName = fn ($l) => app()->getLocale() === 'bn' && filled($l->name_bn) ? $l->name_bn : $l->name_en;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('customer::binding.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('customer::binding.title')" :subtitle="__('customer::binding.subtitle')">
            <x-slot:actions>
                <a href="{{ route('customer.binding.tree') }}" class="text-sm text-(--color-brand-500) underline-offset-2 hover:underline">
                    {{ __('customer::binding.tree_link') }}
                </a>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <p data-switch="{{ $switchOn ? 'on' : 'off' }}" class="mb-4 text-sm">
        {{ $switchOn ? __('customer::binding.switch_on') : __('customer::binding.switch_off') }}
    </p>

    {{-- ⭐ আগাম দেখা — দেয়ালের ভিতরে শূন্য দেখা মানুষ লাল --}}
    <section data-boxed data-preview class="{{ $card }} mb-4">
        <h2 class="mb-2 font-semibold">{{ __('customer::binding.preview') }}</h2>
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('customer::binding.preview_hint') }}</p>

        <ul class="space-y-2">
            @foreach ($preview as $row)
                @php $blind = $row['walled'] && (int) $row['dealers'] === 0; @endphp
                <li data-staff="{{ $row['user']->id }}" data-blind="{{ $blind ? '1' : '0' }}"
                    class="rounded-(--radius-field) border px-3 py-2 text-sm {{ $blind ? 'border-(--color-danger) text-(--color-danger)' : 'border-(--color-border)' }}">
                    <div class="font-medium">{{ $row['user']->name }}</div>
                    <div>
                        @if ($row['walled'])
                            {{ __('customer::binding.sees_count', ['count' => (int) $row['dealers']]) }}
                        @else
                            {{ __('customer::binding.sees_all') }}
                        @endif
                    </div>
                    @if ($row['supervisor'] !== null)
                        <div class="text-xs text-(--color-ink-muted)">{{ __('customer::binding.under', ['name' => $row['supervisor']->name]) }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    {{-- বাঁধা --}}
    <section data-boxed class="{{ $card }} mb-4">
        <h2 class="mb-2 font-semibold">{{ __('customer::binding.bind') }}</h2>

        <form method="POST" action="{{ route('customer.binding.store') }}" class="space-y-3">
            @csrf
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.staff') }}</span>
                <select name="user_id" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" @selected(old('user_id') == $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.dealers') }}</span>
                <select name="customer_ids[]" multiple size="8" class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1">
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customerName($customer) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.or_area') }}</span>
                <select name="location_id" class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($areas as $area)
                        <option value="{{ $area->id }}">{{ $areaName($area) }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-(--color-ink-muted)">{{ __('customer::binding.area_hint') }}</span>
            </label>

            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.starts_on') }}</span>
                <input type="date" name="starts_on" required value="{{ old('starts_on', $today) }}" class="{{ $field }}">
            </label>

            <x-ui.button type="submit" tone="primary">{{ __('customer::binding.bind_button') }}</x-ui.button>
        </form>
    </section>

    {{-- হাতবদল --}}
    <section data-boxed class="{{ $card }} mb-4">
        <h2 class="mb-2 font-semibold">{{ __('customer::binding.handover') }}</h2>
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('customer::binding.handover_hint') }}</p>

        <form method="POST" action="{{ route('customer.binding.handover') }}" class="space-y-3">
            @csrf
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.from') }}</span>
                <select name="from_user_id" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.to') }}</span>
                <select name="to_user_id" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.on_date') }}</span>
                <input type="date" name="on_date" required value="{{ $today }}" class="{{ $field }}">
            </label>

            <x-ui.button type="submit" tone="secondary">{{ __('customer::binding.handover_button') }}</x-ui.button>
        </form>
    </section>

    {{-- উপরওয়ালা --}}
    <section data-boxed class="{{ $card }} mb-4">
        <h2 class="mb-2 font-semibold">{{ __('customer::binding.supervisor') }}</h2>
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('customer::binding.supervisor_hint') }}</p>

        <form method="POST" action="{{ route('customer.binding.supervisor') }}" class="space-y-3">
            @csrf
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.staff') }}</span>
                <select name="user_id" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.supervisor_of') }}</span>
                <select name="supervisor_id" class="{{ $field }}">
                    <option value="">{{ __('customer::binding.no_supervisor') }}</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>

            <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
        </form>
    </section>

    {{-- বাঁধনের তালিকা --}}
    <section data-boxed class="{{ $card }}">
        <h2 class="mb-2 font-semibold">{{ __('customer::binding.list') }}</h2>

        <form method="GET" class="mb-3 space-y-2">
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.staff') }}</span>
                <select name="user_id" class="{{ $field }}">
                    <option value="">{{ __('customer::binding.everyone') }}</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" @selected($filterUser === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="ended" value="1" @checked(request()->boolean('ended'))>
                {{ __('customer::binding.with_ended') }}
            </label>
            <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
        </form>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('customer::binding.none') }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($rows as $row)
                    <li data-binding="{{ $row->id }}" class="rounded-(--radius-field) border border-(--color-border) px-3 py-2 text-sm">
                        <div class="font-medium">{{ $customerName($row->customer) }}</div>
                        <div>{{ $row->user?->name }}</div>
                        <div class="text-xs text-(--color-ink-muted)">
                            {{ __('customer::binding.from_date', ['date' => $date($row->starts_on)]) }}
                        </div>
                        <div class="text-xs text-(--color-ink-muted)">
                            {{ $row->ends_on === null ? __('customer::binding.still_on') : __('customer::binding.until_date', ['date' => $date($row->ends_on)]) }}
                        </div>

                        @if ($row->ends_on === null)
                            <form method="POST" action="{{ route('customer.binding.end', $row) }}" class="mt-2 flex items-end gap-2">
                                @csrf
                                <input type="date" name="ends_on" required value="{{ $today }}" class="{{ $field }} max-w-48">
                                <x-ui.button type="submit" tone="secondary">{{ __('customer::binding.end_button') }}</x-ui.button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-3">
                <x-ui.pager :rows="$rows" />
            </div>
        @endif
    </section>
</x-layouts.app>
