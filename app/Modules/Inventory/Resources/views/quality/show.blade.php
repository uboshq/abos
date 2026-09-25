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
            {{-- ⛔ `enctype` ছাড়া ফাইলটা পাঠানোই যেত না, আর **কোনো ভুলও
                 দেখাত না** — ⓘ ব্রাউজার চুপচাপ কেবল নামটা পাঠাত, আর
                 পরিদর্শক ভাবতেন সনদটা উঠেছে। --}}
            <form method="POST" enctype="multipart/form-data"
                  action="{{ route('inventory.qc.decide', $inspection) }}"
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
                    {{-- ⭐ সনদ বা ছবি — রায়ের প্রমাণ, ২৫ সেপ্টেম্বর ২০২৬।

                         ⓘ রায়টা একটা দাবি; কাগজটা তার প্রমাণ। ⚠️ ছয় মাস
                         পরে সরবরাহকারী যখন বলেন *"মাল তো ঠিকই ছিল"*,
                         তখন মন্তব্যের ঘরে লেখা এক লাইন যথেষ্ট নয়।

                         ⓘ ঘরটা ঐচ্ছিক: বেশিরভাগ পরিদর্শনে ছবি লাগে না,
                         আর বাধ্যতামূলক করলে মানুষ যেকোনো একটা ছবি তুলে
                         দিতেন — তাতে প্রমাণের মান বাড়ত না, কেবল কাজ বাড়ত। --}}
                    <div class="mb-3">
                        <label for="paper" class="mb-1 block text-sm font-medium">
                            {{ __('inventory::field.qc_paper') }}
                        </label>

                        <input id="paper" type="file" name="paper"
                               class="w-full text-sm file:me-2 file:rounded-(--radius-field)
                                      file:border file:border-(--color-border)
                                      file:bg-(--color-surface-app) file:px-3 file:py-1.5 file:text-sm">

                        <span class="mt-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('inventory::field.qc_paper_hint') }}
                        </span>
                    </div>

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

        {{-- ⭐ বাতিল মাল বিনাশ — ২৫ সেপ্টেম্বর ২০২৬।

             ── ⛔ কেন এই বোতামটা লাগল ──────────────────────────────────
             রায়ে বাতিল হলে মালটা আটকে যেত, আর **চিরকাল আটকেই থাকত**:
             গুদামে জায়গা নিত, মজুদের মূল্যে গোনা হত, অথচ বিক্রি করা
             যেত না।

             ⚠️ আগে এটা করতে হত দুই ধাপে — আটকানো ছেড়ে, তারপর স্টক
             সমন্বয়ে বাদ দিয়ে। ⛔ আর ঐ দুই ধাপের **মাঝখানে মালটা
             বিক্রয়যোগ্য**, কারণ আটকানো ছাড়ার সাথে সাথেই সংখ্যাটা ফিরে
             আসে। ⓘ পরিদর্শনে বাতিল হওয়া ওষুধ ঐ কয়েক সেকেন্ডে কাউন্টার
             থেকে বেরিয়ে যেতে পারত, আর কোথাও কোনো ভুল দেখাত না।

             ⓘ পুনঃকাজের জন্য আলাদা বোতাম নেই, আর দরকারও নেই: ওটা
             আটকানো **ছেড়ে দেওয়া**, আর তার দরজা মজুদের পর্দায় আগে
             থেকেই আছে। --}}
        @can('decide', $inspection)
            @if (in_array($inspection->status, [
                \App\Modules\Inventory\Models\QualityInspection::REJECTED,
                \App\Modules\Inventory\Models\QualityInspection::QUARANTINE,
            ], true) && $writeOffReasons->isNotEmpty())
                <form method="POST" action="{{ route('inventory.qc.dispose', $inspection) }}"
                      data-boxed
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    <h2 class="mb-1 font-semibold">{{ __('inventory::action.dispose') }}</h2>

                    <p class="mb-3 text-xs text-(--color-ink-muted)">
                        {{ __('inventory::message.qc_dispose_note') }}
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <x-ui.field name="qty" type="number" step="0.01"
                                    :label="__('inventory::field.dispose_qty')" required />

                        {{-- ⛔ ক্ষতিটা কোন খাতে যাবে — নষ্ট, চুরি আর
                             মেয়াদোত্তীর্ণ এক খাতে যায় না। --}}
                        <x-ui.select name="reason_code_id" :label="__('inventory::field.reason')"
                                     :options="$writeOffReasons->mapWithKeys(fn ($r) => [$r->id => $r->name()])"
                                     placeholder="-" required />

                        <x-ui.field name="narration" :label="__('inventory::field.narration')" />
                    </div>

                    <div class="mt-3">
                        <x-ui.button type="submit" tone="danger">
                            {{ __('inventory::action.dispose') }}
                        </x-ui.button>
                    </div>
                </form>
            @endif
        @endcan

        {{-- ⓘ যে কাগজগুলো ইতিমধ্যে আছে। ⚠️ রায়ের ফর্মটা রায় হয়ে গেলে
             আর দেখা যায় না, কিন্তু কাগজগুলো **সবসময়** দেখা যেতে হবে —
             ⛔ নাহলে প্রমাণটা থাকত অথচ কেউ ওটা খুঁজে পেত না। --}}
        @if ($papers->isNotEmpty())
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-2 font-semibold">{{ __('inventory::field.qc_papers') }}</h2>

                <ul class="list-inside list-disc text-sm">
                    @foreach ($papers as $paper)
                        <li>{{ $paper->original_name ?: '—' }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.app>
