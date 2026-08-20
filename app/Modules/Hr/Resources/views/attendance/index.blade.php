{{--
    একটা দিনের হাজিরা — সবার সারি একসাথে।

    মাসিক গ্রিড নয়: ত্রিশ দিন × বিশ জন মানে ছয়শো ঘর, আর ফোনে সেটা ভরা
    যায় না। গুদামের লোক সকালে একবার আজকের পর্দাটা খোলেন — কাজের ধরনটাই
    এই রকম।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.attendance') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('hr::menu.attendance')"
            :subtitle="$date->translatedFormat('d M Y, l')">
            <x-slot:actions>
                <x-ui.button :href="route('hr.attendance.sheet', ['month' => $date->format('Y-m')])">
                    {{ __('hr::action.monthly_sheet') }}
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

    {{-- দিন বাছা — আলাদা ফর্ম, কারণ এটা শুধু পাতা বদলায়, কিছু সংরক্ষণ করে না --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <label class="block">
            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                         text-(--color-ink-muted)">{{ __('hr::field.work_date') }}</span>
            <x-ui.date name="date"
                       :value="$date->toDateString()"
                       :submit-on-change="true"
                       class="text-sm" />
        </label>
    </form>

    @if ($employees->isEmpty())
        <div class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-8 text-center text-sm text-(--color-ink-muted)">
            {{ __('hr::message.no_employees_for_day') }}
        </div>
    @else
        <form method="POST" action="{{ route('hr.attendance.store') }}">
            @csrf
            <input type="hidden" name="work_date" value="{{ $date->toDateString() }}">

            <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
                <p class="border-b border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">
                    {{ __('hr::message.attendance_note') }}
                </p>

                <table class="ui-grid is-dense w-full text-sm">
                    <thead class="border-b border-(--color-border) text-2xs uppercase
                                  tracking-wide text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('hr::field.code') }}</th>
                            <th class="text-start">{{ __('hr::field.name') }}</th>
                            <th class="text-start">{{ __('hr::field.attendance_status') }}</th>
                            <th class="text-center">{{ __('hr::field.is_late') }}</th>
                            <th class="text-start">{{ __('hr::field.remarks') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            @php $row = $existing->get($employee->id); @endphp
                            <tr class="border-b border-(--color-border)">
                                <td class="num">{{ $employee->code }}</td>
                                <td>{{ $employee->name() }}</td>
                                <td>
                                    <select name="rows[{{ $employee->id }}][status]"
                                            class="rounded-(--radius-field) border border-(--color-border)
                                                   bg-(--color-surface-app) px-2 py-1.5 text-sm">
                                        <option value="">—</option>
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}"
                                                    @selected($row?->status === $status)>
                                                {{ __('hr::kind.' . $status) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" name="rows[{{ $employee->id }}][is_late]" value="1"
                                           @checked($row?->is_late) class="size-4">
                                </td>
                                <td>
                                    <input type="text" name="rows[{{ $employee->id }}][remarks]"
                                           value="{{ $row?->remarks }}"
                                           class="w-full rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-app) px-2 py-1.5 text-sm">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @can('hr.attendance.manage')
                <div class="mt-3 flex justify-end">
                    <x-ui.button type="submit" tone="primary">
                        {{ __('hr::action.save_attendance') }}
                    </x-ui.button>
                </div>
            @endcan
        </form>
    @endif
</x-layouts.app>
