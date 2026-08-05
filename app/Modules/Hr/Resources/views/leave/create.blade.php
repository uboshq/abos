{{--
    ছুটির আবেদন।

    দিনের সংখ্যা তারিখ থেকে গুনে বসানো হয় না — আধা দিনের ছুটি আছে, আর
    মাঝে সাপ্তাহিক ছুটি পড়লে সেটা গোনা হবে কি না তা প্রতিষ্ঠানের নীতি।
    তাই সংখ্যাটা মানুষই লেখে, আর সেবা স্তর কেবল দেখে সেটা তারিখের
    পরিসরের চেয়ে বড় নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::action.apply_leave') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::action.apply_leave')" />
    </x-slot:header>

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

    @if ($types->isEmpty())
        <div class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-8 text-center">
            <h2 class="text-lg font-semibold">{{ __('hr::message.empty_leave_types') }}</h2>
            <x-ui.button class="mt-4" tone="primary" :href="route('hr.leave_type.index')">
                {{ __('hr::menu.leave_types') }}
            </x-ui.button>
        </div>
    @else
        <form method="POST" action="{{ route('hr.leave.store') }}"
              class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf

            <div class="grid gap-3 md:grid-cols-3">
                <x-ui.select name="employee_id" :label="__('hr::field.name')"
                             :options="$employees->mapWithKeys(fn ($e) => [$e->id => $e->label()])"
                             :selected="old('employee_id')" placeholder="-" required />

                <x-ui.select name="leave_type_id" :label="__('hr::field.leave_type')"
                             :options="$types->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="old('leave_type_id')" placeholder="-" required />

                <x-ui.field name="days" type="number" step="0.5" min="0.5"
                            :label="__('hr::field.days')" :value="old('days')" required />

                <x-ui.field name="from_date" type="date" :label="__('hr::field.from_date')"
                            :value="old('from_date', now()->toDateString())" required />

                <x-ui.field name="to_date" type="date" :label="__('hr::field.to_date')"
                            :value="old('to_date', now()->toDateString())" required />

                <x-ui.field name="reason" :label="__('hr::field.reason')" :value="old('reason')" />
            </div>

            <div class="mt-3 flex justify-end gap-2">
                <x-ui.button :href="route('hr.leave.index')">{{ __('core.action.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary">{{ __('hr::action.apply_leave') }}</x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
