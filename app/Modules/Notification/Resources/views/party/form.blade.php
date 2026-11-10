{{--
    ⭐ একটা লেখা — নাম, কোন কাগজ, বাংলা আর English, চালু/বন্ধ (মালিকের "ok", ১০ নভেম্বর ২০২৬)।
    ⓘ লেখার ভিতরে {party} {paper_no} {amount} {date} {company} {link} — পাঠানোর সময় আসল মান বসে।
    ⛔ মোছা নেই, বন্ধ আছে — খাতার পুরনো সারিগুলো কোন লেখা থেকে গিয়েছিল, তা থেকে যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $template->exists ? $template->name : __('notification::party.new') }}</x-slot:title>

    <div class="space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ $template->exists ? $template->name : __('notification::party.new') }}</h1>
            <a href="{{ route('notification.party_templates.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('notification::party.back') }}</a>
        </div>

        @include('notification::partials.flash')

        <form method="POST" action="{{ $template->exists ? route('notification.party_templates.update', $template) : route('notification.party_templates.store') }}"
              class="space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            @if ($template->exists) @method('PUT') @endif
            <input type="hidden" name="channel" value="{{ \App\Models\PartyMessageTemplate::WHATSAPP }}">

            <div class="grid gap-3 md:grid-cols-4">
                <label class="grid gap-1 text-2xs text-(--color-ink-muted) md:col-span-2">
                    {{ __('notification::party.name') }}
                    <input type="text" name="name" maxlength="120" required value="{{ old('name', $template->name) }}"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::party.document_type') }}
                    <select name="document_type" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                        <option value="">{{ __('notification::party.any_paper') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(old('document_type', $template->document_type) === $type)>{{ __('notification::party.types.'.$type) }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('notification::party.sort') }}
                        <input type="number" name="sort" min="0" max="9999" value="{{ old('sort', $template->sort) }}"
                               class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm tabular-nums">
                    </label>
                    <label class="flex items-center gap-2 self-end text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active))>
                        {{ __('notification::party.active') }}
                    </label>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::party.body_bn') }}
                    <textarea name="body_bn" rows="7" maxlength="1000" required
                              class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) p-2 text-sm">{{ old('body_bn', $template->body_bn) }}</textarea>
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::party.body_en') }}
                    <textarea name="body_en" rows="7" maxlength="1000" required lang="en"
                              class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) p-2 text-sm">{{ old('body_en', $template->body_en) }}</textarea>
                </label>
            </div>

            <div class="text-2xs text-(--color-ink-muted)">
                <p class="mb-1 font-medium">{{ __('notification::party.variables') }}</p>
                <ul class="flex flex-wrap gap-x-4 gap-y-1">
                    @foreach (\App\Models\PartyMessageTemplate::VARIABLES as $var)
                        <li><code class="text-(--color-ink)">{{ '{'.$var.'}' }}</code> — {{ __('notification::party.vars.'.$var) }}</li>
                    @endforeach
                </ul>
            </div>

            <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::party.save') }}</button>
        </form>
    </div>
</x-layouts.app>
