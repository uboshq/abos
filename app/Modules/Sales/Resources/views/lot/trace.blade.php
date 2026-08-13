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
                    {{ rtrim(rtrim((string) $recipients->sum(fn ($r) => (float) $r->qty), '0'), '.') ?: '0' }}
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
                <table class="table-cards w-full text-sm">
                    <thead class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                        <tr>
                            <th class="p-2 text-start font-medium">{{ __('sales::field.date') }}</th>
                            <th class="p-2 text-start font-medium">{{ __('sales::doc.challan') }}</th>
                            <th class="p-2 text-start font-medium">{{ __('sales::field.customer') }}</th>
                            <th class="p-2 text-start font-medium">{{ __('inventory::field.phone') }}</th>
                            <th class="p-2 text-end font-medium">{{ __('sales::field.quantity') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($recipients as $row)
                            <tr class="border-b border-(--color-border)">
                                <td class="p-2" data-label="{{ __('sales::field.date') }}">
                                    {{ \App\Core\Support\DateFormat::format($row->trx_date) }}
                                </td>

                                {{-- চালানের নম্বরটা ক্লিকযোগ্য — নিয়ম ১:
                                     প্রতিটা সংখ্যা তার উৎসে নিয়ে যায় --}}
                                <td class="p-2" data-label="{{ __('sales::doc.challan') }}">
                                    <a href="{{ route('sales.challan.show', ['challan' => $row->challan_id]) }}"
                                       class="text-(--color-brand-700) hover:underline">
                                        {{ $row->document_no }}
                                    </a>
                                </td>

                                <td class="p-2" data-label="{{ __('sales::field.customer') }}">
                                    {{ app()->getLocale() === 'bn' && $row->customer_bn ? $row->customer_bn : $row->customer_en }}
                                </td>

                                <td class="p-2" data-label="{{ __('inventory::field.phone') }}">
                                    @if ($row->customer_phone)
                                        <a href="tel:{{ $row->customer_phone }}" class="num hover:underline">
                                            {{ $row->customer_phone }}
                                        </a>
                                    @endif
                                </td>

                                <td class="num p-2 text-end" data-label="{{ __('sales::field.quantity') }}">
                                    {{ rtrim(rtrim((string) $row->qty, '0'), '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-layouts.app>
