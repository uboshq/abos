{{--
    Accounts-এর সেটিংস।

    সুইচগুলো এখানে হাতে লেখা নেই — module.php যা ঘোষণা করে, এই পর্দা
    তা-ই দেখায় (নিয়ম ৭)। নতুন একটা ঐচ্ছিক ফিল্ড যোগ করার সময় তার
    সুইচটা একই ফাইলে লেখা হয়, আর এই পর্দায় সেটা নিজে থেকেই আসে।

    সেটিংস এক জায়গায়, স্ক্রিনের ভেতরে ছড়িয়ে নয়: ভাউচারের পর্দায়
    "পিছনের তারিখ কত দিন" বসালে সেটা রোজ চোখে পড়ত অথচ বছরে একবার
    বদলাত, আর কে বদলাল তা জানার উপায় থাকত না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.settings') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.settings')"
                          :subtitle="__('accounts::message.settings_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <form method="POST" action="{{ route('accounts.settings.update') }}" class="max-w-3xl space-y-4">
        @csrf
        @method('PUT')

        @foreach ($groups as $group => $settings)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('accounts::settings_group.' . $group) }}</h2>

                <div class="space-y-3">
                    @foreach ($settings as $setting)
                        @if ($setting['type'] === 'boolean')
                            <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                                <input type="checkbox" name="settings[{{ $setting['key'] }}]" value="1"
                                       @checked($setting['value']) class="mt-1 size-4">
                                <span>{{ __($setting['label']) }}</span>
                            </label>
                        @else
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium">{{ __($setting['label']) }}</span>
                                <input type="{{ $setting['type'] === 'integer' ? 'number' : 'text' }}"
                                       name="settings[{{ $setting['key'] }}]"
                                       value="{{ $setting['value'] }}"
                                       @if ($setting['type'] === 'integer') min="0" inputmode="numeric" @endif
                                       class="h-(--spacing-field) w-full max-w-40 rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3
                                              @if ($setting['type'] === 'integer') num text-end @endif">
                            </label>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach

        <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
    </form>
</x-layouts.app>
