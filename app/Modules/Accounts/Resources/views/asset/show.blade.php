{{--
    একটা সম্পদের পাতা।

    উপরে চারটা সংখ্যা, নিচে মাসে মাসে ক্ষয়ের ইতিহাস। ইতিহাসটাই এই
    পাতার আসল কাজ: "গত জুনেরটা কি বসানো হয়েছিল" প্রশ্নের উত্তর আর
    কোথাও নেই।
--}}
@php
    /* কলাম ধরে, স্লটে নয় — কম্পোনেন্ট স্লট পড়ে না। */
    $columns = [
        [
            'key' => 'period_end',
            'label' => __('accounts::asset.period'),
            'render' => fn ($e) => $e->period_end?->format('M Y'),
        ],
        [
            'key' => 'amount',
            'label' => __('accounts::asset.amount'),
            'numeric' => true,
            'render' => fn ($e) => view('accounts::asset.partials.amount', ['value' => $e->amount]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $asset->name }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$asset->name"
                          :subtitle="$asset->document_no" />
    </x-slot:header>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['accounts::asset.cost', (string) $asset->cost],
            ['accounts::asset.accumulated', $asset->accumulated()],
            ['accounts::asset.book_value', $asset->bookValue()],
            ['accounts::asset.next_month', $nextAmount],
        ] as [$label, $value])
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3">
                <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="num text-xl font-semibold">{{ \App\Core\Support\Money::format($value) }}</p>
            </div>
        @endforeach
    </div>

    @include('accounts::asset.partials.details')

    {{-- ⭐ দায়িত্বে থাকা কর্মীর স্বীকৃতি আর লেবেল (ধাপ ৪) --}}
    <div class="mb-5 flex flex-wrap items-center gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3 text-sm">
        <span class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('accounts::asset.custodian_ack') }}</span>
        @if ($asset->custodian_id === null)
            <span>—</span>
        @elseif ($ack = $asset->acknowledgedByCustodian())
            <x-ui.badge tone="success">{{ __('accounts::asset.ack_done', ['date' => $ack->acknowledged_at?->format('d M Y')]) }} · {{ __('accounts::asset.ack_'.$ack->condition) }}</x-ui.badge>
        @else
            <x-ui.badge tone="pending">{{ __('accounts::asset.custodian_ack_none') }}</x-ui.badge>
        @endif
        <span class="ms-auto">
            <x-ui.button tone="secondary" icon="printer" :href="route('accounts.asset.labels', ['assets' => [$asset->id]])" target="_blank">
                {{ __('accounts::asset.labels_action') }}
            </x-ui.button>
        </span>
    </div>

    @include('accounts::asset.partials.estimate')

    @include('accounts::asset.partials.events')

    {{-- ── ⭐ শাখা বদল — মানচিত্র §১৫, ২১ সেপ্টেম্বর ২০২৬ ──────────────

         ⚠️ কেন কেবল একটা কলাম বদলানো যথেষ্ট নয়: ফ্রিজটা ঢাকা থেকে খুলনায়
         গেলে **দুইটা শাখার স্থিতিপত্রই** বদলায় — একটা থেকে সম্পদ যায়,
         অন্যটায় আসে। ⓘ তাই দাখিলা বসে, আর সঞ্চিত ক্ষয়টাও সাথে যায়।

         ⓘ ইতিহাসটা নিচে থাকে, কারণ "গত বছর এটা কোথায় ছিল" প্রশ্নটা
         ছয় মাস পরে ওঠে, আর তখন উত্তর দেওয়ার মতো আর কিছু থাকে না। --}}
    {{-- ⭐ ধাপ ৩: কর্মী, জায়গা আর বিভাগও — শাখা না বদলালে দাখিলা নেই --}}
    @if ($asset->isInService())
        @can('accounts.asset.manage')
            <form method="POST" action="{{ route('accounts.asset.transfer', $asset) }}"
                  class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                         bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-4">
                @csrf

                <div class="lg:col-span-4">
                    <p class="text-sm font-semibold">{{ __('accounts::asset.transfer') }}</p>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.transfer_hint') }}</p>
                </div>

                <x-ui.select name="to_branch_id"
                             :label="__('accounts::asset.to_branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])->all()"
                             :placeholder="__('accounts::asset.same_branch')" />

                <x-ui.select name="custodian_id" :label="__('accounts::asset.custodian')"
                             :options="$employees" :selected="$asset->custodian_id" placeholder="—" />

                <x-ui.field name="location" :label="__('accounts::asset.location')" :value="$asset->location" />

                <x-ui.field name="department" :label="__('accounts::asset.department')" :value="$asset->department" />

                <label class="block">
                    <span class="text-sm font-medium">{{ __('accounts::asset.moved_on') }}</span>
                    <x-ui.date name="moved_on" :required="true" :value="now()->toDateString()" />
                </label>

                <x-ui.field name="note" :label="__('core.table.narration')" :value="old('note')" />

                <div class="flex items-end">
                    <x-ui.button type="submit" tone="secondary">
                        {{ __('accounts::asset.transfer') }}
                    </x-ui.button>
                </div>
            </form>
        @endcan
    @endif

    @if ($moves->isNotEmpty())
        <section data-boxed
                 class="mb-5 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">
                {{ __('accounts::asset.move_history') }}
            </h2>

            <ul class="divide-y divide-(--color-border) text-sm">
                @foreach ($moves as $move)
                    <li class="flex flex-wrap items-center gap-x-3 px-4 py-2">
                        <span class="tabular-nums text-(--color-ink-muted)">{{ $move->moved_on?->format('d M Y') }}</span>
                        @if ($move->movedBranch())
                            <span>{{ $move->fromBranch?->name() ?? '—' }} → {{ $move->toBranch?->name() ?? '—' }}</span>
                        @endif
                        @if ((int) $move->from_custodian_id !== (int) $move->to_custodian_id)
                            <span>{{ __('accounts::asset.custodian') }}: {{ $people[$move->from_custodian_id] ?? '—' }} → {{ $people[$move->to_custodian_id] ?? '—' }}</span>
                        @endif
                        @if ((string) $move->from_location !== (string) $move->to_location)
                            <span>{{ __('accounts::asset.location') }}: {{ $move->from_location ?? '—' }} → {{ $move->to_location ?? '—' }}</span>
                        @endif
                        @if ((string) $move->from_department !== (string) $move->to_department)
                            <span>{{ __('accounts::asset.department') }}: {{ $move->from_department ?? '—' }} → {{ $move->to_department ?? '—' }}</span>
                        @endif
                        @if (filled($move->note))
                            <span class="text-2xs text-(--color-ink-muted)">{{ $move->note }}</span>
                        @endif
                        <span class="ms-auto text-2xs text-(--color-ink-muted)">{{ $move->creator?->name }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($asset->isInService())
        @can('accounts.asset.manage')
            <form method="POST" action="{{ route('accounts.asset.dispose', $asset) }}"
                  class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                         bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-4">
                @csrf

                <div class="lg:col-span-4">
                    <p class="text-sm font-semibold">{{ __('accounts::asset.dispose_title') }}</p>
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.dispose_hint') }}</p>
                </div>

                {{-- ⭐ বিক্রি, বাতিল (ভাঙারি) বা হারানো/চুরি — ধাপ ৩ --}}
                <x-ui.select name="as" :label="__('accounts::asset.leaving_as')" required
                             :options="collect(\App\Modules\Accounts\Models\FixedAsset::LEAVING)->mapWithKeys(fn ($s) => [$s => __('accounts::asset.leaving_'.$s)])->all()" />

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.disposal_amount') }}</span>
                    <input type="number" step="0.01" min="0" name="disposal_amount" required value="0"
                           class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-2 text-end">
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.into_account') }}</span>
                    <select name="into_account_id"
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2">
                        <option value="">—</option>
                        @foreach ($moneyAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium">{{ __('accounts::asset.disposed_on') }}</span>
                    <x-ui.date name="disposed_on" :required="true" :value="now()->toDateString()" />
                </label>

                <div class="lg:col-span-3">
                    <x-ui.field name="reason" :label="__('accounts::asset.leaving_reason')" />
                </div>

                <div class="flex items-end">
                    <x-ui.button type="submit" class="w-full">
                        {{ __('accounts::asset.dispose_action') }}
                    </x-ui.button>
                </div>
            </form>
        @endcan
    @endif

    <x-ui.table :rows="$asset->depreciation"
                :columns="$columns"
                :empty="__('accounts::asset.empty_entries')" />

    {{-- ⭐ ছবি আর কাগজ — রসিদ, ওয়ারেন্টি কার্ড, জিনিসের ছবি (স্থায়ী সম্পদ ধাপ ১; সংযুক্তির ইঞ্জিন) --}}
    <div class="mt-5">
        <x-ui.attachments :document="$asset" />
    </div>

</x-layouts.app>
