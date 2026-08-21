{{--
    একজন কর্মীর বেতনের কাঠামো।

    উপরে এই তারিখে যা কার্যকর, নিচে নতুন তারিখ থেকে নতুন অঙ্ক, আর
    শেষে পুরো ইতিহাস। পুরনো সারি সম্পাদনার কোনো পথ নেই — সেটাই
    ইচ্ছাকৃত, কারণ গত মাসের বেতনশিট গত মাসের অঙ্কেই থাকতে হবে।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        ['key' => 'name', 'label' => __('hr::field.name'),
         'render' => fn ($h) => view('hr::employee.partials.head', ['head' => $h])],
        ['key' => 'calculation', 'label' => __('hr::field.calculation'),
         'render' => fn ($h) => __('hr::kind.'.$h->calculation)],
        ['key' => 'amount', 'label' => __('hr::field.amount'), 'numeric' => true, 'width' => '11rem',
         'render' => fn ($h) => view('hr::employee.partials.amount',
             ['head' => $h, 'components' => $components])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $employee->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::action.salary')" :subtitle="$employee->label()">
            <x-slot:actions>
                <x-ui.button :href="route('hr.employee.show', $employee)">
                    {{ __('hr::action.back_to_employee') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
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

    @if ($heads->isEmpty())
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-8 text-center">
            <h2 class="text-lg font-semibold">{{ __('hr::message.empty_heads') }}</h2>
            <p class="mx-auto mt-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('hr::message.empty_heads_note') }}
            </p>
            @can('hr.salary.manage')
                <x-ui.button class="mt-4" tone="primary" :href="route('hr.salary_head.index')">
                    {{ __('hr::menu.salary_heads') }}
                </x-ui.button>
            @endcan
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-[1fr_22rem]">

            @can('hr.salary.manage')
                <form method="POST" action="{{ route('hr.employee.salary.store', $employee) }}"
                      class="rounded-(--radius-card) border border-(--color-border)
                             bg-(--color-surface-card) p-4">
                    @csrf

                    <div class="mb-3 grid gap-3 sm:grid-cols-[14rem_1fr] sm:items-end">
                        <x-ui.field name="effective_from" type="date"
                                    :label="__('hr::field.effective_from')"
                                    :value="old('effective_from', now()->toDateString())" required />
                        <p class="text-2xs text-(--color-ink-muted)">{{ __('hr::message.salary_note') }}</p>
                    </div>

        <x-ui.table :rows="$heads"
                    :columns="$columns"
                    :empty="__('core.empty.no_results')" />

                    <div class="mt-3 flex justify-end">
                        <x-ui.button type="submit" tone="primary">{{ __('hr::action.save') }}</x-ui.button>
                    </div>
                </form>
            @endcan

            {{-- এই তারিখে যা কার্যকর --}}
            <aside class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('hr::field.net') }}</h2>
                <p class="mb-3 text-2xs text-(--color-ink-muted)">
                    {{ __('hr::message.as_of', ['date' => $on->format('d M Y')]) }}
                </p>

                @if ($components === [])
                    <p class="text-sm text-(--color-ink-muted)">{{ __('hr::message.no_salary_yet') }}</p>
                @else
                    <dl class="space-y-1 text-sm">
                        @foreach ($components as $component)
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-(--color-ink-muted)">{{ $component['head']->name() }}</dt>
                                <dd class="num">{{ \App\Core\Support\Money::format($component['amount']) }}</dd>
                            </div>
                        @endforeach

                        <div class="mt-2 flex items-center justify-between gap-2 border-t
                                    border-(--color-border) pt-2">
                            <dt class="text-(--color-ink-muted)">{{ __('hr::field.gross') }}</dt>
                            <dd class="num">{{ \App\Core\Support\Money::format($totals['gross']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-(--color-ink-muted)">{{ __('hr::field.deductions') }}</dt>
                            <dd class="num">{{ \App\Core\Support\Money::format($totals['deductions']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 font-semibold">
                            <dt>{{ __('hr::field.net') }}</dt>
                            <dd class="num">{{ \App\Core\Support\Money::format($totals['net']) }}</dd>
                        </div>
                    </dl>
                @endif
            </aside>
        </div>

        {{-- পুরো ইতিহাস — কে কবে কোন অঙ্ক বসিয়েছিল --}}
        <div data-boxed class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-2 font-semibold">
                {{ __('hr::message.history') }}
            </h2>

            <x-ui.table
                :empty="__('hr::message.no_salary_yet')"
                :rows="$history"
                :columns="[
                    ['key' => 'effective_from', 'label' => __('hr::field.effective_from'), 'width' => '12rem',
                     'render' => fn ($r) => $r->effective_from->format('d M Y')],
                    ['key' => 'head', 'label' => __('hr::field.name'),
                     'render' => fn ($r) => $r->salaryHead?->name() ?? '—'],
                    ['key' => 'amount', 'label' => __('hr::field.amount'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($r) => \App\Core\Support\Money::format($r->amount)],
                    ['key' => 'creator', 'label' => __('hr::field.entered_by'), 'width' => '12rem',
                     'render' => fn ($r) => $r->creator?->name ?? '—'],
                ]" />
        </div>
    @endif
</x-layouts.app>
