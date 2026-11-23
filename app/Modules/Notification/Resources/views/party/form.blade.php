{{--
    ⭐ একটা লেখার সম্পাদক — লেখার পাতার পাশে (নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব খ; [[notification::party.templates]])।

    নাম (মাথায়) · চালু · পথ · কোন কাগজ · "বসানো যায়" চিপ (চাপলে লেখার ঘরের কার্সরে বসে) · বাংলা লেখা + English লেখা পাশাপাশি ·
    বাংলার নমুনা (নমুনার মান বসিয়ে) · অক্ষর গোনা · বাতিল / সংরক্ষণ।
    ⓘ WhatsApp-এর লেখায় Meta-র অনুমোদনের অবস্থা আর "Meta-তে অনুমোদনে পাঠান" — কেবল যখন সেই রুট আছে (Cloud API)।
    ⛔ মোছা নেই, বন্ধ আছে — খাতার পুরনো সারিগুলো কোন লেখা থেকে গিয়েছিল, তা থেকে যায়।
--}}
@php
    $company = \App\Models\Company::query()->find(\App\Core\Support\CompanyContext::id());
    $sample = (array) __('notification::party.sample');
    $sample['company'] = (string) ($company?->name_bn ?: $company?->name_en ?: '');
    $sample['link'] = url('/p/7Kp2…');
    $editorWords = [
        'count' => __('notification::party.count', ['n' => '#n']),
        'countSms' => __('notification::party.count_sms', ['n' => '#n', 'k' => '#k']),
    ];
    $isWa = old('channel', $template->channel) === \App\Models\PartyMessageTemplate::WHATSAPP;
    $waStatus = $template->getAttribute('wa_status');
    $field = 'h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm font-normal text-(--color-ink)';
    $area = 'min-h-28 w-full resize-y rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) p-2 text-sm font-normal leading-relaxed text-(--color-ink)';
    $back = $template->exists
        ? route('notification.party_templates.edit', ['template' => $template->id] + $tabQuery)
        : route('notification.party_templates.index', $tabQuery);
@endphp

<div class="min-w-0 self-start rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3" data-template-editor
     x-data="partyTemplateEditor({ sample: @js($sample), words: @js($editorWords) })">
    <form method="POST" action="{{ $template->exists ? route('notification.party_templates.update', $template) : route('notification.party_templates.store') }}"
          class="grid gap-2.5">
        @csrf
        @if ($template->exists) @method('PUT') @endif
        @if ($tabQuery !== [])
            <input type="hidden" name="tab" value="{{ $tabQuery['tab'] }}">
        @endif
        {{-- ⓘ ক্রম পর্দায় নেই (নমুনায় নেই) — যা ছিল তা-ই থাকে --}}
        <input type="hidden" name="sort" value="{{ old('sort', $template->sort ?? 100) }}">

        <div class="flex flex-wrap items-center gap-2">
            {{-- ⓘ মাথার নামটাই নামের ঘর — নমুনার শিরোনামের জায়গায় --}}
            <input type="text" name="name" maxlength="120" required value="{{ old('name', $template->name) }}"
                   aria-label="{{ __('notification::party.name') }}" placeholder="{{ __('notification::party.name') }}"
                   class="min-w-0 flex-1 rounded-(--radius-field) border border-transparent bg-transparent px-1 py-0.5 text-sm font-bold
                          hover:border-(--color-border) focus:border-(--color-brand-500)" data-template-name>

            @if ($isWa && $template->exists && $metaSubmit)
                {{-- ⓘ Meta-র অনুমোদন — লেখা বদলালে মুছে যায় ([[PartyMessageTemplate::booted()]]) --}}
                <span @class([
                    'inline-flex whitespace-nowrap rounded-full px-2.5 py-px text-xs font-semibold',
                    'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $waStatus === 'approved',
                    'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => in_array($waStatus, ['pending', 'paused'], true),
                    'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => in_array($waStatus, ['rejected', 'disabled'], true),
                    'bg-(--color-surface-sunken) text-(--color-ink-muted)' => ! in_array($waStatus, ['approved', 'pending', 'paused', 'rejected', 'disabled'], true),
                ]) data-wa-status="{{ $waStatus ?? 'none' }}">{{ __('notification::party.wa_states.'.(in_array($waStatus, ['approved', 'pending', 'paused', 'rejected', 'disabled'], true) ? $waStatus : 'none')) }}</span>
            @endif

            <label class="flex items-center gap-1.5 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active))>
                {{ __('notification::party.active') }}
            </label>
        </div>

        <div class="grid gap-2.5 sm:grid-cols-2">
            <label class="grid gap-1 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('notification::party.channel') }}
                <select name="channel" x-ref="channel" x-on:change="read()" class="{{ $field }}" data-template-road>
                    @foreach (\App\Models\PartyMessageTemplate::CHANNELS as $channel)
                        <option value="{{ $channel }}" @selected(old('channel', $template->channel) === $channel)>{{ __('notification::party.channels.'.$channel) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('notification::party.document_type') }}
                <select name="document_type" class="{{ $field }}" data-template-type>
                    <option value="">{{ __('notification::party.any_paper') }}</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(old('document_type', $template->document_type) === $type)>{{ __('notification::party.types.'.$type) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="grid gap-1">
            <span class="text-xs font-semibold text-(--color-ink-muted)">{{ __('notification::party.variables') }}</span>
            <div class="flex flex-wrap gap-1.5" data-template-vars>
                @foreach (\App\Models\PartyMessageTemplate::VARIABLES as $var)
                    {{-- ⓘ mousedown-এ নয়, click-এ — কিন্তু ঘরের কার্সর হারায় না: শেষ মন যে ঘরে ছিল সেটা মনে রাখা ([[partyTemplateEditor]]) --}}
                    <button type="button" x-on:click="insert('{{ $var }}')" data-var="{{ $var }}" title="{{ '{'.$var.'}' }}"
                            class="rounded-full border border-dashed border-(--color-brand-500) bg-(--color-brand-50) px-2.5 py-0.5 text-xs font-semibold text-(--color-brand-600)">
                        {{ __('notification::party.vars.'.$var) }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="grid gap-2.5 sm:grid-cols-2">
            <label class="grid gap-1 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('notification::party.body_bn') }}
                <textarea name="body_bn" rows="6" maxlength="1000" required x-ref="bn" x-on:input="read()" x-on:focus="focus($event)"
                          class="{{ $area }}">{{ old('body_bn', $template->body_bn) }}</textarea>
            </label>
            <label class="grid gap-1 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('notification::party.body_en') }}
                <textarea name="body_en" rows="6" maxlength="1000" required lang="en" x-ref="en" x-on:focus="focus($event)"
                          class="{{ $area }}">{{ old('body_en', $template->body_en) }}</textarea>
            </label>
        </div>

        <div class="grid gap-1">
            <span class="text-xs font-semibold text-(--color-ink-muted)">{{ __('notification::party.preview_bn') }}</span>
            <p x-text="preview" data-template-preview
               class="whitespace-pre-wrap rounded-(--radius-field) border border-(--color-border) bg-(--color-badge-success-bg) px-3 py-2.5 text-sm leading-relaxed text-(--color-ink)">{{ \App\Core\Services\PartyMessages::render((string) old('body_bn', $template->body_bn), $sample) }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="min-w-0 flex-1 text-xs text-(--color-ink-muted) tabular-nums" x-text="count" data-template-count></span>
            @if ($isWa && $template->exists && $metaSubmit)
                <x-ui.button type="submit" tone="secondary" form="meta-submit-{{ $template->id }}" data-meta-submit>{{ __('notification::party.meta_submit') }}</x-ui.button>
            @endif
            <x-ui.button tone="secondary" :href="$back">{{ __('notification::party.cancel') }}</x-ui.button>
            <x-ui.button type="submit" tone="primary">{{ __('notification::party.save') }}</x-ui.button>
        </div>
    </form>

    @if ($isWa && $template->exists && $metaSubmit)
        <form method="POST" id="meta-submit-{{ $template->id }}" action="{{ route('notification.party_templates.meta_submit', $template) }}" class="hidden">
            @csrf
        </form>
    @endif
</div>
