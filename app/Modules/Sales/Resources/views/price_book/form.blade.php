{{--
    দর তালিকা — নতুন বা এডিট। ⛔ তালিকা ঠিক একজনের জন্য: স্তরটা ঠিকানা থেকে (`?target=`), আর সেই স্তরের একটা বাছাই।
    ⓘ JavaScript লাগে না — স্তর বদলাতে হলে তালিকার পাতার ট্যাব থেকে আবার "নতুন তালিকা"।
--}}
@php
    $isNew = ! $list->exists;
    $aimOptions = match ($target) {
        'customer' => $customers->mapWithKeys(fn ($c) => [$c->id => $c->code.' — '.$c->name()]),
        'tier' => $tiers->mapWithKeys(fn ($t) => [$t->id => $t->name()]),
        'territory' => $places->mapWithKeys(fn ($p) => [$p->id => $p->path()]),
        default => collect(),
    };
    $aimSelected = match ($target) {
        'customer' => $list->customer_id,
        'tier' => $list->party_type_id,
        'territory' => $list->location_id,
        default => null,
    };
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::price_book.new') : $list->code }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isNew ? __('sales::price_book.new') : $list->name()" :subtitle="__('sales::price_book.title_'.$target)" />
    </x-slot:header>

    <x-ui.errors />

    <form method="POST" action="{{ $isNew ? route('sales.price_book.store') : route('sales.price_book.update', $list) }}"
          data-boxed data-price-list-form
          class="space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <input type="hidden" name="target" value="{{ $target }}">

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.field name="code" :label="__('sales::price_book.code')" :value="old('code', $list->code)" required maxlength="16" />
            <x-ui.field name="name_en" :label="__('sales::price_book.name_en')" :value="old('name_en', $list->name_en)" required maxlength="60" />
            <x-ui.field name="name_bn" :label="__('sales::price_book.name_bn')" :value="old('name_bn', $list->name_bn)" maxlength="60" />

            @if ($target !== 'all')
                <x-ui.select name="target_id" :label="__('sales::price_book.for_'.$target)"
                             :options="$aimOptions" :selected="old('target_id', $aimSelected)" placeholder="-" required />
            @else
                <p class="self-end text-sm text-(--color-ink-muted)">{{ __('sales::price_book.for') }}: {{ __('sales::price_book.everyone') }}</p>
            @endif
        </div>

        <label class="inline-flex items-center gap-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $list->is_active ?? true))>
            {{ __('sales::price_book.is_active') }}
        </label>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('sales.price_book.index', ['target' => $target])" tone="secondary">{{ __('sales::price_book.back') }}</x-ui.button>
            <x-ui.button type="submit" tone="primary">{{ __('sales::price_book.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
