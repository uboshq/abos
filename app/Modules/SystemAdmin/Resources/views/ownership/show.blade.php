{{--
    মালিকানা হস্তান্তর।

    ── কেন পাতাটা এত কথা বলে ───────────────────────────────────────────
    এখানকার একটা ক্লিক পুরো প্রতিষ্ঠানটা অন্য একজনের হাতে তুলে দেয়, আর
    কাজটা ফেরত নেওয়ার উপায় নেই — নতুন মালিকই কেবল আবার হস্তান্তর করতে
    পারেন। ⛔ যে সিদ্ধান্ত ফেরানো যায় না, সেটা বোঝা যাচ্ছে কি না তা
    অনুমান করা চলে না; লিখে দিতে হয়।

    ⓘ আর একটা কথা ইচ্ছাকৃতভাবে এখানে: যিনি কেবল টাকা দেন তাঁর অ্যাকাউন্ট
    লাগে না। প্রশ্নটা বারবার ওঠে ("বিনিয়োগকারীকে কী দেব?"), আর উত্তরটা
    ঠিক এই পাতাতেই দরকার হয় — নইলে কেউ তাঁকেই মালিক বানিয়ে বসতেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::menu.ownership') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::menu.ownership')"
                          :subtitle="__('system_admin::message.ownership_intro')" />
    </x-slot:header>

    <div class="max-w-xl space-y-4">

        <div class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
            <p class="text-sm">
                {{ __('system_admin::message.ownership_weight') }}
            </p>

            <p class="mt-2 text-2xs text-(--color-ink-muted)">
                {{ __('system_admin::message.ownership_not_an_investor') }}
            </p>

            @if ($currentOwner)
                <p class="mt-3 text-sm">
                    <span class="text-(--color-ink-muted)">{{ __('system_admin::field.current_owner') }}:</span>
                    {{ $currentOwner->name }}
                    <span class="text-(--color-ink-muted)">({{ $currentOwner->email }})</span>
                </p>
            @endif
        </div>

        @if ($candidates->isEmpty())
            {{-- কেউ না থাকলে ফর্মটা দেখানোর কোনো মানে নেই — একটা খালি
                 তালিকা আর একটা নিষ্ক্রিয় বোতাম কেবল বিভ্রান্ত করত --}}
            <div class="rounded-(--radius-card) border border-(--color-border) p-4">
                <p class="text-sm text-(--color-ink-muted)">
                    {{ __('system_admin::message.ownership_no_candidates') }}
                </p>
            </div>
        @else
            <form method="POST" action="{{ route('system_admin.ownership.update') }}"
                  class="rounded-(--radius-card) border border-(--color-danger)
                         bg-(--color-badge-danger-bg) p-4 space-y-3">
                @csrf
                @method('PUT')

                <label for="user_id" class="block text-sm text-(--color-badge-danger-ink)">
                    {{ __('system_admin::field.new_owner') }}
                </label>

                <select id="user_id" name="user_id" required
                        class="h-(--spacing-field) w-full rounded-(--radius-field)
                               border border-(--color-border) bg-(--color-surface-card) px-3">
                    <option value="">—</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}" @selected(old('user_id') == $candidate->id)>
                            {{ $candidate->name }} ({{ $candidate->email }})
                        </option>
                    @endforeach
                </select>

                @error('user_id')
                    <p class="text-xs text-(--color-danger)">{{ $message }}</p>
                @enderror

                {{-- পাসওয়ার্ড — খোলা রেখে যাওয়া একটা স্ক্রিনই নইলে যথেষ্ট
                     হত পুরো প্রতিষ্ঠানটা হাতবদল করতে --}}
                <label for="password" class="block text-sm text-(--color-badge-danger-ink)">
                    {{ __('auth.confirm_with_password') }}
                </label>

                <input id="password" name="password" type="password" autocomplete="current-password" required
                       class="h-(--spacing-field) w-full rounded-(--radius-field)
                              border border-(--color-border) bg-(--color-surface-card) px-3">

                @error('password')
                    <p class="text-xs text-(--color-danger)">{{ $message }}</p>
                @enderror

                @error('roles')
                    <p class="text-xs text-(--color-danger)">{{ $message }}</p>
                @enderror

                <button type="submit"
                        class="min-h-(--spacing-touch) rounded-(--radius-field) border
                               border-(--color-danger) px-4 text-sm text-(--color-danger)">
                    {{ __('system_admin::field.transfer_now') }}
                </button>
            </form>
        @endif
    </div>
</x-layouts.app>
