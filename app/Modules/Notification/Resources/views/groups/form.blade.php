{{--
    ⭐ একটা প্রাপক-দল — নাম আর সদস্য (মানুষ, রোল, শাখা, বিভাগ); নিচে আজকের সদস্যরা (ধাপ ৩)।
    ⓘ সদস্য গোনা পাঠানোর সময় — রোলে নতুন কেউ এলে তিনিও পান। শাখার দেয়াল তখনই যাচাই হয়।
--}}
@php $members = (array) old('members', $group->members ?? []); @endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $group->exists ? $group->name : __('notification::group.new') }}</x-slot:title>

    <div class="mx-auto max-w-4xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ $group->exists ? $group->name : __('notification::group.new') }}</h1>
            <a href="{{ route('notification.groups.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('notification::group.back') }}</a>
        </div>

        @include('notification::partials.flash')

        <form method="POST" action="{{ $group->exists ? route('notification.groups.update', $group) : route('notification.groups.store') }}"
              class="space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            @if ($group->exists) @method('PUT') @endif

            <div class="grid gap-3 md:grid-cols-3">
                <label class="grid gap-1 text-2xs text-(--color-ink-muted) md:col-span-2">
                    {{ __('notification::group.name') }}
                    <input type="text" name="name" maxlength="120" required value="{{ old('name', $group->name) }}"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                </label>
                <label class="flex items-center gap-2 self-end text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $group->is_active))>
                    {{ __('notification::rule.active') }}
                </label>
            </div>

            <div class="grid gap-3 md:grid-cols-4">
                @foreach (\App\Models\NotificationRecipientGroup::KINDS as $kind)
                    @include('notification::partials.pick', ['label' => __('notification::rule.kinds.'.$kind), 'name' => 'members['.$kind.']', 'options' => $choices[$kind], 'chosen' => $members[$kind] ?? []])
                @endforeach
            </div>

            <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::group.save') }}</button>
        </form>

        @if ($group->exists)
            <section class="space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="text-sm font-medium">{{ __('notification::group.today', ['count' => $people->count()]) }}</h2>
                <ul class="grid gap-1 text-2xs md:grid-cols-3">
                    @foreach ($people as $person)
                        <li data-group-member="{{ $person->id }}">{{ $person->name }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.app>
