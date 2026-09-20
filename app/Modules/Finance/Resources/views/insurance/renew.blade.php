{{--
    নবায়ন — নতুন মেয়াদ আর প্রিমিয়াম। আগের মেয়াদ প্রিমিয়ামের সারিতে থেকে যায়।
    ⓘ নতুন মেয়াদ আগেরটার পরদিন থেকে এক বছর — সবচেয়ে সাধারণ ঘটনা, আগে থেকে বসানো।
--}}
@php
    $from = $policy->ends_on->copy()->addDay();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::insurance.renew_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::insurance.renew_title').' — '.$policy->policy_no"
                          :subtitle="$policy->subject.' · '.($policy->institution?->label() ?? '')" />
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
        <form method="POST" action="{{ route('finance.insurance.renew', $policy) }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf

            <x-ui.field name="starts_on" type="date" required :label="__('finance::insurance.starts_on')"
                        :value="old('starts_on', $from->toDateString())" />

            <x-ui.field name="ends_on" type="date" required :label="__('finance::insurance.ends_on')"
                        :value="old('ends_on', $from->copy()->addYear()->subDay()->toDateString())" />

            <x-ui.field name="premium" type="number" step="0.01" numeric required
                        :label="__('finance::insurance.premium')"
                        :value="old('premium', $policy->premium)" />

            <x-ui.field name="sum_insured" type="number" step="0.01" numeric
                        :label="__('finance::insurance.sum_insured')"
                        :value="old('sum_insured', $policy->sum_insured)" />

            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-4">
                <x-ui.button type="submit" tone="primary">{{ __('finance::insurance.renew') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.insurance.show', $policy)">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
