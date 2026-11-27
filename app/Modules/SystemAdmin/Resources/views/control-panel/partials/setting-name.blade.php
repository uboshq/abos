{{-- নাম, ব্যাখ্যা আর স্তরের চিহ্ন --}}
<span class="whitespace-normal">{{ $setting['text'] }}</span>
@if (__($setting['label'].'_hint') !== $setting['label'].'_hint')
    <span data-setting-hint tabindex="0" class="ms-1 inline-block cursor-help align-middle text-(--color-ink-muted)" title="{{ __($setting['label'].'_hint') }}" aria-label="{{ __($setting['label'].'_hint') }}" role="img"><x-ui.icon name="info" :size="15" /></span>
@endif
@foreach (['per_branch' => 'branch', 'signed' => 'signed', 'product' => 'product'] as $flag => $name)
    @if ($setting[$flag])
        <em data-level="{{ $name }}" class="me-1 inline-block rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-0.5 text-2xs not-italic text-(--color-badge-draft-ink)">{{ __('system_admin::control.level_'.$name) }}</em>
    @endif
@endforeach
