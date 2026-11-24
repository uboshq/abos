{{--
    ⭐ প্রসবের আসল দিন — মঞ্জুর হওয়া মাতৃত্বকালীন ছুটিতে (ধারা ৪৫; fe-র উত্তর ৩, ২১ অক্টোবর ২০২৬; [[MaternityDeliveryService]]; a6)।
    ⓘ অপেক্ষার অনুরোধ থাকলে "সইয়ের অপেক্ষায়" আর "সই দিন"/প্রত্যাখ্যান; নইলে দিন লেখার ঘর। যে মানুষ লিখেছেন তিনি সই দিতে চাইলে সেবা ফেরায়।
--}}
@php($waiting = \App\Modules\Hr\Models\MaternityDelivery::query()->where('leave_application_id', $application->id)
    ->where('status', \App\Modules\Hr\Models\MaternityDelivery::PENDING)->first())
@can('hr.leave.approve')
    @if ($waiting !== null)
        <span data-delivery-waiting class="flex flex-wrap items-center justify-end gap-1">
            <span class="text-2xs text-(--color-ink-muted)">{{ __('hr::message.delivery_waiting', ['date' => $waiting->actual_delivery_date->toDateString(), 'to' => $waiting->new_to_date->toDateString()]) }}</span>
            <form method="POST" action="{{ route('hr.leave.delivery_sign', $waiting->id) }}">
                @csrf
                <button type="submit" class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-success) transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('hr::action.sign') }}
                </button>
            </form>
            <form method="POST" action="{{ route('hr.leave.delivery_reject', $waiting->id) }}" class="flex items-center gap-1">
                @csrf
                <input type="text" name="remarks" required placeholder="{{ __('hr::field.reason') }}"
                       class="w-24 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-1.5 py-1 text-2xs">
                <button type="submit" class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)">
                    {{ __('hr::action.reject') }}
                </button>
            </form>
        </span>
    @else
        <form method="POST" action="{{ route('hr.leave.delivery', $application->id) }}" class="flex items-center justify-end gap-1">
            @csrf
            <label>
                <span class="sr-only">{{ __('hr::field.actual_delivery_date') }}</span>
                <x-ui.date name="actual_delivery_date" id="delivery-{{ $application->id }}" required dense
                           :value="$application->actual_delivery_date?->toDateString()" />
            </label>
            <button type="submit" class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)">
                {{ __('hr::action.record_delivery') }}
            </button>
        </form>
    @endif
@endcan
