{{--
    বাজেট লেখা — এক খাত, এক বছর, (ঐচ্ছিক) এক বিভাগ, বারো মাস একসাথে।

    ⓘ নতুন আর সম্পাদনা একই ফর্ম: পরিকল্পনার সারির "সম্পাদনা" এখানে আসে খাত,
    বছর আর বিভাগ নিয়ে, আর মাসগুলো আগে থেকে ভরা থাকে। ⚠️ মাসের ঘর খালি
    করে জমা দিলে সেই মাসের বাজেট উঠে যায় — আলাদা "মুছুন" নেই, কারণ
    খালি মানেই "এই মাসে বাজেট নেই"।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::budget.new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$accountId ? __('finance::budget.edit_title') : __('finance::budget.new')"
                          :subtitle="__('finance::budget.form_note')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section data-boxed class="max-w-screen-2xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST" action="{{ route('finance.budget.store') }}"
              x-data="{ busy: false }"
              @submit="busy ? $event.preventDefault() : (busy = true)"
              class="space-y-4">
            @csrf

            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="year" type="number" :label="__('finance::budget.year')"
                            :value="old('year', $year)" min="2000" max="2100" required />

                <x-ui.select name="account_id" :label="__('finance::budget.account')"
                             :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' — '.$a->name()])"
                             :selected="old('account_id', $accountId)" placeholder="-" required />

                <x-ui.select name="cost_center_id" :label="__('finance::budget.center')"
                             :options="$centers->mapWithKeys(fn ($c) => [$c->id => $c->code.' — '.$c->name()])"
                             :selected="old('cost_center_id', $center)" :placeholder="__('finance::budget.no_center')" />
            </div>

            <fieldset>
                <legend class="mb-2 text-sm font-medium">{{ __('finance::budget.months') }}</legend>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach (range(1, 12) as $m)
                        <x-ui.field :name="'months['.$m.']'" type="number" step="0.01" inputmode="decimal" numeric
                                    :label="__('finance::budget.month_long.'.$m)"
                                    :value="old('months.'.$m, $months[$m])" />
                    @endforeach
                </div>
            </fieldset>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" tone="primary" ::class="busy && 'pointer-events-none opacity-70'">
                    {{ __('core.action.save') }}
                </x-ui.button>
                <x-ui.button tone="secondary" :href="route('finance.budget.index', ['year' => $year])">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
