{{--
    ⭐ টেমপ্লেট স্টুডিও — বাংলা ও ইংরেজি লেখা, অনুমোদিত চলক, পূর্বরূপ, পরীক্ষার পাঠানো, সংস্করণ, প্রকাশ, ফেরা
    (মালিকের স্পেক §৯গ; ধাপ ৩)।

    ⛔ পূর্বরূপ `{{ }}` দিয়ে escape করা — লেখায় HTML থাকলেও আঁকা হয় না, অক্ষর হিসেবেই দেখায়।
    ⓘ প্রতিটা সংরক্ষণ নতুন খসড়া সংস্করণ; প্রকাশ আলাদা চাবিতে। পুরনো সংস্করণ প্রকাশ করা মানেই আগের লেখায় ফেরা।
--}}
@php
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
    $area = 'rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-sm';
    $chosen = (array) old('channels', $template->channels ?? []);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $template->exists ? $template->name : __('notification::template.new') }}</x-slot:title>

    <div class="mx-auto max-w-5xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ $template->exists ? $template->name : __('notification::template.new') }}</h1>
            <a href="{{ route('notification.templates.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('notification::template.back') }}</a>
        </div>

        @include('notification::partials.flash')

        <form method="POST" action="{{ $template->exists ? route('notification.templates.update', $template) : route('notification.templates.store') }}"
              class="space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            @if ($template->exists) @method('PUT') @endif

            <div class="grid gap-3 md:grid-cols-4">
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::template.code') }}
                    <input type="text" name="code" maxlength="48" required value="{{ old('code', $template->code) }}" class="{{ $field }} font-mono">
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted) md:col-span-2">
                    {{ __('notification::template.name') }}
                    <input type="text" name="name" maxlength="120" required value="{{ old('name', $template->name) }}" class="{{ $field }}">
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::template.category') }}
                    <select name="category" class="{{ $field }}">
                        @foreach (\App\Core\Support\NotificationKinds::CATEGORIES as $category)
                            <option value="{{ $category }}" @selected(old('category', $template->category) === $category)>{{ __('core.notify.category.'.$category) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-4 text-sm">
                <span class="text-2xs text-(--color-ink-muted)">{{ __('notification::template.channels') }}</span>
                @foreach (\App\Models\NotificationChannel::ALL as $channel)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" name="channels[]" value="{{ $channel }}" @checked(in_array($channel, $chosen, true))>
                        {{ __('notification::channel.names.'.$channel) }}
                    </label>
                @endforeach
                <label class="flex items-center gap-1">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active))>
                    {{ __('notification::template.active') }}
                </label>
            </div>

            <p class="text-2xs text-(--color-ink-muted)">
                {{ __('notification::template.variables_note') }}
                @foreach ($variables as $name)
                    <code class="rounded bg-(--color-surface-sunken) px-1" title="{{ __('core.notify.var.'.$name) }}">{{ '{'.$name.'}' }}</code>
                @endforeach
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                @foreach (['bn', 'en'] as $lang)
                    <div class="space-y-2" data-template-lang="{{ $lang }}">
                        <h2 class="text-sm font-medium">{{ __('notification::template.lang.'.$lang) }}</h2>
                        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                            {{ __('notification::template.subject') }}
                            <input type="text" name="subject_{{ $lang }}" maxlength="191" value="{{ old('subject_'.$lang, $version?->{'subject_'.$lang}) }}" class="{{ $field }}">
                        </label>
                        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                            {{ __('notification::template.title_field') }}
                            <input type="text" name="title_{{ $lang }}" maxlength="191" required value="{{ old('title_'.$lang, $version?->{'title_'.$lang}) }}" class="{{ $field }}">
                        </label>
                        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                            {{ __('notification::template.body') }}
                            <textarea name="body_{{ $lang }}" rows="4" maxlength="1000" class="{{ $area }}">{{ old('body_'.$lang, $version?->{'body_'.$lang}) }}</textarea>
                        </label>
                    </div>
                @endforeach
            </div>

            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::template.change_note') }}
                <input type="text" name="note" maxlength="191" value="{{ old('note') }}" class="{{ $field }}">
            </label>

            <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::template.save') }}</button>
        </form>

        @if ($version !== null)
            <section class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="text-sm font-medium">{{ __('notification::template.preview', ['version' => $version->version]) }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($previews as $lang => $preview)
                        <div class="space-y-1 rounded-(--radius-field) border border-(--color-border) p-3" data-preview="{{ $lang }}">
                            <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::template.lang.'.$lang) }} · {{ $preview['subject'] }}</p>
                            <p class="font-medium">{{ $preview['title'] }}</p>
                            <p class="text-sm">{{ $preview['body'] }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('notification.templates.test', $template) }}">
                        @csrf
                        <input type="hidden" name="version_id" value="{{ $version->id }}">
                        <button type="submit" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm">{{ __('notification::template.test') }}</button>
                    </form>
                    @if ($canPublish && (int) $template->published_version_id !== (int) $version->id)
                        <form method="POST" action="{{ route('notification.templates.publish', $template) }}">
                            @csrf
                            <input type="hidden" name="version_id" value="{{ $version->id }}">
                            <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::template.publish', ['version' => $version->version]) }}</button>
                        </form>
                    @endif
                </div>
            </section>

            <section class="space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="text-sm font-medium">{{ __('notification::template.versions') }}</h2>
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::template.version') }}</th>
                            <th class="text-start">{{ __('notification::template.author') }}</th>
                            <th class="text-start">{{ __('notification::template.when') }}</th>
                            <th class="text-start">{{ __('notification::template.change_note') }}</th>
                            <th class="text-start">{{ __('notification::template.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($versions as $v)
                            <tr class="border-t border-(--color-border)" data-template-version="{{ $v->version }}">
                                <td class="text-2xs"><a href="{{ route('notification.templates.edit', [$template, 'version' => $v->version]) }}" class="hover:underline">v{{ $v->version }}</a></td>
                                <td class="text-2xs">{{ $v->author?->name ?? '—' }}</td>
                                <td class="text-2xs">{{ $v->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="text-2xs">{{ $v->note ?? '—' }}</td>
                                <td class="text-2xs">{{ (int) $template->published_version_id === (int) $v->id ? __('notification::template.live') : ($v->published_at ? __('notification::template.was_live') : __('notification::template.draft')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    </div>
</x-layouts.app>
