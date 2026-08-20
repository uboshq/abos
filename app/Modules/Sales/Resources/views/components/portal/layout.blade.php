{{--
    পোর্টালের নিজের খোল।

    অ্যাপের শেলটা ব্যবহার করা হয়নি ইচ্ছাকৃতভাবে: ওখানে মেনু, মডিউল,
    কোম্পানি বাছাই, অনুমোদনের ঘণ্টা — ডিলারের কোনোটাই দরকার নেই, আর
    দেখালে তিনি ভাবতেন ভুল জায়গায় এসেছেন।

    তিনটা জিনিস: তাঁর নাম, বেরোনোর পথ, আর পাতাটা।
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('sales::portal.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-(--color-surface-app) text-(--color-ink)">
    <header class="border-b border-(--color-border) bg-(--color-surface-card)">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
            <div class="min-w-0">
                <p class="truncate font-semibold">{{ $dealer->name_bn ?: $dealer->name_en }}</p>
                <p class="text-2xs text-(--color-ink-muted)">{{ $dealer->code }}</p>
            </div>

            <form method="POST" action="{{ route('sales.portal.logout') }}">
                @csrf
                <button type="submit"
                        class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                               hover:bg-(--color-surface-hover)">
                    {{ __('sales::portal.sign_out') }}
                </button>
            </form>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-5">
        @if (session('status'))
            <div role="status"
                 class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                        text-(--color-badge-success-ink)">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert"
                 class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
