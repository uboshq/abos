{{-- একটা খসড়ার একটাই কাজ — দেখা, পপ-আপে ([[direct/drafts]])।

     ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"খসড়া তালিকায় খসড়া khulbe na … vew hobe
     popup e, print hobe na, khulbe sudu direct challan e pending box theke"*। ⓘ তাই
     এখানে খোলা বা বাতিলের বোতাম নেই; পপ-আপে কেবল কী আছে আর কেন আটকে। --}}
<div x-data="{ open: false }" class="flex flex-wrap items-center justify-end gap-2">
    {{-- ⭐ নিষ্ক্রিয় / সক্রিয় / মুছুন — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬। ⛔ সইয়ের অপেক্ষায়
         থাকলে নয় — ওটা অনুমোদনের পাতায় শেষ হয়। --}}
    @unless ($held)
        <form method="POST" action="{{ route($draft->draft_paused_at === null ? 'sales.direct.draft_pause' : 'sales.direct.draft_resume', $draft->id) }}">
            @csrf
            <button type="submit"
                    class="rounded-(--radius-field) border border-(--color-border) px-3 py-1 text-xs font-semibold
                           text-(--color-ink) hover:bg-(--color-surface-hover)">
                {{ $draft->draft_paused_at === null ? __('sales::action.pause_draft') : __('sales::action.resume_draft') }}
            </button>
        </form>

        <form method="POST" action="{{ route('sales.direct.discard', $draft->id) }}" class="flex items-center gap-1">
            @csrf
            <input type="hidden" name="back" value="drafts">
            <input type="text" name="reason" required maxlength="500"
                   aria-label="{{ __('sales::message.cancel_reason') }}"
                   placeholder="{{ __('sales::message.cancel_reason') }}"
                   class="h-(--spacing-field-dense) w-32 rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-xs">
            <button type="submit"
                    class="rounded-(--radius-field) bg-(--color-danger) px-3 py-1 text-xs font-semibold text-white
                           hover:bg-(--color-danger-hover)">
                {{ __('sales::action.delete_draft') }}
            </button>
        </form>
    @endunless

    <button type="button" x-on:click="open = true"
            class="rounded-(--radius-field) bg-(--color-brand-600) px-3 py-1 text-xs font-semibold text-white
                   hover:bg-(--color-brand-700)">
        {{ __('sales::action.view_draft') }}
    </button>

    <div x-show="open" x-cloak role="dialog" aria-modal="true"
         x-on:keydown.escape.window="open = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 text-start">
        <div x-on:click.outside="open = false"
             class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-5 shadow-lg">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-lg font-bold text-(--color-ink)">
                        {{ $draft->document_no }}
                        @if ($challanNo = $draft->lines->first()?->challanLine?->challan?->document_no)
                            <span class="text-sm font-normal text-(--color-ink-muted)">· {{ $challanNo }}</span>
                        @endif
                    </p>
                    <p class="text-sm text-(--color-ink-muted)">
                        {{ \App\Core\Support\DateFormat::format($draft->trx_date) }}
                        · {{ $draft->customer?->name() }}
                        @if ($draft->customer?->location)
                            · {{ $draft->customer->location->name() }}
                        @endif
                        @if ($draft->customer?->phone)
                            · {{ $draft->customer->phone }}
                        @endif
                    </p>
                </div>

                <span class="shrink-0 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-2 py-1 text-xs
                             font-semibold text-(--color-badge-pending-ink)">
                    {{ $why }}
                </span>
            </div>

            <table class="mt-4 w-full text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) text-2xs text-(--color-ink-muted)">
                        <th class="py-1 text-start">{{ __('sales::field.product') }}</th>
                        <th class="py-1 text-end">{{ __('sales::field.qty') }}</th>
                        <th class="py-1 text-end">{{ __('sales::field.rate') }}</th>
                        <th class="py-1 text-end">{{ __('sales::field.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($draft->lines as $line)
                        <tr class="border-b border-(--color-border)">
                            <td class="py-1">{{ $line->product?->name() }}</td>
                            <td class="num py-1 text-end">{{ \App\Core\Support\Money::format($line->qty) }}</td>
                            <td class="num py-1 text-end">{{ \App\Core\Support\Money::format($line->rate) }}</td>
                            <td class="num py-1 text-end">{{ \App\Core\Support\Money::format($line->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td class="py-2" colspan="3">{{ __('sales::field.total') }}</td>
                        <td class="num py-2 text-end">{{ \App\Core\Support\Money::format($draft->total) }}</td>
                    </tr>
                </tfoot>
            </table>

            <p class="mt-3 text-xs text-(--color-ink-muted)">{{ __('sales::message.draft_opens_at_counter') }}</p>

            <div class="mt-4 flex justify-end">
                <button type="button" x-on:click="open = false"
                        class="rounded-(--radius-field) border border-(--color-border) px-4 py-2 text-sm font-semibold
                               text-(--color-ink) hover:bg-(--color-surface-hover)">
                    {{ __('sales::message.credit_wall_ok') }}
                </button>
            </div>
        </div>
    </div>
</div>
