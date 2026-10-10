{{--
    একটা গোনা — কী পাওয়া গেল, খাতায় কত ছিল, আর পার্থক্যটা কত।

    ⭐ পাতার আসল কাজ একটাই সিদ্ধান্ত: **পার্থক্যটা মেনে নেব কি না**।
    ⚠️ তাই পার্থক্যের কলামটাই সবচেয়ে চওড়া চোখে পড়ে, আর মেনে নেওয়ার
    বোতামটা কারণ-কোডের ঠিক পাশে — ⛔ কারণ ছাড়া মেনে নেওয়ার কোনো পথ নেই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $count->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$count->document_no"
                          :subtitle="$count->warehouse?->name()" />
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
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.date') }}</dt>
                    <dd>{{ \App\Core\Support\DateFormat::format($count->count_date) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.counted_by') }}</dt>
                    <dd>{{ $count->counter?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.state') }}</dt>
                    <dd>@include('inventory::count.partials.status', ['count' => $count])</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.approved_by') }}</dt>
                    <dd>{{ $count->approver?->name ?? '—' }}</dd>
                </div>
            </dl>

            @if ($count->narration)
                <p class="mt-3 text-sm text-(--color-ink-muted)">{{ $count->narration }}</p>
            @endif

            {{-- ⭐ ফ্রি মালের সমন্বয় — খাতা আর দর ফ্রি ভাণ্ডারের, টাকা নেই (মজুদ ⚠️৬ক) --}}
            @if ($count->isFree())
                <p class="mt-3 text-sm font-medium">{{ __('inventory::field.free_count_paper') }}</p>
            @endif

            {{-- ⭐ মাল বের করার কাগজ — কারণটা কাগজেই, টাকা সেই খাতে (অডিট গ৫) --}}
            @if ($count->isIssue())
                <p class="mt-3 text-sm font-medium">
                    {{ __('inventory::field.issue_paper', ['reason' => $count->reason?->label() ?? '—']) }}
                </p>
            @endif

            {{-- ⓘ বাতিল খসড়া — কে আর কেন (অডিট গ৭) --}}
            @if ($count->cancel_reason)
                <p class="mt-3 text-sm text-(--color-badge-danger-ink)">
                    {{ __('inventory::field.count_cancelled_because', ['who' => $count->canceller?->name ?? '—', 'reason' => $count->cancel_reason]) }}
                </p>
            @endif
        </section>

        <section data-boxed
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="table-responsive">
                <table class="ui-lines table-cards w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('inventory::field.product') }}</th>
                            <th class="text-end">{{ __('inventory::field.book_qty') }}</th>
                            <th class="text-end">{{ __('inventory::field.counted_qty') }}</th>
                            <th class="text-end">{{ __('inventory::field.difference') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($count->lines as $line)
                            @php $off = bccomp((string) $line->difference, '0', 4) !== 0; @endphp

                            <tr class="border-b border-(--color-border)">
                                <td data-label="{{ __('inventory::field.product') }}">
                                    {{ $line->product?->name() ?? '—' }}

                                    @if ($line->batch)
                                        <span class="text-xs text-(--color-ink-muted)">
                                            · {{ $line->batch->batch_no }}
                                        </span>
                                    @endif
                                </td>

                                <td class="num text-end" data-label="{{ __('inventory::field.book_qty') }}">
                                    {{ \App\Core\Support\Money::quantity($line->book_qty) }}
                                </td>
                                <td class="num text-end" data-label="{{ __('inventory::field.counted_qty') }}">
                                    {{ \App\Core\Support\Money::quantity($line->counted_qty) }}
                                </td>

                                {{-- ⓘ মিলে গেলে রং নেই — ⚠️ প্রতিটা সারি রঙিন হলে
                                     যে কয়টা সত্যিই মেলেনি সেগুলোই আর চোখে পড়ত না। --}}
                                <td @class([
                                        'num text-end',
                                        'font-semibold text-(--color-badge-danger-ink)' => $off,
                                    ])
                                    data-label="{{ __('inventory::field.difference') }}">
                                    {{ \App\Core\Support\Money::quantity($line->difference) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ── মেনে নেওয়া ─────────────────────────────────────────────
             ⛔ বোতামটা কেবল তখনই, যখন নীতি বলে এই মানুষটা পারেন **আর**
             গোনাটা এখনো খসড়া ([[StockCountPolicy::approve()]])। --}}
        @can('approve', $count)
            <form method="POST" action="{{ route('inventory.count.approve', $count) }}"
                  data-boxed
                  class="flex flex-wrap items-end gap-3 rounded-(--radius-card) border
                         border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf

                <x-ui.select name="reason_code_id" :label="__('inventory::field.reason')"
                             :options="$reasons->mapWithKeys(fn ($r) => [$r->id => $r->label()])"
                             placeholder="-" required />

                <x-ui.button type="submit" tone="primary">
                    {{ __('inventory::action.settle_count') }}
                </x-ui.button>

                <p class="w-full text-xs text-(--color-ink-muted)">
                    {{ __('inventory::message.count_settle_note') }}
                </p>
            </form>
        @else
            @if ($count->status === \App\Core\Support\DocumentStatus::DRAFT)
                <p class="text-sm text-(--color-ink-muted)">
                    {{ $count->isIssue()
                        ? __('inventory::message.issue_waiting_for_signature', ['document' => $count->document_no])
                        : __('inventory::message.count_waiting_for_approver') }}
                </p>
            @endif
        @endcan

        {{-- ⭐ পড়ে থাকা খসড়া বাতিল — তারপর একই পণ্যের নতুন গণনা লেখা যায় (অডিট গ৭, [[StockCountPolicy::cancel()]]) --}}
        @can('cancel', $count)
            <form method="POST" action="{{ route('inventory.count.cancel', $count) }}"
                  data-boxed
                  class="flex flex-wrap items-end gap-3 rounded-(--radius-card) border
                         border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf

                <label class="flex min-w-64 flex-1 flex-col gap-1 text-sm">
                    <span class="text-xs text-(--color-ink-muted)">{{ __('inventory::message.cancel_reason') }}</span>
                    <input type="text" name="cancel_reason" required minlength="3" maxlength="500"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-3">
                </label>

                <x-ui.button type="submit" tone="danger">
                    {{ __('inventory::action.cancel_count') }}
                </x-ui.button>
            </form>
        @endcan
    </div>
</x-layouts.app>
