{{--
    ⭐ সম্পদের জীবনের ঘটনা — সংযোজন, মেরামত, পুনর্মূল্যায়ন, দাম পড়া (স্থায়ী সম্পদ ধাপ ৩; IAS 16, IAS 36)।
    ⓘ প্রতিটা একটা ভাঁজ করা ফর্ম — পাতা লম্বা হয় না, জাভাস্ক্রিপ্টও লাগে না। টাকা নড়লে সই চাওয়া হয়; নিচে ইতিহাস।
--}}
@php
    $box = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2';
    $paidFrom = fn (bool $logOnly) => collect(['money', 'credit'])
        ->when($logOnly, fn ($c) => $c->push('already'))
        ->mapWithKeys(fn ($way) => [$way => __('accounts::asset.paid_'.$way)])->all();
@endphp

@if ($asset->isInService())
    @can('accounts.asset.manage')
        <section data-boxed class="mb-5 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.events_title') }}</h2>

            @foreach (\App\Modules\Accounts\Models\AssetEvent::KINDS as $kind)
                @continue($kind === \App\Modules\Accounts\Models\AssetEvent::REVALUATION && ! $revaluationOn)
                <details class="border-b border-(--color-border) last:border-b-0" @if (old('kind') === $kind) open @endif>
                    <summary class="cursor-pointer px-4 py-2 text-sm font-medium">
                        {{ __('accounts::asset.event_'.$kind) }}
                        <span class="text-2xs font-normal text-(--color-ink-muted)">— {{ __('accounts::asset.event_'.$kind.'_hint') }}</span>
                    </summary>

                    <form method="POST" action="{{ route('accounts.asset.event', [$asset, $kind]) }}"
                          class="grid gap-3 px-4 pb-4 md:grid-cols-3 lg:grid-cols-6">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">

                        <x-ui.field name="amount" type="number" numeric required
                                    :label="__('accounts::asset.event_amount_'.$kind)" :value="old('kind') === $kind ? old('amount') : null" />

                        <label class="flex flex-col gap-1">
                            <span class="text-sm font-medium">{{ __('accounts::asset.happened_on') }}</span>
                            <x-ui.date name="happened_on" :required="true" :value="now()->toDateString()" />
                        </label>

                        @if (in_array($kind, ['addition', 'repair'], true))
                            <x-ui.select name="funded_by" :label="__('accounts::asset.paid_from')" required
                                         :options="$paidFrom($kind === 'repair')" />
                            <x-ui.select name="funding_account_id" :label="__('accounts::asset.paid_money_account')"
                                         :options="$moneyAccounts->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all()" placeholder="—" />
                            <x-ui.select name="funding_supplier_id" :label="__('accounts::asset.vendor')"
                                         :options="$suppliers" placeholder="—" />
                        @endif

                        @if ($kind === 'addition' && $asset->method === \App\Modules\Accounts\Models\FixedAsset::STRAIGHT_LINE)
                            <x-ui.field name="extend_months" type="number" numeric :label="__('accounts::asset.extend_months')" />
                        @endif

                        @if ($kind === 'repair')
                            <x-ui.select name="charge_account_id" :label="__('accounts::asset.repair_account')"
                                         :options="$expenseAccounts->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all()" placeholder="—" />
                        @endif

                        @if ($kind === 'revaluation')
                            <x-ui.select name="account_id" :label="__('accounts::asset.surplus_account')" required
                                         :options="$equityAccounts->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all()" placeholder="—" />
                        @endif

                        <div class="md:col-span-2">
                            <x-ui.field name="reason" :label="__('accounts::asset.event_reason')"
                                        :required="in_array($kind, ['revaluation', 'impairment'], true)" />
                        </div>

                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
                        </div>
                    </form>
                </details>
            @endforeach
        </section>
    @endcan
@endif

@if ($asset->events->isNotEmpty())
    <section data-boxed class="mb-5 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.event_history') }}</h2>
        <ul class="divide-y divide-(--color-border) text-sm">
            @foreach ($asset->events as $event)
                <li class="flex flex-wrap items-center gap-x-3 px-4 py-2">
                    <span class="tabular-nums text-(--color-ink-muted)">{{ $event->happened_on?->format('d M Y') }}</span>
                    <x-ui.drill source="asset_event" :id="$event->id">{{ $event->document_no }}</x-ui.drill>
                    <span>{{ $event->kindLabel() }}</span>
                    @if ($event->isAwaiting())
                        <x-ui.badge tone="pending">{{ __('accounts::asset.status_awaiting') }}</x-ui.badge>
                    @endif
                    @if (filled($event->reason))
                        <span class="min-w-0 truncate text-2xs text-(--color-ink-muted)">{{ $event->reason }}</span>
                    @endif
                    <span class="num ms-auto tabular-nums">{{ \App\Core\Support\Money::format((string) $event->amount) }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
