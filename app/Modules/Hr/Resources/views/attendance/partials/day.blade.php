{{-- দিনের খাতার ছক আর তার জমার ফর্ম। চাই: $employees, $existing, $date, $statuses, $typed, $facetPanel (ABOS রূপে; নইলে null) --}}
@if ($employees->isEmpty())
    <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-8 text-center text-sm text-(--color-ink-muted)">
        {{ __('hr::message.no_employees_for_day') }}
    </div>
@else
    <form id="attendance-form" method="POST" action="{{ route('hr.attendance.store') }}" @if ($facetPanel) x-on:submit="saved()" @endif @if ($typed !== null) data-unsaved @endif>
        @csrf
        <input type="hidden" name="work_date" value="{{ $date->toDateString() }}">

        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            @unless ($facetPanel)
            <p class="border-b border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">
                {{ __('hr::message.attendance_note') }}
            </p>
            @endunless

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
                        @php
                            $row = $existing->get($employee->id);
                            $was = $typed === null ? null : (array) ($typed[$employee->id] ?? []);
                            $status0 = $was === null ? $row?->status : ($was['status'] ?? null);
                            $late0 = $was === null ? (bool) $row?->is_late : (bool) ($was['is_late'] ?? false);
                            $remarks0 = $was === null ? $row?->remarks : ($was['remarks'] ?? null);
                        @endphp
                        <tr class="border-b border-(--color-border)" @if ($facetPanel) data-day-row data-find="{{ $employee->code }} {{ $employee->name() }}" data-department="{{ $employee->department_id }}" @endif>
                            <td class="num">{{ $employee->code }}</td>
                            <td>{{ $employee->name() }}</td>
                            <td>
                                <select name="rows[{{ $employee->id }}][status]"
                                        class="rounded-(--radius-field) border border-(--color-border)
                                               bg-(--color-surface-app) px-2 py-1.5 text-sm">
                                    <option value="">—</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}"
                                                @selected($status0 === $status)>
                                            {{ __('hr::kind.' . $status) }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="text-center">
                                <input type="checkbox" name="rows[{{ $employee->id }}][is_late]" value="1"
                                       @checked($late0) class="size-4">
                            </td>
                            <td>
                                <input type="text" name="rows[{{ $employee->id }}][remarks]"
                                       value="{{ $remarks0 }}"
                                       class="w-full rounded-(--radius-field) border border-(--color-border)
                                              bg-(--color-surface-app) px-2 py-1.5 text-sm">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @can('hr.attendance.manage')
            @unless ($facetPanel)
            <div class="mt-3 flex justify-end">
                <x-ui.button type="submit" tone="primary">
                    {{ __('hr::action.save_attendance') }}
                </x-ui.button>
            </div>
            @endunless
        @endcan
    </form>
@endif
