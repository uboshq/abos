{{--
    নতুন রান — শুধু মাসটা বেছে নেওয়া।

    কর্মীর তালিকা এখানে বাছা হয় না ইচ্ছাকৃতভাবে: ওই মাসে যারা কর্মরত
    ছিলেন তারা সবাই আসবেন। হাতে বাছতে দিলে একজন বাদ পড়ার সম্ভাবনা
    থাকত, আর বাদ পড়া মানুষটা মাসের শেষে টের পেতেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::action.new_run') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::action.new_run')" />
    </x-slot:header>

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

    <form method="POST" action="{{ route('hr.payroll.store') }}"
          class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        @csrf

        <div class="grid gap-3 sm:grid-cols-[14rem_14rem_auto] sm:items-end">
            <label class="block">
                <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                             text-(--color-ink-muted)">{{ __('hr::field.month') }}</span>
                <x-ui.month name="month" required :value="old('month', $month)"
                            class="w-full rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2 py-1.5 text-sm" />
            </label>

            {{-- খরচের তারিখ আলাদা: বেতন জুনের, কিন্তু খরচটা কোন দিনে বসবে
                 তা প্রতিষ্ঠান ঠিক করে — সাধারণত মাসের শেষ দিন। --}}
            <label class="block">
                <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                             text-(--color-ink-muted)">{{ __('hr::field.trx_date') }}</span>
                <x-ui.date name="trx_date"
                           value="{{ old('trx_date') }}"
                           class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm" />
                           </label>

            <x-ui.button type="submit" tone="primary">{{ __('hr::action.build') }}</x-ui.button>
        </div>
    </form>

    {{--
        ⭐ উৎসব ভাতার রান — আলাদা ধরন (শ্রম বিধিমালা ২০১৫ বিধি ১১১; a6, ২১ অক্টোবর ২০২৬)। দিনটা বাছা হয়, মাস নয়:
        সেদিন যাঁদের চাকরি এক বছর, তাঁরাই পান; বছরে বড়জোর দুইটা।
    --}}
    <form method="POST" action="{{ route('hr.payroll.store') }}" data-bonus-form
          class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        @csrf
        <input type="hidden" name="run_type" value="{{ \App\Modules\Hr\Models\PayrollRun::BONUS }}">
        <h2 class="mb-1 font-semibold">{{ __('hr::action.new_bonus_run') }}@if (\App\Core\Support\AbosHead::on())<x-ui.page-info :text="__('hr::message.bonus_note')" />@endif</h2>
@unless (\App\Core\Support\AbosHead::on())
        <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('hr::message.bonus_note') }}</p>
@endunless

        <div class="grid gap-3 sm:grid-cols-[14rem_14rem_auto] sm:items-end">
            <label class="block">
                <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                             text-(--color-ink-muted)">{{ __('hr::field.bonus_date') }}</span>
                <x-ui.date name="bonus_date" required
                           value="{{ old('bonus_date', now()->toDateString()) }}"
                           class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm" />
            </label>

            <label class="block">
                <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                             text-(--color-ink-muted)">{{ __('hr::field.narration') }}</span>
                <input type="text" name="narration" maxlength="200" value="{{ old('narration') }}"
                       class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm">
            </label>

            <x-ui.button type="submit" tone="primary">{{ __('hr::action.build') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
