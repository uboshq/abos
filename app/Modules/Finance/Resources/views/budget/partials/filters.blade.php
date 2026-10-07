{{--
    বছর আর বিভাগ — একটা GET ফর্ম, জমা দিলেই ছাঁকে।
    ⓘ `$extra` দিয়ে পাতা নিজের বাড়তি ঘর (মাস) যোগ করে।
--}}
<form method="GET" class="mb-3 flex flex-wrap items-end gap-3">
    <x-ui.field name="year" type="number" :label="__('finance::budget.year')" :value="$year"
                min="2000" max="2100" class="w-28" />

    <x-ui.select name="center" :label="__('finance::budget.center')"
                 :options="$centers->mapWithKeys(fn ($c) => [$c->id => $c->code.' — '.$c->name()])"
                 :selected="$center" :placeholder="__('finance::budget.all_centers')" />

    {{ $extra ?? '' }}

    <x-ui.button type="submit" tone="secondary">{{ __('finance::budget.show') }}</x-ui.button>
</form>
