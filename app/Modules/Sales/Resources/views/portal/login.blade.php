{{--
    ডিলারের লগইন।

    কোড দিয়ে, ইমেইল দিয়ে নয়: ডিলারের ইমেইল প্রায়ই থাকে না, আর থাকলেও
    ভাগাভাগি করা। কোডটা বিলের উপরে ছাপা, তাই তাঁর হাতেই থাকে।
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sales::portal.login_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-(--color-surface-app) px-4 text-(--color-ink)">
    <div class="w-full max-w-sm">
        <img src="{{ asset('brand/adi-abos-lockup.png') }}" alt="ADI | ABOS"
             class="mx-auto mb-6 h-12 w-auto">

        <form method="POST" action="{{ route('sales.portal.login.attempt') }}"
              class="rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-5">
            @csrf

            <h1 class="mb-4 text-lg font-semibold">{{ __('sales::portal.login_title') }}</h1>

            @if ($errors->any())
                <div role="alert"
                     class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                            text-(--color-badge-danger-ink)">
                    {{ $errors->first() }}
                </div>
            @endif

            <label class="mb-3 block">
                <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.code') }}</span>
                <input type="text" name="code" required autofocus value="{{ old('code') }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-app) px-3">
                <span class="mt-1 block text-2xs text-(--color-ink-muted)">
                    {{ __('sales::portal.code_hint') }}
                </span>
            </label>

            <label class="mb-4 block">
                <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.password') }}</span>
                <input type="password" name="password" required
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-app) px-3">
            </label>

            <button type="submit"
                    class="h-(--spacing-field) w-full rounded-(--radius-field)
                           bg-(--color-brand-500) font-medium text-white">
                {{ __('sales::portal.sign_in') }}
            </button>
        </form>
    </div>
</body>
</html>
