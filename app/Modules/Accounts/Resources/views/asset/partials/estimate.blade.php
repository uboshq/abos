{{--
    ⭐ আয়ু, শেষ দাম, পদ্ধতি, হার বা মোট একক বদল — আগামীর দিকে, কারণসহ; নিচে ইতিহাস (স্থায়ী সম্পদ ধাপ ২; IAS 16.51)।
    ⓘ বসে যাওয়া অবচয় ছোঁয়া হয় না; পরের মাস থেকে বাকি দাম বাকি আয়ুতে ভাগ হয়। ব্যবহারের এককের সম্পদে মাসের এককও এখানে।
--}}
@if ($asset->isInService())
    @can('accounts.asset.manage')
        <form method="POST" action="{{ route('accounts.asset.estimate', $asset) }}"
              x-data="{ method: @js($asset->method) }"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 md:grid-cols-3 lg:grid-cols-6">
            @csrf
            <div class="md:col-span-3 lg:col-span-6">
                <p class="text-sm font-semibold">{{ __('accounts::asset.estimate_title') }}</p>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.estimate_hint') }}</p>
            </div>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.method') }}</span>
                <select name="method" x-model="method"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
                    @foreach (\App\Modules\Accounts\Models\FixedAsset::METHODS as $method)
                        <option value="{{ $method }}">{{ __('accounts::asset.'.$method) }}</option>
                    @endforeach
                </select>
            </label>
            <div x-show="method === 'straight'">
                <x-ui.field name="life_months" type="number" numeric :label="__('accounts::asset.life_months')" :value="$asset->life_months" />
            </div>
            <div x-show="method === 'reducing'" x-cloak>
                <x-ui.field name="rate" type="number" numeric :label="__('accounts::asset.rate')" :value="$asset->rate" />
            </div>
            <div x-show="method === 'units'" x-cloak>
                <x-ui.field name="total_units" type="number" numeric :label="__('accounts::asset.total_units')" :value="$asset->total_units" />
            </div>
            <x-ui.field name="salvage" type="number" numeric :label="__('accounts::asset.salvage')" :value="$asset->salvage" />
            <div class="lg:col-span-2">
                <x-ui.field name="reason" :label="__('accounts::asset.estimate_reason')" required />
            </div>
            <div class="flex items-end">
                <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        </form>

        @if ($asset->method === \App\Modules\Accounts\Models\FixedAsset::UNITS)
            <form method="POST" action="{{ route('accounts.asset.usage', $asset) }}"
                  class="mb-5 flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf
                <p class="w-full text-sm font-semibold">{{ __('accounts::asset.usage_title') }}</p>
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.run_month') }}</span>
                    <input type="month" name="month" required value="{{ now()->subMonthNoOverflow()->format('Y-m') }}"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
                </label>
                <x-ui.field name="units" type="number" numeric :label="__('accounts::asset.units_used')" required />
                <x-ui.field name="note" :label="__('core.table.narration')" />
                <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        @endif
    @endcan
@endif

@if ($asset->estimateChanges->isNotEmpty() || $asset->usages->isNotEmpty())
    <div class="mb-5 grid gap-3 lg:grid-cols-2">
        @if ($asset->estimateChanges->isNotEmpty())
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.estimate_history') }}</h2>
                <ul class="divide-y divide-(--color-border) text-sm">
                    @foreach ($asset->estimateChanges as $change)
                        <li class="px-4 py-2">
                            <div class="flex flex-wrap gap-x-3">
                                <span class="tabular-nums text-(--color-ink-muted)">{{ $change->changed_on?->format('d M Y') }}</span>
                                <span>{{ $change->reason }}</span>
                                <span class="ms-auto text-2xs text-(--color-ink-muted)">{{ $change->creator?->name }}</span>
                            </div>
                            <p class="text-2xs text-(--color-ink-muted)">
                                @foreach ($change->after as $field => $value)
                                    @if (($change->before[$field] ?? null) !== $value)
                                        {{ __('accounts::asset.estimate_field_'.$field) }}: {{ $change->before[$field] ?? '—' }} → {{ $value ?? '—' }}
                                    @endif
                                @endforeach
                            </p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($asset->usages->isNotEmpty())
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.usage_title') }}</h2>
                <ul class="divide-y divide-(--color-border) text-sm">
                    @foreach ($asset->usages as $usage)
                        <li class="flex items-center gap-3 px-4 py-2">
                            <span class="tabular-nums text-(--color-ink-muted)">{{ $usage->period_end?->format('M Y') }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $usage->note }}</span>
                            <span class="num tabular-nums">{{ \App\Core\Support\Money::quantity((string) $usage->units) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endif
