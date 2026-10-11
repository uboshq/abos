{{--
    ছাঁচ ভরে কাগজ (§২; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬) — প্রতিটা ঘরের মান, তারপর ABOS-এর ছাপার যন্ত্রে PDF আর নতুন কাগজ।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $template->title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$template->title" :subtitle="__('documents::message.fill_subtitle')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('documents.templates.generate', $template) }}"
          class="grid gap-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 lg:grid-cols-3" data-boxed>
        @csrf
        <x-ui.field name="name" :label="__('documents::field.name')" :value="old('name', $template->title)" maxlength="191" />
        <x-ui.select name="branch_id" :label="__('documents::field.branch')" :options="$branches"
                     :selected="old('branch_id', $defaultBranch)" :required="! $companyWide"
                     :placeholder="$companyWide ? __('documents::message.company_wide') : '—'" />
        <x-ui.select name="confidentiality" :label="__('documents::field.confidentiality')" :options="$levels"
                     :selected="old('confidentiality', $internal)" required />

        @foreach ($variables as $variable)
            <x-ui.field :name="'values['.$variable.']'" :label="$variable" :value="old('values.'.$variable)" maxlength="2000" />
        @endforeach

        {{-- ⭐ বাতিল · তৈরি নিচের স্থির পট্টিতে (documents রিভিউ) --}}
        <x-ui.form-actions class="lg:col-span-3" :cancel="route('documents.templates')">
            <x-slot:submit>
                <x-ui.button type="submit" tone="primary" icon="attachment">{{ __('documents::action.make_document') }}</x-ui.button>
            </x-slot:submit>
        </x-ui.form-actions>
    </form>
</x-layouts.app>
