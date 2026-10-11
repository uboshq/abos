{{--
    গ্রাহকের নিজের পাতা।

    সবার উপরে বকেয়া, বড় করে — কারণ গ্রাহক এই পাতায় আসেন ওই একটা
    সংখ্যা দেখতে। বাকি সব তার ব্যাখ্যা।
--}}
<x-sales::portal.layout :customer="$customer">
    {{-- ⭐ জের গ্রাহকের পক্ষে হলে "আপনার জমা" — খাতারই জের, আলাদা "অগ্রিম হিসাব" নয় (মালিকের নিয়ম; গ্রাহক পোর্টাল, ধাপ ১) --}}
    @php($inCredit = bccomp((string) $due, '0', 4) < 0)
    <div data-boxed class="mb-5 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) px-4 py-4" @if ($inCredit) data-portal-your-credit @endif>
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
            {{ $inCredit ? __('sales::portal_statement.your_credit') : __('sales::portal.due') }}
        </p>
        <p class="num text-3xl font-semibold">
            {{ $inCredit ? '৳'.\App\Core\Support\Money::format(bcmul((string) $due, '-1', 4)) : \App\Core\Support\Money::format($due) }}
        </p>
        <a href="{{ route('sales.portal.ledger') }}" class="mt-1 inline-block text-sm text-(--color-brand-500) hover:underline">
            {{ __('sales::portal_statement.title') }}
        </a>
    </div>

    <a href="{{ route('sales.portal.claim.create') }}"
       class="mb-6 block rounded-(--radius-field) bg-(--color-brand-500) px-4 py-3 text-center
              font-medium text-white">
        {{ __('sales::portal.claim_title') }}
    </a>

    {{-- ⭐ নিজের বিক্রয় আদেশ — কোম্পানি DO ছেড়ে আদেশে চলে গেলে (DO+SO মেশানো, ধাপ ৯, ৫ অক্টোবর ২০২৬); খোলা DO নিচের ছোট লিংকে শেষ হয় --}}
    @if ($ordersOn ?? false)
        <a href="{{ route('sales.portal.order.index') }}" data-portal-orders
           class="mb-2 block rounded-(--radius-field) bg-(--color-brand-500) px-4 py-3 text-center font-medium text-white">
            {{ __('sales::portal_order.title') }}
        </a>
        <a href="{{ route('sales.portal.do.index') }}" data-portal-do-old
           class="mb-3 block text-center text-sm text-(--color-brand-500) hover:underline">
            {{ __('sales::portal_order.old_do') }}
        </a>
    @else
        {{-- ⭐ নিজের DO — লেখা আর জমা (মালিকের বিক্রয়-ধারা, ২ অক্টোবর ২০২৬) --}}
        <a href="{{ route('sales.portal.do.index') }}" data-portal-do
           class="mb-3 block rounded-(--radius-field) bg-(--color-brand-500) px-4 py-3 text-center font-medium text-white">
            {{ __('sales::delivery_order.title') }}
        </a>
    @endif

    {{-- ⭐ বিলের ঘর — নিজের পাকা বিল, বাকি আর শোধ, PDF (গ্রাহক পোর্টাল, ধাপ ১) --}}
    <a href="{{ route('sales.portal.bill.index') }}" data-portal-bills
       class="mb-3 block rounded-(--radius-field) border border-(--color-brand-500) px-4 py-3 text-center
              font-medium text-(--color-brand-500)">
        {{ __('sales::portal_bill.title') }}
    </a>

    {{-- ⭐ নিজের অর্ডার কোথায় — ডেলিভারি ট্র্যাকিং (মালিক, ২ অক্টোবর ২০২৬) --}}
    <a href="{{ route('sales.portal.tracking') }}" data-portal-tracking
       class="mb-6 block rounded-(--radius-field) border border-(--color-brand-500) px-4 py-3 text-center
              font-medium text-(--color-brand-500)">
        {{ __('sales::tracking.title') }}
    </a>

    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::portal.my_claims') }}</h2>

    @if ($claims->isEmpty())
        <p class="mb-6 text-sm text-(--color-ink-muted)">{{ __('sales::portal.no_claims') }}</p>
    @else
        <ul class="mb-6 divide-y divide-(--color-border) rounded-(--radius-card) border
                   border-(--color-border) bg-(--color-surface-card)">
            @foreach ($claims as $claim)
                <li>
                    <a href="{{ route('sales.portal.claim.show', $claim) }}"
                       class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-(--color-surface-hover)">
                        <span class="min-w-0">
                            <span class="block num font-medium">
                                {{ \App\Core\Support\Money::format($claim->amount) }}
                            </span>
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ $claim->claimed_on?->format('d M Y') }}
                                @if ($claim->reference) · {{ $claim->reference }} @endif
                            </span>
                        </span>

                        <x-ui.badge :tone="match ($claim->status) {
                            'accepted' => 'success',
                            'rejected' => 'danger',
                            'verifying' => 'info',
                            default => 'pending',
                        }">
                            {{ __('sales::portal.'.$claim->status) }}
                        </x-ui.badge>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::portal.recent_bills') }}</h2>

    @if ($invoices->isEmpty())
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::portal.no_bills') }}</p>
    @else
        <ul class="divide-y divide-(--color-border) rounded-(--radius-card) border
                   border-(--color-border) bg-(--color-surface-card)">
            @foreach ($invoices as $invoice)
                <li>
                    {{-- ⭐ বিলটা এখন খোলে — পাকা হলে বিলের ঘরে (গ্রাহক পোর্টাল, ধাপ ১); খসড়ার নিজের পাতা নেই --}}
                    @if (in_array($invoice->status, \App\Core\Support\DocumentStatus::POSTED, true))
                        <a href="{{ route('sales.portal.bill.show', $invoice->public_id) }}"
                           class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-(--color-surface-hover)">
                    @else
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                    @endif
                        <span class="min-w-0">
                            <span class="block font-medium">{{ $invoice->document_no }}</span>
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ \App\Core\Support\DateFormat::format($invoice->trx_date) }}
                            </span>
                        </span>
                        <span class="num font-medium">
                            {{ \App\Core\Support\Money::format($invoice->total) }}
                        </span>
                    @if (in_array($invoice->status, \App\Core\Support\DocumentStatus::POSTED, true))
                        </a>
                    @else
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-sales::portal.layout>
