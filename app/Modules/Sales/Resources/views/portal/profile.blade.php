{{--
    ⭐ গ্রাহকের প্রোফাইল — নিজের তথ্য, আর পাসওয়ার্ড বদল (গ্রাহক পোর্টাল, ধাপ ১; [[PortalProfileController]])।

    ⛔ মূল রেকর্ড এখানে বদলায় না — মালিকের নিয়ম। তথ্য কেবল দেখা।
--}}
<x-sales::portal.layout :customer="$customer" :title="__('sales::portal_profile.title')">
    <h1 class="mb-3 text-lg font-semibold">{{ __('sales::portal_profile.title') }}</h1>

    <section data-boxed data-portal-profile-contact
             class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 text-sm font-semibold">
            {{ __('sales::portal_profile.contact') }}
        </h2>
        <dl class="grid gap-3 p-4 text-sm sm:grid-cols-2">
            @foreach ([
                'code' => $customer->code,
                'name' => $customer->name(),
                'owner_name' => $customer->owner_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address_bn ?: $customer->address_en,
            ] as $field => $value)
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_profile.'.$field) }}</dt>
                    <dd class="font-medium">{{ filled($value) ? $value : '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section data-boxed id="password"
             class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 text-sm font-semibold">
            {{ __('sales::portal_profile.password_title') }}
        </h2>
        <form method="POST" action="{{ route('sales.portal.profile.password') }}" class="grid gap-3 p-4" data-portal-password>
            @csrf
            @foreach (['current_password' => 'current-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'] as $field => $autocomplete)
                <label class="block">
                    <span class="mb-1 block text-sm">{{ __('sales::portal_profile.'.$field) }}</span>
                    <input type="password" name="{{ $field }}" required autocomplete="{{ $autocomplete }}"
                           class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3">
                </label>
            @endforeach
            <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_profile.password_hint') }}</p>
            <button type="submit"
                    class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-4 text-sm font-medium text-white">
                {{ __('sales::portal_profile.change_password') }}
            </button>
        </form>
    </section>

    <a href="{{ route('sales.portal.home') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::portal.title') }}
    </a>
</x-sales::portal.layout>
