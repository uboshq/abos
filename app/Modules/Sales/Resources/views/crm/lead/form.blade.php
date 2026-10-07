{{--
    লিড লেখা ও বদলানো — একই ফর্ম।

    মালিকের ঘরটা কেবল সবার-দেখার চাবিধারী দেখেন; বাকিদের লিড নিজের নামেই
    বসে ([[LeadService::ownerFor()]]) — ঘর লুকানোটা কেবল চেহারা, দেয়াল
    সার্ভিসে।
--}}
@php
    $editing = $lead->exists;
    $title = $editing ? $lead->document_no : __('sales::crm.new_lead');
    $sources = collect(\App\Modules\Sales\Models\Lead::SOURCES)
        ->mapWithKeys(fn ($s) => [$s => __('sales::crm.source.' . $s)]);
    $statuses = collect(\App\Modules\Sales\Models\Lead::SETTABLE)
        ->mapWithKeys(fn ($s) => [$s => __('sales::crm.lead_status.' . $s)]);
    $action = $editing ? route('sales.lead.update', $lead->id) : route('sales.lead.store');
    $cancel = $editing ? route('sales.lead.show', $lead->id) : route('sales.lead.index');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$title" :subtitle="__('sales::crm.leads_note')" />
    </x-slot:header>

    <x-ui.errors />

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST" action="{{ $action }}" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <x-ui.field name="name" :label="__('sales::crm.lead_name')" required
                        :value="old('name', $lead->name)" />

            <x-ui.field name="contact_person" :label="__('sales::crm.contact_person')"
                        :value="old('contact_person', $lead->contact_person)" />

            <x-ui.field name="phone" :label="__('sales::crm.phone')"
                        :value="old('phone', $lead->phone)" />

            <x-ui.select name="location_id" :label="__('sales::crm.location')"
                         :options="$locations"
                         :placeholder="__('core.form.choose')"
                         :selected="old('location_id', $lead->location_id)" />

            <x-ui.select name="source" :label="__('sales::crm.source_label')" required
                         :options="$sources"
                         :selected="old('source', $lead->source)" />

            <x-ui.select name="status" :label="__('sales::crm.status')" required
                         :options="$statuses"
                         :selected="old('status', $lead->status)" />

            @if ($owners->isNotEmpty())
                <x-ui.select name="owner_user_id" :label="__('sales::crm.owner')"
                             :options="$owners"
                             :placeholder="__('sales::crm.owner_me')"
                             :selected="old('owner_user_id', $lead->owner_user_id)" />
            @endif

            <div class="sm:col-span-2 xl:col-span-2">
                <x-ui.field name="address" :label="__('sales::crm.address')"
                            :value="old('address', $lead->address)" />
            </div>

            <div class="sm:col-span-2 xl:col-span-3">
                <x-ui.field name="lost_reason" :label="__('sales::crm.lost_reason')"
                            :hint="__('sales::crm.lost_reason_hint')"
                            :value="old('lost_reason', $lead->lost_reason)" />
            </div>

            <label class="block sm:col-span-2 xl:col-span-3">
                <span class="mb-1 block text-sm font-medium">{{ __('sales::crm.notes') }}</span>
                <textarea name="notes" rows="3" maxlength="5000"
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-card) px-2 py-1 text-sm">{{ old('notes', $lead->notes) }}</textarea>
            </label>

            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="$cancel">{{ __('core.action.cancel') }}</x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
