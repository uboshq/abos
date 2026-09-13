{{--
    মূলধন লেখা ও শোধরানো — একটাই ফর্ম, দুই কাজে (One Form Standard, ১৫.২৪)।

    ── ⛔ কেন ফর্মটা এখানে, তালিকার মাঝখানে নয় ──────────────────────────
    আগে ঘরগুলো তালিকার পাতার মাঝখানে গোঁজা ছিল, আর উপরে কোনো বোতাম ছিল
    না। মালিক ১৩ সেপ্টেম্বর ২০২৬-এ ধরিয়ে দিলেন যে বাকি পর্দায় — বিক্রয়
    বিল, ভাউচার, চেক — উপরে বাঁ কোণে "+ নতুন" থাকে।

    ⓘ এক রকম না হলে দাম দিতে হয় প্রতিদিন: মানুষ প্রতিটা পর্দায় নতুন করে
    খোঁজেন কোথায় কী, আর একটা পর্দা শিখে অন্যটায় কাজে লাগে না।

    ── সম্পাদনা কেবল খসড়ায় ────────────────────────────────────────────
    ⚠️ পোস্ট হওয়া সারি এখানে আসেই না ([[CapitalController::edit()]] আটকে
    দেয়)। পোস্ট মানে একটা ভাউচার আর দুইটা দাখিলা খাতায় বসে গেছে; সারিটা
    পরে বদলালে **খাতা আর তালিকা দুই কথা বলত**।
--}}
@php
    $isNew = ! ($entry ?? null);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>
        {{ $isNew ? __('finance::action.new_contribution') : __('finance::action.edit_contribution') }}
    </x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('finance::action.new_contribution') : __('finance::action.edit_contribution')"
            :subtitle="$isNew ? __('finance::message.recorded_then_posted') : $entry->document_no" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 max-w-4xl rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section data-boxed
             class="max-w-4xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST"
              action="{{ $isNew ? route('finance.capital.store') : route('finance.capital.update', $entry) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            {{-- ⓘ ঘরটা দুই কলাম নেয়, কারণ ভেতরে বাছাই আর নিচে নতুন নাম
                 যোগ করার পথ — দুইটা একসাথে। --}}
            <div class="sm:col-span-2">
                @include('finance::components.person-picker', [
                    'people' => $people,
                    'label' => __('finance::field.who'),
                    'required' => true,
                    'selected' => old('person_id', $entry->person_id ?? null),
                ])
            </div>

            <x-ui.select name="contributor_type" :label="__('finance::field.as')" required
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::WHO)
                             ->mapWithKeys(fn ($w) => [$w => __('finance::who.'.$w)])"
                         :selected="old('contributor_type', $entry->contributor_type ?? 'owner')" />

            <x-ui.select name="entry_type" :label="__('finance::field.kind')" required
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::KINDS)
                             ->mapWithKeys(fn ($k) => [$k => __('finance::kind.'.$k)])"
                         :selected="old('entry_type', $entry->entry_type ?? 'contribution')" />

            <x-ui.field name="trx_date" type="date" :label="__('finance::field.date')" required
                        :value="old('trx_date', $entry?->trx_date?->toDateString() ?? now()->toDateString())" />

            <x-ui.field name="amount" type="number" step="0.01" numeric required
                        :label="__('finance::field.amount')"
                        :value="old('amount', $entry->amount ?? null)" />

            <x-ui.field name="share_percent" type="number" step="0.01" numeric
                        :label="__('finance::field.share')"
                        :value="old('share_percent', $entry->share_percent ?? null)" />

            <div class="sm:col-span-2 xl:col-span-3">
                <x-ui.field name="narration" :label="__('finance::field.what_for')"
                            :value="old('narration', $entry->narration ?? null)" />
            </div>

            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.capital.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
