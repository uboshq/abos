{{--
    ⭐ সম্পদের পরিচয় — শ্রেণি, জায়গা, কার হাতে, কোন বিল থেকে, ওয়ারেন্টি আর বীমা (স্থায়ী সম্পদ ধাপ ১, ১০ অক্টোবর ২০২৬)।

    ⓘ বাম দিকে পরিচয়, ডান দিকে কেনা দামের ভাগ আর অংশ — গুদামে দাঁড়িয়ে প্রথম প্রশ্ন "এটা কোনটা, কার হাতে"।
    ক্রয় বিলের নম্বর ভাগ করা ঝলক-পর্দায় খোলে ([[components/ui/drill]])।
--}}
@php
    $facts = [
        ['accounts::asset.status', view('accounts::asset.partials.status', ['asset' => $asset])],
        ['accounts::asset.category', $asset->category?->label()],
        ['accounts::asset.tag_no', $asset->tag_no],
        ['accounts::asset.branch', $asset->branch?->name()],
        ['accounts::asset.location', $asset->location],
        ['accounts::asset.department', $asset->department],
        ['accounts::asset.custodian', $custodian],
        ['accounts::asset.supplier', $supplier],
        ['accounts::asset.acquired_on', $asset->acquired_on?->format('d M Y')],
        ['accounts::asset.put_in_use_on', $asset->put_in_use_on?->format('d M Y')],
        ['accounts::asset.serial_no', $asset->serial_no],
        ['accounts::asset.model_no', $asset->model_no],
        ['accounts::asset.warranty_ends_on', $asset->warranty_ends_on?->format('d M Y')],
        ['accounts::asset.insurance_policy_no', $asset->insurance_policy_no],
        ['accounts::asset.insured_until', $asset->insured_until?->format('d M Y')],
    ];
@endphp

<div class="mb-5 grid gap-3 lg:grid-cols-3">
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) lg:col-span-2">
        <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.details') }}</h2>
        <dl class="grid gap-x-6 gap-y-2 px-4 py-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($facts as [$label, $value])
                @if (filled($value))
                    <div class="min-w-0">
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="truncate">{{ $value }}</dd>
                    </div>
                @endif
            @endforeach

            @if ($billLine !== null)
                <div class="min-w-0">
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.bill_line') }}</dt>
                    <dd class="truncate">
                        <x-ui.drill source="purchase_bill" :id="$billLine['bill_id']">{{ $billLine['bill_no'] }}</x-ui.drill>
                        · {{ $billLine['product'] }} × {{ \App\Core\Support\Money::quantity((string) $asset->capitalised_qty) }}
                    </dd>
                </div>
            @endif

            @if ($asset->parent !== null)
                <div class="min-w-0">
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.parent') }}</dt>
                    <dd class="truncate">
                        <a href="{{ route('accounts.asset.show', $asset->parent) }}" class="text-(--color-brand-500) hover:underline">
                            {{ $asset->parent->document_no }} — {{ $asset->parent->name }}
                        </a>
                    </dd>
                </div>
            @endif
        </dl>
    </section>

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.cost_parts') }}</h2>
        @if ($asset->costParts->isEmpty())
            <p class="px-4 py-3 text-sm text-(--color-ink-muted)">{{ __('accounts::asset.no_cost_parts') }}</p>
        @else
            <ul class="divide-y divide-(--color-border) text-sm">
                @foreach ($asset->costParts as $part)
                    <li class="flex items-center gap-3 px-4 py-2">
                        <span class="min-w-0 flex-1 truncate">{{ __('accounts::asset.part_'.$part->kind) }}{{ filled($part->note) ? ' — '.$part->note : '' }}</span>
                        <span class="num tabular-nums">{{ \App\Core\Support\Money::format((string) $part->amount) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($asset->components->isNotEmpty())
            <h2 class="border-y border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.components') }}</h2>
            <ul class="divide-y divide-(--color-border) text-sm">
                @foreach ($asset->components as $part)
                    <li class="flex items-center gap-3 px-4 py-2">
                        <a href="{{ route('accounts.asset.show', $part) }}" class="min-w-0 flex-1 truncate text-(--color-brand-500) hover:underline">
                            {{ $part->document_no }} — {{ $part->name }}
                        </a>
                        <span class="num tabular-nums">{{ \App\Core\Support\Money::format((string) $part->cost) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

{{-- ⭐ অবস্থা বদল — ব্যবহারে, অলস, মেরামতে; টাকা নড়ে না (ধাপ ১) --}}
@if ($asset->isInService())
    @can('accounts.asset.manage')
        <form method="POST" action="{{ route('accounts.asset.status', $asset) }}"
              class="mb-5 flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            <x-ui.select name="status" :label="__('accounts::asset.change_status')"
                         :options="collect(\App\Modules\Accounts\Models\FixedAsset::SWITCHABLE)->mapWithKeys(fn ($s) => [$s => __('accounts::asset.status_'.$s)])->all()"
                         :selected="$asset->status" required />
            <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
        </form>
    @endcan
@endif
