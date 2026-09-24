{{--
    একটা চাহিদা — আর তার দুইটা সিদ্ধান্ত।

    ⭐ পাতার কাজ দুইটা, আর দুইটাই দুই জনের: **মঞ্জুর করা** (এখানে সই, আর
    এখানেই থামা যায়) আর **আদেশে রূপান্তর** (ক্রয় বিভাগের কাজ, আর
    সরবরাহকারী বাছা হয় এখানেই — চাহিদায় নয়)।
--}}
@php
    $estimated = $requisition->estimatedTotal();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $requisition->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$requisition->document_no"
                          :subtitle="$requisition->purpose" />
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

    <div class="space-y-4">
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.date') }}</dt>
                    <dd>{{ \App\Core\Support\DateFormat::format($requisition->trx_date) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.needed_by') }}</dt>
                    <dd>{{ $requisition->needed_by
                        ? \App\Core\Support\DateFormat::format($requisition->needed_by)
                        : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.requested_by') }}</dt>
                    <dd>{{ $requisition->requester?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd>@include('purchase::requisition.partials.status', ['requisition' => $requisition])</dd>
                </div>
            </dl>

            {{-- ⓘ আদেশ হয়ে গেলে কাগজটার পরের অধ্যায়ে যাওয়ার পথ। ⚠️ লিংক
                 না থাকলে মানুষ নম্বরটা কপি করে আদেশের তালিকায় খুঁজতেন। --}}
            @if ($requisition->order)
                <p class="mt-3 text-sm">
                    <span class="text-xs text-(--color-ink-muted)">
                        {{ __('purchase::field.became_order') }}:
                    </span>
                    <a href="{{ route('purchase.order.show', $requisition->order) }}"
                       class="text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ $requisition->order->document_no }}
                    </a>
                </p>
            @endif

            @if ($requisition->narration)
                <p class="mt-1 text-sm text-(--color-ink-muted)">{{ $requisition->narration }}</p>
            @endif
        </section>

        <section data-boxed
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="table-responsive">
                <table class="ui-lines table-cards w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('purchase::field.product') }}</th>
                            <th class="text-end">{{ __('purchase::field.quantity') }}</th>
                            <th class="text-end">{{ __('purchase::field.estimated_rate') }}</th>
                            <th class="text-end">{{ __('purchase::field.estimated_amount') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($requisition->lines as $line)
                            <tr class="border-b border-(--color-border)">
                                <td data-label="{{ __('purchase::field.product') }}">
                                    {{ $line->product?->name() ?? '—' }}

                                    @if ($line->narration)
                                        <span class="block text-2xs text-(--color-ink-muted)">
                                            {{ $line->narration }}
                                        </span>
                                    @endif
                                </td>
                                <td class="num text-end" data-label="{{ __('purchase::field.quantity') }}">
                                    {{ $line->qty }}
                                </td>

                                {{-- ⓘ দর না বসানো থাকলে একটা ড্যাশ, শূন্য নয় —
                                     ⚠️ শূন্য লিখলে মনে হত জিনিসটা বিনামূল্যে। --}}
                                <td class="num text-end" data-label="{{ __('purchase::field.estimated_rate') }}">
                                    {{ $line->estimated_rate !== null
                                        ? \App\Core\Support\Money::format((string) $line->estimated_rate)
                                        : '—' }}
                                </td>
                                <td class="num text-end" data-label="{{ __('purchase::field.estimated_amount') }}">
                                    {{ \App\Core\Support\Money::format($line->estimatedAmount()) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end font-medium">
                                {{ __('purchase::field.estimated_total') }}
                            </td>
                            <td class="num text-end font-semibold">
                                {{ \App\Core\Support\Money::format($estimated) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        {{-- ── মঞ্জুরি ──────────────────────────────────────────────── --}}
        @can('approve', $requisition)
            <form method="POST" action="{{ route('purchase.requisition.approve', $requisition) }}"
                  data-boxed
                  class="flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                         border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf

                <x-ui.button type="submit" tone="primary">
                    {{ __('purchase::action.approve_requisition') }}
                </x-ui.button>

                <p class="text-xs text-(--color-ink-muted)">
                    {{ __('purchase::message.requisition_approve_note') }}
                </p>
            </form>
        @endcan

        {{-- ── আদেশে রূপান্তর ────────────────────────────────────────
             ⓘ সরবরাহকারীটা **এখানে** বাছা হয়, চাহিদায় নয়: যিনি চান
             তিনি জানেন কী লাগবে, আর কার কাছ থেকে সেটা ক্রয় বিভাগের
             সিদ্ধান্ত। --}}
        @if ($requisition->canBecomeAnOrder() && $suppliers->isNotEmpty())
            <form method="POST" action="{{ route('purchase.requisition.order', $requisition) }}"
                  data-boxed
                  class="rounded-(--radius-card) border border-(--color-border)
                         bg-(--color-surface-card) p-4">
                @csrf

                <h2 class="mb-3 text-sm font-semibold">
                    {{ __('purchase::action.make_order') }}
                </h2>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.select name="supplier_id" :label="__('purchase::field.supplier')"
                                 :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name()])"
                                 placeholder="-" required />

                    <x-ui.select name="warehouse_id" :label="__('purchase::field.warehouse')"
                                 :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                                 placeholder="-" required />

                    <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                                :value="old('trx_date', now()->toDateString())" required />

                    <x-ui.field name="expected_on" type="date" :label="__('purchase::field.expected_on')"
                                :value="old('expected_on', $requisition->needed_by?->toDateString())" />
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" tone="primary">
                        {{ __('purchase::action.make_order') }}
                    </x-ui.button>

                    <p class="text-xs text-(--color-ink-muted)">
                        {{ __('purchase::message.requisition_order_note') }}
                    </p>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
