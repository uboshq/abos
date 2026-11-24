@if ($canInstallDefaults)
    <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-8 text-center">
        {{-- ⓘ খালি তালিকা, বা কিছু প্রমিত ধরন বাকি (যেমন নতুন মাতৃত্বকালীন) — [[LeaveTypeDefaults::anyMissing()]] --}}
        <h2 class="text-lg font-semibold">{{ __($types->isEmpty() && ! request()->filled('q') && ! request()->filled('paid') ? 'hr::message.empty_leave_types' : 'hr::message.leave_types_missing') }}</h2>

        @can('hr.leave.manage')
            <form method="POST" action="{{ route('hr.leave_type.install') }}" class="mt-4">
                @csrf
                <x-ui.button type="submit" tone="primary">
                    {{ __('hr::action.install_leave_types') }}
                </x-ui.button>
            </form>
        @endcan
    </div>
@endif
