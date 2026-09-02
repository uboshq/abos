{{--
    একজন কর্মীর পাতা।

    বেতনের অঙ্কগুলো কেবল hr.salary.view অনুমতিতে দেখা যায় — হিসাবরক্ষক
    ছাড়া কারও কারও বেতন জানার দরকার নেই, আর তালিকায় সেটা ফাঁস হওয়া
    উচিত নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $employee->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$employee->name()" :subtitle="$employee->code">
            <x-slot:actions>
                @can('hr.salary.view')
                    <x-ui.button :href="route('hr.employee.salary', $employee)">
                        {{ __('hr::action.salary') }}
                    </x-ui.button>
                @endcan
                @can('hr.employee.manage')
                    <x-ui.button tone="primary" :href="route('hr.employee.edit', $employee)">
                        {{ __('hr::action.edit') }}
                    </x-ui.button>
                @endcan
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

    <div class="grid gap-4 lg:grid-cols-2">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="space-y-2 text-sm">
                @foreach ([
                    'hr::field.department' => $employee->department?->name(),
                    'hr::field.designation' => $employee->designation?->name(),
                    'hr::field.employment_type' => $employee->employmentType?->name(),
                    'hr::field.branch' => $employee->branch?->name(),
                    'hr::field.joining_date' => $employee->joining_date?->format('d M Y'),
                    'hr::field.leaving_date' => $employee->leaving_date?->format('d M Y'),
                    'hr::field.mobile' => $employee->mobile,
                    'hr::field.national_id' => \App\Core\Security\FieldSecurity::show(
                        $employee, 'national_id', $employee->national_id),
                    'hr::field.payment_method' => __('hr::kind.' . $employee->payment_method),
                    'hr::field.bank_account_no' => \App\Core\Security\FieldSecurity::show(
                        $employee, 'bank_account_no', $employee->bank_account_no),
                    'hr::field.mfs_number' => \App\Core\Security\FieldSecurity::show(
                        $employee, 'mfs_number', $employee->mfs_number),
                ] as $label => $value)
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd>{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @can('hr.salary.view')
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('hr::action.salary') }}</h2>

                @if ($components === [])
                    <p class="text-sm text-(--color-ink-muted)">{{ __('hr::message.no_salary_yet') }}</p>
                @else
                    <dl class="space-y-1 text-sm">
                        @foreach ($components as $component)
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-(--color-ink-muted)">
                                    {{ $component['head']->name() }}
                                    @unless ($component['head']->isEarning())
                                        <span class="text-2xs">({{ __('hr::kind.deduction') }})</span>
                                    @endunless
                                </dt>
                                <dd class="num">{{ \App\Core\Support\Money::format($component['amount']) }}</dd>
                            </div>
                        @endforeach

                        <div class="mt-2 flex items-center justify-between gap-2 border-t border-(--color-border) pt-2
                                    font-semibold">
                            <dt>{{ __('hr::field.net') }}</dt>
                            <dd class="num">{{ \App\Core\Support\Money::format($totals['net']) }}</dd>
                        </div>
                    </dl>
                @endif
            </section>
        @endcan
    </div>
</x-layouts.app>
