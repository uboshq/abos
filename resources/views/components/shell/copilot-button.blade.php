{{--
    ⭐ কোপাইলটের বোতাম — উপরের বারে মগজের ছবি, তার নিচে ছোট করে নাম (মালিক, ১০ নভেম্বর ২০২৬: "মগজের ছবি, নিচে ছোট Copilot";
    পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2 অংশ ১)। নামটা কন্ট্রোল প্যানেল থেকে ([[AssistantEngine::name()]])।
    ⓘ কেবল যাঁর হাতে `assistant.use`; চাপলে কোপাইলটের পাতা — পাশের প্রশ্নের প্যানেল ধাপ ৪-এ।
    ⓘ ৬৪০px-এর নিচে লুকায় (hidden sm:flex) — ৩৭৫px-এ প্রোফাইলের বোতাম পর্দার ভেতরে থাকে; ফোনে কোপাইলট মেনুতে আর অ্যাপে।
--}}
@auth
    @can('assistant.use')
        @php $copilot = app(\App\Core\Engines\Assistant\AssistantEngine::class)->name(); @endphp
        <a href="{{ route('assistant.today') }}" data-copilot-button
           class="hidden h-(--spacing-touch) min-w-(--spacing-touch) flex-col sm:flex items-center justify-center rounded-(--radius-field) px-1 text-(--color-topbar-ink-muted) transition-colors hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink)"
           title="{{ $copilot }}" aria-label="{{ $copilot }}">
            <span class="grid place-items-center" data-topbar-icon="brain" style="color: var(--color-topbar-glyph-brain)"><x-ui.icon name="brain" :size="20" /></span>
            <span class="text-[0.625rem] leading-none font-semibold" style="color: var(--color-topbar-glyph-brain)">{{ $copilot }}</span>
        </a>
    @endcan
@endauth
