{{-- ⭐ লোডিং খরচ — চালানের পরিবহন পাতায়, ভাড়ার নিচে (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে"; [[ChallanLoading]], [[ChallanLoadingController]])।
     ⓘ তিন অবস্থা: লেখা আছে (এক লাইনে, [[LabourCost::describe()]]) · পাকা চালানে এখনো লেখা হয়নি আর চাবি আছে (লেখার ফর্ম) ·
       বাকি সব ক্ষেত্রে "এখনো বাছা হয়নি"। ⛔ একবার লেখা হলে আর ফর্ম নয় — খরচ দুইবার বসত ([[ChallanLoading::recordOnConfirmed()]])। --}}
@php
    $loadingSet = filled($challan->loading_mode);
    $loadingCanRecord = ! $loadingSet
        && $challan->status === \App\Core\Support\DocumentStatus::CONFIRMED
        && (auth()->user()?->can('sales.challan.create') ?? false);
@endphp

<section class="mx-auto mt-4 max-w-xl rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
         data-challan-loading="{{ $loadingSet ? 'set' : ($loadingCanRecord ? 'record' : 'none') }}">
    @if ($loadingSet)
        <h2 class="mb-2 text-base font-semibold">{{ __('supplier::labour.loading') }}</h2>
        <p class="text-sm" data-loading-line>{{ app(\App\Modules\Supplier\Services\LabourCost::class)->describe($challan, 'loading_') }}</p>
    @elseif ($loadingCanRecord)
        <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('sales::loading_cost.record_hint') }}</p>
        <form method="POST" action="{{ route('sales.challan.loading.record', $challan) }}" class="grid gap-2" data-loading-record data-no-peek>
            @csrf
            @include('sales::challan.partials.loading-fields', ['modeRequired' => true])
            <x-ui.button type="submit" tone="primary">{{ __('sales::loading_cost.record') }}</x-ui.button>
        </form>
    @else
        <h2 class="mb-2 text-base font-semibold">{{ __('supplier::labour.loading') }}</h2>
        <p class="text-sm text-(--color-ink-muted)">{{ __('supplier::labour.not_chosen') }}</p>
    @endif

    {{-- ⓘ ফর্ম না থাকলেও দরজার বার্তা ("আগেই লেখা", "কেবল পাকা চালানে") যেন হারিয়ে না যায় --}}
    @unless ($loadingCanRecord)
        @foreach (\App\Modules\Supplier\Services\LabourCost::fields('loading_') as $field)
            @error($field)
                <p class="mt-1 text-sm text-(--color-danger)" data-loading-error>{{ $message }}</p>
            @enderror
        @endforeach
    @endunless
</section>
