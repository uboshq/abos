{{--
    কাউন্টারের শিফট — খোলা, চলমান, বন্ধ।

    ── এক পর্দায় তিনটা অবস্থা কেন ───────────────────────────────────────
    ক্যাশিয়ারের কাছে এটা একটাই জায়গা: সকালে খুলি, দিনভর দেখি, রাতে
    বন্ধ করি। তিনটা আলাদা পাতা বানালে "আমার শিফটটা কোথায়" প্রশ্নের
    উত্তর প্রতিবার আলাদা হত।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        ['key' => 'till', 'label' => __('sales::field.till'),
         'render' => fn ($r) => $r->till?->name()],
        ['key' => 'user', 'label' => __('sales::field.user'),
         'render' => fn ($r) => $r->user?->name],
        ['key' => 'time', 'label' => __('sales::field.time'), 'width' => '9rem',
         'render' => fn ($r) => $r->opened_at?->format('H:i').'-'.$r->closed_at?->format('H:i')],
        ['key' => 'counted', 'label' => __('sales::message.shift_counted'),
         'numeric' => true, 'width' => '11rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r->closing_counted)],
        ['key' => 'view', 'label' => '', 'width' => '6rem',
         'render' => fn ($r) => view('sales::shift.partials.view', ['row' => $r])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.shift') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.shift')"
                          :subtitle="__('sales::message.shift_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
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

    @if ($shift === null)
        {{-- খোলা নেই — খোলার ফর্ম --}}
        <form method="POST" action="{{ route('sales.shift.open') }}"
              class="rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4">
            @csrf

            <p class="mb-3 text-sm text-(--color-ink-muted)">{{ __('sales::message.shift_none_open') }}</p>

            @if ($tills->isEmpty())
                <p class="text-sm">{{ __('sales::message.shift_no_till') }}</p>
            @else
                <div class="flex flex-wrap items-end gap-3">
                    <label class="min-w-56 flex-1">
                        <span class="mb-1 block text-sm font-medium">{{ __('sales::field.till') }}</span>
                        <select name="cash_till_id" required
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-app) px-3">
                            @foreach ($tills as $till)
                                <option value="{{ $till->id }}">{{ $till->code }} — {{ $till->name() }}</option>
                            @endforeach
                        </select>
                    </label>

                    {{-- খোলার সময় গোনা টাকা — ফ্লোট। এটা না চাইলে দিনশেষের
                         পার্থক্যটা অর্থহীন হত: কত ছিল তা-ই তো জানা নেই। --}}
                    <label class="min-w-40">
                        <span class="mb-1 block text-sm font-medium">{{ __('sales::message.shift_opening') }}</span>
                        <input type="number" name="opening_counted" step="0.01" inputmode="decimal"
                               required value="0"
                               class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-app) px-3 text-end">
                    </label>

                    <x-ui.button type="submit" tone="primary">{{ __('sales::action.shift_open') }}</x-ui.button>
                </div>
            @endif
        </form>
    @else
        {{-- চলমান শিফট — চলতি হিসাব ও বন্ধ করার ফর্ম --}}
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'shift_opening' => $figures['opening'],
                'shift_cash_in' => $figures['cash_in'],
                'shift_cash_out' => $figures['cash_out'],
                'shift_expected' => $figures['expected'],
            ] as $label => $value)
                <div class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                    <div class="text-sm text-(--color-ink-muted)">{{ __('sales::message.'.$label) }}</div>
                    <div class="num mt-1 text-xl font-semibold">{{ \App\Core\Support\Money::format($value) }}</div>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('sales.shift.close', ['shift' => $shift->id]) }}"
              class="rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4">
            @csrf

            <p class="mb-3 text-sm">
                {{ $shift->till?->name() }} ·
                <span class="text-(--color-ink-muted)">
                    {{ \App\Core\Support\DateFormat::format($shift->opened_at) }}
                    {{ $shift->opened_at?->format('H:i') }}
                </span>
                · {{ __('sales::message.shift_bills') }}: <span class="num">{{ $figures['bills'] }}</span>
            </p>

            <div class="flex flex-wrap items-end gap-3">
                {{-- দিনশেষে গোনা টাকা। খাতার সংখ্যাটা পাশেই দেখা যাচ্ছে,
                     আর সেটা ইচ্ছাকৃত: লুকিয়ে রাখলে ক্যাশিয়ার আন্দাজে
                     লিখতেন, আর পার্থক্য চিরকাল শূন্য দেখাত। --}}
                <label class="min-w-40">
                    <span class="mb-1 block text-sm font-medium">{{ __('sales::message.shift_counted') }}</span>
                    <input type="number" name="closing_counted" step="0.01" inputmode="decimal" required
                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-app) px-3 text-end">
                </label>

                <label class="min-w-56 flex-1">
                    <span class="mb-1 block text-sm font-medium">{{ __('sales::field.narration') }}</span>
                    <input type="text" name="narration" maxlength="500"
                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-app) px-3">
                </label>

                <x-ui.button type="submit" tone="primary">{{ __('sales::action.shift_close') }}</x-ui.button>
            </div>
        </form>
    @endif

    @if ($closed->isNotEmpty())
        <h2 class="mt-6 mb-2 text-sm font-semibold">{{ __('sales::message.shift_today') }}</h2>

        <div class="table-responsive rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <x-ui.table :rows="$closed"
                    :columns="$columns"
                    :empty="__('core.empty.no_results')" />
        </div>
    @endif
</x-layouts.app>
