{{--
    ⭐ একটা মাধ্যমের সাজানো — চালু/বন্ধ, প্রেরক, প্রোভাইডার, গোপন চাবি, সংযোগ পরীক্ষা (মালিকের স্পেক §৪, §৭; ধাপ ২)।

    ⛔ গোপন চাবি কখনো ফেরত দেখানো হয় না — ঘরটা সবসময় ফাঁকা, পাশে কেবল "বসানো আছে"। ফাঁকা পাঠালে আগেরটাই থাকে।
    ⓘ ইমেইল আর মোবাইল পুশের চাবি সার্ভারের `.env`-এ — এখানে কেবল চালু/বন্ধ আর প্রেরকের নাম।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::channel.names.'.$channel) }}</x-slot:title>

    <div class="mx-auto max-w-2xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ __('notification::channel.names.'.$channel) }}</h1>
            <a href="{{ route('notification.channels.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('notification::channel.back') }}</a>
        </div>

        @if (session('saved'))
            <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                    text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
        @endif
        @if (session('failed'))
            <p role="alert" class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                   text-sm text-(--color-badge-danger-ink)">{{ session('failed') }}</p>
        @endif

        <p data-connected="{{ $connected ? '1' : '0' }}" @class([
            'inline-block rounded-full px-2 py-0.5 text-2xs',
            'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $connected,
            'bg-(--color-surface-sunken) text-(--color-ink-muted)' => ! $connected,
        ])>{{ __('notification::channel.'.($connected ? 'connected' : 'not_connected')) }}</p>

        <p class="text-sm text-(--color-ink-muted)">
            @switch ($channel)
                @case ('email')
                    {{ __('notification::channel.email_note', ['from' => config('mail.from.address')]) }}
                    @if (in_array(config('mail.default'), ['log', 'array'], true))
                        <span class="block">{{ __('notification::channel.email_silent') }}</span>
                    @endif
                    @break
                @case ('web_push')
                    {{ __('notification::channel.web_push_note') }}
                    @break
                @case ('mobile_push')
                    {{ __('notification::channel.mobile_push_note') }}
                    @break
                @default
                    {{ __('notification::channel.sms_note') }}
            @endswitch
        </p>

        <form method="POST" action="{{ route('notification.channels.update', $channel) }}"
              class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            @method('PUT')

            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked($config?->enabled)>
                {{ __('notification::channel.enabled_label') }}
            </label>

            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::channel.sender') }}
                <input type="text" name="sender_id" maxlength="64" value="{{ old('sender_id', $config?->sender_id) }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                @error('sender_id') <span class="text-(--color-danger)">{{ $message }}</span> @enderror
            </label>

            @if ($channel === 'sms')
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::channel.provider') }}
                    <select name="provider" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                        <option value="">{{ __('notification::channel.sms_no_gateway') }}</option>
                        @foreach ($gateways as $gateway)
                            <option value="{{ $gateway }}" @selected($config?->provider === $gateway)>{{ $gateway }}</option>
                        @endforeach
                    </select>
                </label>

                @foreach (['api_key', 'api_secret'] as $secret)
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('notification::channel.'.$secret) }}
                        <input type="password" name="secrets[{{ $secret }}]" value="" autocomplete="new-password" data-secret="{{ $secret }}"
                               class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                        <span>{{ __('notification::channel.'.($hasSecret($secret) ? 'secret_saved' : 'secret_empty')) }}</span>
                    </label>
                @endforeach
            @endif

            @if (in_array($channel, ['email', 'sms'], true))
                {{-- ⭐ প্রোভাইডারের ফেরত-খবর (রসিদ, bounce) — এই ঠিকানায়, এই গোপন চাবির HMAC স্বাক্ষরে --}}
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::channel.webhook_secret') }}
                    <input type="password" name="secrets[webhook_secret]" value="" autocomplete="new-password" data-secret="webhook_secret"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <span>{{ __('notification::channel.'.($hasSecret('webhook_secret') ? 'secret_saved' : 'secret_empty')) }}</span>
                </label>
                <p class="text-2xs text-(--color-ink-muted)" data-callback-url>
                    {{ __('notification::channel.callback_note') }}
                    <code class="break-all">{{ route('api.notification-callbacks', ['company' => \App\Models\Company::query()->whereKey(\App\Core\Support\CompanyContext::id())->value('public_id'), 'channel' => $channel]) }}</code>
                </p>
            @endif

            <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::channel.save') }}</button>
        </form>

        @if ($channel === 'web_push')
            <form method="POST" action="{{ route('notification.channels.vapid') }}"
                  class="space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf
                @if ($hasSecret('vapid_private'))
                    <p class="text-sm">{{ __('notification::channel.vapid_ready') }}</p>
                    <button type="submit" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm">{{ __('notification::channel.vapid_again') }}</button>
                @else
                    <button type="submit" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm">{{ __('notification::channel.vapid') }}</button>
                @endif
            </form>
        @endif

        <form method="POST" action="{{ route('notification.channels.test', $channel) }}"
              class="space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            <p class="text-2xs text-(--color-ink-muted)">
                {{ __('notification::channel.last_check') }}:
                @if ($config?->last_checked_at)
                    {{ $config->last_checked_at->format('d/m/Y H:i') }} ·
                    {{ $config->last_check_ok ? __('notification::channel.test_ok') : __('notification::channel.test_failed', ['error' => $config->last_error]) }}
                @else
                    {{ __('notification::channel.never') }}
                @endif
            </p>
            <button type="submit" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm">{{ __('notification::channel.test') }}</button>
        </form>
    </div>
</x-layouts.app>
