{{--
    বীমা পলিসি বসানো ও বদলানো — একটাই ফর্ম, দুই কাজে; আলাদা পাতায়।

    ⓘ বীমা কোম্পানি প্রতিষ্ঠানের তালিকা থেকে (ধরন: বীমা); তালিকায় না
    থাকলে এখানেই "+" — এক জমায় ([[institution-picker]])।
    ⚠️ প্রিমিয়ামের টাকা এখানে দেওয়া হয় না: জমা দিলে একটা "দেওয়া বাকি"
    সারি বসে, আর পলিসির পাতায় "প্রিমিয়াম দিন" পরিশোধ ভাউচার খোলে।
--}}
@php
    use App\Modules\Finance\Models\InsurancePolicy;

    $isNew = ! $policy->exists;

    $covers = collect(InsurancePolicy::COVERS)
        ->mapWithKeys(fn ($c) => [$c => __('finance::insurance.covers_'.$c)])
        ->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('finance::insurance.new') : __('finance::insurance.edit') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isNew ? __('finance::insurance.new') : __('finance::insurance.edit')"
                          :subtitle="__('finance::insurance.subtitle')" />
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
              action="{{ $isNew ? route('finance.insurance.store') : route('finance.insurance.update', $policy) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <div class="sm:col-span-2">
                @include('finance::components.institution-picker', [
                    'institutions' => $insurers,
                    'selected' => old('institution_id', $policy->institution_id),
                    'label' => __('finance::insurance.insurer'),
                ])
            </div>

            <x-ui.field name="policy_no" :label="__('finance::insurance.policy_no')" required
                        :value="old('policy_no', $policy->policy_no)" />

            <x-ui.select name="covers" :label="__('finance::insurance.covers')" required
                         :options="$covers" :selected="old('covers', $policy->covers)" />

            <div class="sm:col-span-2">
                <x-ui.field name="subject" :label="__('finance::insurance.subject')" required
                            :hint="__('finance::insurance.subject_hint')"
                            :value="old('subject', $policy->subject)" />
            </div>

            <x-ui.field name="sum_insured" type="number" step="0.01" numeric
                        :label="__('finance::insurance.sum_insured')"
                        :value="old('sum_insured', $policy->sum_insured)" />

            <x-ui.field name="premium" type="number" step="0.01" numeric required
                        :label="__('finance::insurance.premium')"
                        :value="old('premium', $policy->premium)" />

            <x-ui.field name="starts_on" type="date" required :label="__('finance::insurance.starts_on')"
                        :value="old('starts_on', $policy->starts_on?->toDateString())" />

            <x-ui.field name="ends_on" type="date" required :label="__('finance::insurance.ends_on')"
                        :value="old('ends_on', $policy->ends_on?->toDateString())" />

            <div class="sm:col-span-2 xl:col-span-3">
                <x-ui.field name="notes" :label="__('finance::insurance.notes')"
                            :value="old('notes', $policy->notes)" />
            </div>

            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary"
                             :href="$isNew ? route('finance.insurance.index') : route('finance.insurance.show', $policy)">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
