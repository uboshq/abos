{{--
    রিকল — এই লটটা কাদের কাছে গেছে।

    ── কেন দুইটা সংখ্যা আলাদা ───────────────────────────────────────────
    তাকে যা পড়ে আছে সেটা এখনই আটকানো যায় — হাতের কাজ। গ্রাহকের কাছে যা
    চলে গেছে সেটা ফেরত আনতে ফোন করতে হয়। একটা যোগফলে মিলিয়ে দিলে
    দোকানি জানতেন কত পিস বাজারে আছে, কিন্তু কোনটা নিয়ে কী করবেন জানতেন
    না।

    ── কেন ফোন নম্বর তালিকায় ────────────────────────────────────────────
    রিকলের মুহূর্তে পরের কাজটাই ফোন করা। নম্বরটা না থাকলে প্রতিটা নামের
    জন্য গ্রাহকের পাতা খুলতে হত — তিরিশজন গ্রাহকে তিরিশবার।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        ['key' => 'trx_date', 'label' => __('sales::field.date'), 'width' => '9rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->trx_date)],
        ['key' => 'document_no', 'label' => __('sales::doc.challan'),
         'render' => fn ($r) => view('sales::lot.partials.challan', ['row' => $r])],
        ['key' => 'customer', 'label' => __('sales::field.customer'),
         'render' => fn ($r) => app()->getLocale() === 'bn' && $r->customer_bn
             ? $r->customer_bn : $r->customer_en],
        ['key' => 'phone', 'label' => __('inventory::field.phone'), 'width' => '10rem',
         'render' => fn ($r) => view('sales::lot.partials.phone', ['row' => $r])],
        ['key' => 'qty', 'label' => __('sales::field.quantity'), 'numeric' => true,
         'width' => '8rem',
         // পিছনের শূন্য কাটা — ৫.০০০ নয়, ৫
         'render' => fn ($r) => rtrim(rtrim((string) $r->qty, '0'), '.')],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.lot_trace') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.lot_trace')"
                          :subtitle="__('sales::message.trace_note')" />
    </x-slot:header>

    {{-- লট বাছা — GET, কারণ এটা প্রশ্ন, কিছু বদলায় না। ফলে লিংকটা কপি
         করে পাঠানো যায়, আর ব্রাউজারের পেছনে যাওয়াও ভাঙে না। --}}
    <form method="GET" action="{{ route('sales.lot.trace') }}"
          class="mb-4 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4">
        <div class="flex flex-wrap items-end gap-3">
            <label class="min-w-64 flex-1">
                <span class="mb-1 block text-sm font-medium">{{ __('inventory::field.batch_no') }}</span>

                <select name="batch"
                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                               border-(--color-border) bg-(--color-surface-app) px-3">
                    <option value="">-</option>
                    @foreach ($batches as $row)
                        <option value="{{ $row->id }}" @selected($batch?->id === $row->id)>
                            {{ $row->product?->name() }} — {{ $row->label() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <x-ui.button type="submit" tone="primary">{{ __('inventory::action.trace') }}</x-ui.button>
        </div>
    </form>

    @if ($batch !== null)
        {{-- দুইটা সংখ্যা, দুইটা করণীয় --}}
        <div class="mb-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <div class="text-sm text-(--color-ink-muted)">{{ __('sales::message.trace_on_hand') }}</div>
                <div class="num mt-1 text-2xl font-semibold">{{ rtrim(rtrim($onHand, '0'), '.') ?: '0' }}</div>
                <p class="mt-1 text-2xs text-(--color-ink-muted)">
                    {{ __('sales::message.trace_on_hand_note') }}
                </p>
            </div>

            <div class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <div class="text-sm text-(--color-ink-muted)">{{ __('sales::message.trace_gone') }}</div>
                <div class="num mt-1 text-2xl font-semibold">
                    {{-- যোগফলটা bcmath-এ, float-এ নয়।

                         আগে এখানে `sum(fn ($r) => (float) $r->qty)` ছিল —
                         অর্থাৎ রিকলের মোট পরিমাণটা float-এ যোগ হত। একশো
                         সারিতে ০.১ কেজি করে যোগ করলে ফলটা ১০ হয় না, আর
                         রিকলে ওই ভুলের মানে হলো কিছু মাল হিসাবের বাইরে
                         থেকে যাওয়া। --}}
                    {{ \App\Core\Support\Money::quantity(
                        \App\Core\Support\Money::sumOf($recipients, fn ($r) => $r->qty)) }}
                </div>
                <p class="mt-1 text-2xs text-(--color-ink-muted)">
                    {{ __('sales::message.trace_gone_note', ['count' => $recipients->count()]) }}
                </p>
            </div>
        </div>

        @if ($recipients->isEmpty())
            <p class="rounded-(--radius-card) border border-(--color-border)
                      bg-(--color-surface-card) p-6 text-center text-sm text-(--color-ink-muted)">
                {{ __('sales::message.trace_nobody') }}
            </p>
        @else
            <div class="table-responsive rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
        <x-ui.table :rows="$recipients"
                    :columns="$columns"
                    :empty="__('core.empty.no_results')" />
            </div>
        @endif
    @endif
</x-layouts.app>
