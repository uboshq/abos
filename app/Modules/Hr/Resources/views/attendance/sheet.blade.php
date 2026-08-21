{{--
    মাসের হাজিরা — কে কতদিন, আর কতদিন বেতন থেকে কাটা যাবে।

    "কাটা যাবে" কলামটাই এই পর্দার আসল কাজ: বেতনের রান ওই সংখ্যাটাই
    ব্যবহার করে, তাই মাস শেষের আগে সেটা দেখে নেওয়ার একটা জায়গা লাগে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::action.monthly_sheet') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('hr::action.monthly_sheet')"
            :subtitle="$month->translatedFormat('F Y')">
            <x-slot:actions>
                <x-ui.button :href="route('hr.attendance.index')">
                    {{ __('hr::menu.attendance') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @unless ($affectsSalary)
        {{-- সুইচ বন্ধ থাকলে সংখ্যাগুলো কেবল তথ্য — বেতনে যাচ্ছে না।
             না বললে কেউ ভাবতেন কাটা হচ্ছে, অথচ হচ্ছে না। --}}
        <div class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
                    text-(--color-ink-muted)">
            {{ __('hr::message.attendance_off_note') }}
        </div>
    @endunless

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <label class="block">
            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                         text-(--color-ink-muted)">{{ __('hr::field.month') }}</span>
            <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                   onchange="this.form.submit()"
                   class="rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 py-1.5 text-sm">
        </label>
    </form>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('hr::message.no_employees_for_day')"
            :rows="$rows"
            :columns="[
                ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '8rem',
                 'render' => fn ($r) => $r['employee']->code],
                ['key' => 'name', 'label' => __('hr::field.name'),
                 'render' => fn ($r) => $r['employee']->name()],
                ['key' => 'present', 'label' => __('hr::field.present'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['present']],
                ['key' => 'absent', 'label' => __('hr::field.absent'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['absent']],
                ['key' => 'leave', 'label' => __('hr::kind.leave'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['leave']],
                ['key' => 'late', 'label' => __('hr::field.is_late'), 'numeric' => true, 'width' => '6rem',
                 'render' => fn ($r) => $r['summary']['late']],
                ['key' => 'marked', 'label' => __('hr::field.marked'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['marked']],
                ['key' => 'unpaid', 'label' => __('hr::field.unpaid_days'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($r) => $r['summary']['unpaid']],
            ]" />
    </div>
</x-layouts.app>
