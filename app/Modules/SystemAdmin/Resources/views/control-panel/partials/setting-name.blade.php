{{-- নাম, ব্যাখ্যা আর স্তরের চিহ্ন --}}
<span>{{ $setting['text'] }}</span>
@if (__($setting['label'].'_hint') !== $setting['label'].'_hint')
    <span data-setting-hint class="mt-0.5 block text-xs font-normal text-(--color-ink-muted)">{{ __($setting['label'].'_hint') }}</span>
@endif
@foreach (['per_branch' => 'branch', 'signed' => 'signed', 'product' => 'product'] as $flag => $name)
    @if ($setting[$flag])
        <em data-level="{{ $name }}" class="me-1 inline-block rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-0.5 text-2xs not-italic text-(--color-badge-draft-ink)">{{ __('system_admin::control.level_'.$name) }}</em>
    @endif
@endforeach
