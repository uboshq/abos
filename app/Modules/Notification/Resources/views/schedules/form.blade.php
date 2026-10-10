{{--
    ⭐ একটা সূচি — টেমপ্লেট, দল, গুরুত্ব, কখন (সূচির নিজের সময় অঞ্চলে), কতবার (ধাপ ৩)।
    ⓘ পাঠায় প্রতি মিনিটের `abos:notifications-schedule`; একবারের সূচি পাঠানোর পরে নিজে বন্ধ হয়।
--}}
@php $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm'; @endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $schedule->exists ? $schedule->name : __('notification::schedule.new') }}</x-slot:title>

    <div class="mx-auto max-w-3xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ $schedule->exists ? $schedule->name : __('notification::schedule.new') }}</h1>
            <a href="{{ route('notification.schedules.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('notification::schedule.back') }}</a>
        </div>

        @include('notification::partials.flash')

        <form method="POST" action="{{ $schedule->exists ? route('notification.schedules.update', $schedule) : route('notification.schedules.store') }}"
              class="grid gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 md:grid-cols-2">
            @csrf
            @if ($schedule->exists) @method('PUT') @endif

            <label class="grid gap-1 text-2xs text-(--color-ink-muted) md:col-span-2">
                {{ __('notification::schedule.name') }}
                <input type="text" name="name" maxlength="120" required value="{{ old('name', $schedule->name) }}" class="{{ $field }}">
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::schedule.template') }}
                <select name="template_id" required class="{{ $field }}">
                    @foreach ($templates as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('template_id', $schedule->template_id) === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::schedule.group') }}
                <select name="group_id" required class="{{ $field }}">
                    @foreach ($groups as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('group_id', $schedule->group_id) === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::rule.priority') }}
                <select name="priority" class="{{ $field }}">
                    @foreach (\App\Core\Support\NotificationKinds::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(old('priority', $schedule->priority) === $priority)>{{ __('core.notify.priority.'.$priority) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::schedule.recurrence') }}
                <select name="recurrence" class="{{ $field }}">
                    @foreach (\App\Models\NotificationSchedule::RECURRENCES as $recurrence)
                        <option value="{{ $recurrence }}" @selected(old('recurrence', $schedule->recurrence) === $recurrence)>{{ __('notification::schedule.recurrences.'.$recurrence) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::schedule.run_at') }}
                <input type="datetime-local" name="run_at" required value="{{ old('run_at', $local) }}" class="{{ $field }}">
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::schedule.timezone') }}
                <select name="timezone" class="{{ $field }}">
                    @foreach ($zones as $zone)
                        <option value="{{ $zone }}" @selected(old('timezone', $schedule->timezone) === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm md:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $schedule->is_active))>
                {{ __('notification::rule.active') }}
            </label>
            @if ($templates === [] || $groups === [])
                <p class="text-2xs text-(--color-ink-muted) md:col-span-2">{{ __('notification::schedule.needs') }}</p>
            @endif

            <div class="md:col-span-2">
                <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::schedule.save') }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
