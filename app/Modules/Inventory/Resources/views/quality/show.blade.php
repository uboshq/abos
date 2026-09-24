{{--
    একটা পরিদর্শন — আর তার রায়ের ঘর।

    ⭐ পাতার আসল কাজ একটাই সিদ্ধান্ত, আর সেটা ফেরানো সহজ নয়: রায়ের পর
    মালটা আটকে যায়। ⚠️ তাই গৃহীত ও বাতিল পরিমাণ দুইটা আলাদা ঘরে, আর
    যোগফল না মিললে সেবা থামিয়ে দেয়।
--}}
@php
    $pending = $inspection->isPending();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $inspection->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$inspection->document_no"
                          :subtitle="$inspection->product?->name()" />
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
                    <dd>{{ \App\Core\Support\DateFormat::format($inspection->inspected_on) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.warehouse') }}</dt>
                    <dd>{{ $inspection->warehouse?->name() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.batch_no') }}</dt>
                    <dd>{{ $inspection->batch?->batch_no ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.state') }}</dt>
                    <dd>@include('inventory::quality.partials.status', ['inspection' => $inspection])</dd>
                </div>

                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.qc_inspected') }}</dt>
                    <dd class="num">{{ $inspection->inspected_qty }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.qc_accepted') }}</dt>
                    <dd class="num">{{ $inspection->accepted_qty }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.qc_rejected') }}</dt>
                    <dd class="num">{{ $inspection->rejected_qty }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('inventory::field.inspected_by') }}</dt>
                    <dd>{{ $inspection->inspector?->name ?? '—' }}</dd>
                </div>
            </dl>

            @if ($inspection->criteria)
                <p class="mt-3 text-sm">
                    <span class="text-xs text-(--color-ink-muted)">
                        {{ __('inventory::field.qc_criteria') }}:
                    </span>
                    {{ $inspection->criteria }}
                </p>
            @endif

            @if ($inspection->remarks)
                <p class="mt-1 text-sm text-(--color-ink-muted)">{{ $inspection->remarks }}</p>
            @endif
        </section>

        {{-- ── রায় ────────────────────────────────────────────────────
             ⛔ ঘরগুলো কেবল তখনই, যখন নীতি বলে এই মানুষটা পারেন **আর**
             রায় এখনো হয়নি ([[QualityInspectionPolicy::decide()]])। --}}
        @can('decide', $inspection)
            <form method="POST" action="{{ route('inventory.qc.decide', $inspection) }}"
                  data-boxed
                  class="rounded-(--radius-card) border border-(--color-border)
                         bg-(--color-surface-card) p-4">
                @csrf

                <h2 class="mb-3 text-sm font-semibold">{{ __('inventory::action.decide') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.select name="result" :label="__('inventory::field.qc_result')"
                                 :options="[
                                     \App\Modules\Inventory\Models\QualityInspection::APPROVED => __('inventory::status.qc_approved'),
                                     \App\Modules\Inventory\Models\QualityInspection::QUARANTINE => __('inventory::status.qc_quarantine'),
                                     \App\Modules\Inventory\Models\QualityInspection::REJECTED => __('inventory::status.qc_rejected'),
                                 ]"
                                 placeholder="-" required />

                    <x-ui.field name="accepted_qty" type="number" step="0.01"
                                :label="__('inventory::field.qc_accepted')"
                                :value="old('accepted_qty', $inspection->inspected_qty)" required />

                    <x-ui.field name="rejected_qty" type="number" step="0.01"
                                :label="__('inventory::field.qc_rejected')"
                                :value="old('rejected_qty', '0')" required />

                    <x-ui.field name="remarks" :label="__('inventory::field.narration')"
                                :value="old('remarks', $inspection->remarks)" />
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" tone="primary">
                        {{ __('inventory::action.decide') }}
                    </x-ui.button>

                    <p class="text-xs text-(--color-ink-muted)">
                        {{ __('inventory::message.qc_decide_note') }}
                    </p>
                </div>
            </form>
        @else
            @if ($pending)
                <p class="text-sm text-(--color-ink-muted)">
                    {{ __('inventory::message.qc_waiting_for_inspector') }}
                </p>
            @endif
        @endcan
    </div>
</x-layouts.app>
