{{--
    নিজের প্রোফাইল — নাম ও ছবি।

    তিনটা ফর্ম, একটা নয়: নাম বদলানো, ছবি বসানো আর ছবি মোছা তিনটা আলাদা
    কাজ। একটা ফর্মে জুড়লে নাম ঠিক করতে গিয়ে ছবি না দিলে ছবিটা মুছে যেত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.profile.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.profile.title')"
                          :subtitle="__('core.profile.subtitle')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ __('core.profile.saved') }}
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

    <div class="max-w-2xl space-y-4">

        {{-- ছবি --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.profile.photo') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('core.profile.photo_note', ['mb' => $maxMb]) }}
            </p>

            <div class="flex flex-wrap items-center gap-4">
                <x-ui.avatar :user="$user" size="lg" class="ring-1 ring-(--color-border)" />

                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('profile.avatar') }}"
                          enctype="multipart/form-data"
                          x-data="{ busy: false }"
                          @submit="busy ? $event.preventDefault() : (busy = true)">
                        @csrf

                        {{-- ফাইল ইনপুটটা লেবেলের ভেতরে: ব্রাউজারের নিজের
                             "Choose file" বোতামটা আমাদের বোতামের মতো
                             দেখানো যায় না, আর দুটো আলাদা ভাষা মেনে চলে।
                             নির্বাচন হলেই সাবমিট — "বেছে নিন" তারপর
                             "সেভ করুন" দুই ধাপ অকারণ। --}}
                        <label class="inline-flex min-h-(--spacing-touch) cursor-pointer items-center gap-2
                                      rounded-(--radius-field) bg-(--color-brand-700) px-4 text-sm font-medium
                                      text-(--color-ink-inverse) transition-opacity hover:opacity-90"
                               :class="busy && 'pointer-events-none opacity-70'">
                            <input type="file" name="avatar" class="sr-only"
                                   accept="image/jpeg,image/png,image/webp"
                                   onchange="this.form.requestSubmit()">
                            {{ $user->avatarUrl()
                                ? __('core.profile.change_photo')
                                : __('core.profile.upload_photo') }}
                        </label>
                    </form>

                    @if ($user->avatarUrl())
                        <form method="POST" action="{{ route('profile.avatar.remove') }}"
                              onsubmit="return confirm('{{ __('core.profile.remove_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('core.profile.remove_photo') }}
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        {{-- নাম --}}
        <form method="POST" action="{{ route('profile.update') }}">
            @csrf
            @method('PUT')

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('core.profile.identity') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.name') }}</span>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-3">
                    </label>

                    {{-- ইমেইল দেখানো হয়, বদলানো যায় না: এটা লগইনের পরিচয়,
                         আর নিজে থেকে বদলাতে দিলে যাচাই ছাড়া অ্যাকাউন্ট
                         অন্য ঠিকানায় সরে যেত। প্রশাসক বদলাবে। --}}
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">{{ __('core.profile.email') }}</span>
                        <input type="email" value="{{ $user->email }}" disabled
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-app) px-3
                                      text-(--color-ink-muted)">
                    </label>
                </div>

                <div class="mt-3">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                </div>
            </section>
        </form>
    </div>
</x-layouts.app>
