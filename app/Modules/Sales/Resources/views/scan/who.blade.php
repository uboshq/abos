{{--
    QR স্ক্যান, কেউ ঢোকা নেই — কোন লগইন তা বাছা ([[DeliveryScanController::open()]])।

    ⛔ এখানে চালানের কিছুই দেখানো হয় না — নম্বরও না। QR যার হাতেই পড়ুক, লগইন ছাড়া কাগজটা
    আছে কি না সেটাও জানা যায় না।
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sales::scan.who_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-(--color-surface-app) px-4 text-(--color-ink)">
    <main class="w-full max-w-sm">
        <img src="{{ asset('brand/abos-lockup.png') }}" alt="ABOS" class="mx-auto mb-6 h-12 w-auto">

        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-5">
            <h1 class="mb-2 text-lg font-semibold">{{ __('sales::scan.who_title') }}</h1>
            <p class="mb-5 text-sm text-(--color-ink-muted)">{{ __('sales::scan.who_note') }}</p>

            <div class="space-y-3">
                <a href="{{ route('login') }}" data-staff-login
                   class="flex min-h-(--spacing-touch) items-center justify-center rounded-(--radius-field)
                          bg-(--color-brand-600) px-4 font-medium text-(--color-brand-ink)">
                    {{ __('sales::scan.staff_login') }}
                </a>
                <a href="{{ route('sales.portal.login') }}" data-dealer-login
                   class="flex min-h-(--spacing-touch) items-center justify-center rounded-(--radius-field)
                          border border-(--color-border) px-4 font-medium">
                    {{ __('sales::scan.dealer_login') }}
                </a>
            </div>
        </div>
    </main>
</body>
</html>
