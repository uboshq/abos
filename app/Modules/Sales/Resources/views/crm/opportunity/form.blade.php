{{--
    সুযোগ লেখা ও বদলানো।

    ── কেন পণ্যের সারি স্থির পাঁচটা ─────────────────────────────────────
    এই পর্দায় কোনো JS নেই (CSP-Alpine-এ সারি যোগ করার কোড আলাদা করে
    পরীক্ষা চাইত)। ⓘ ডিপোর একটা সুযোগে সাধারণত এক-দুইটা পণ্য; ফাঁকা
    সারি সার্ভার ফেলে দেয়।

    ⓘ গ্রাহক **অথবা** লিড — দুইটা ঘর, একটা ভরতে হয়। দুইটা ভরলে সার্ভিস
    থামায় ([[OpportunityService::party()]])।
--}}
@php
    $editing = $opportunity->exists;
    $title = $editing ? $opportunity->document_no : __('sales::crm.new_opportunity');
    $action = $editing ? route('sales.opportunity.update', $opportunity->id) : route('sales.opportunity.store');
    $cancel = $editing ? route('sales.opportunity.show', $opportunity->id) : route('sales.opportunity.index');
    $existingLines = $opportunity->relationLoaded('lines') ? $opportunity->lines->values() : collect();
    $leadSelected = $opportunity->customer_id ? null : $opportunity->lead_id;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$title" :subtitle="__('sales::crm.opportunities_note')" />
    </x-slot:header>

    <x-ui.errors />

    <form method="POST" action="{{ $action }}" class="space-y-4">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <section data-boxed class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4 sm:grid-cols-2 xl:grid-cols-3">
            <div class="sm:col-span-2 xl:col-span-3">
                <x-ui.field name="title" :label="__('sales::crm.title')" required
                            :value="old('title', $opportunity->title)" />
            </div>

            <x-ui.select name="customer_id" :label="__('sales::crm.customer')"
                         :options="$customers"
                         :placeholder="__('core.form.choose')"
                         :hint="__('sales::crm.party_hint')"
                         :selected="old('customer_id', $opportunity->customer_id)" />

            <x-ui.select name="lead_id" :label="__('sales::crm.lead')"
                         :options="$leads"
                         :placeholder="__('core.form.choose')"
                         :selected="old('lead_id', $leadSelected)" />

            @if ($salespeople->isNotEmpty())
                <x-ui.select name="salesperson_user_id" :label="__('sales::crm.salesperson')"
                             :options="$salespeople"
                             :placeholder="__('sales::crm.owner_me')"
                             :selected="old('salesperson_user_id', $opportunity->salesperson_user_id)" />
            @endif

            <x-ui.select name="stage_id" :label="__('sales::crm.stage')" required
                         :options="$stages"
                         :selected="old('stage_id', $opportunity->stage_id)" />

            <x-ui.field name="probability" type="number" :label="__('sales::crm.probability')"
                        :hint="__('sales::crm.probability_hint')"
                        min="0" max="100" step="1"
                        :value="old('probability', $opportunity->probability)" />

            <x-ui.field name="expected_close_date" type="date" :label="__('sales::crm.expected_close_date')"
                        :value="old('expected_close_date', $opportunity->expected_close_date?->toDateString())" />

            <x-ui.field name="competitor" :label="__('sales::crm.competitor')"
                        :value="old('competitor', $opportunity->competitor)" />

            <label class="block sm:col-span-2 xl:col-span-2">
                <span class="mb-1 block text-sm font-medium">{{ __('sales::crm.remarks') }}</span>
                <textarea name="remarks" rows="2" maxlength="5000"
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-card) px-2 py-1 text-sm">{{ old('remarks', $opportunity->remarks) }}</textarea>
            </label>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('sales::crm.products') }}</h2>
            <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('sales::crm.products_hint') }}</p>

            @error('lines')
                <p class="mb-2 text-2xs text-(--color-danger)">{{ $message }}</p>
            @enderror

            <div class="space-y-3">
                @for ($i = 0; $i < $lineRows; $i++)
                    @php $line = $existingLines->get($i); @endphp
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <x-ui.select :name="'lines[' . $i . '][product_id]'" :label="__('sales::crm.product')"
                                     :error-key="'lines.' . $i . '.product_id'"
                                     :options="$products"
                                     :placeholder="__('core.form.choose')"
                                     :selected="old('lines.' . $i . '.product_id', $line?->product_id)" />

                        <x-ui.field :name="'lines[' . $i . '][qty]'" type="number" step="any" :label="__('sales::crm.qty')"
                                    :error-key="'lines.' . $i . '.qty'" numeric
                                    :value="old('lines.' . $i . '.qty', $line ? rtrim(rtrim((string) $line->qty, '0'), '.') : null)" />

                        <x-ui.field :name="'lines[' . $i . '][value]'" type="number" step="0.01" :label="__('sales::crm.line_value')"
                                    :error-key="'lines.' . $i . '.value'" numeric
                                    :value="old('lines.' . $i . '.value', $line?->value)" />
                    </div>
                @endfor
            </div>
        </section>

        <div class="flex flex-wrap items-end gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="$cancel">{{ __('core.action.cancel') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
