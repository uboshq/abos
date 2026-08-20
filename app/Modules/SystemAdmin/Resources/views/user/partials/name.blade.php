{{-- নিষ্ক্রিয় হলে নামের পাশেই বলা হয় — আলাদা কলাম নিলে চোখ দুইবার
     ঘুরত, আর ওই তথ্যটা নামের সাথেই পড়া দরকার। --}}
{{ $user->name }}
@unless ($user->is_active)
    <span class="ms-1 rounded-full bg-(--color-badge-warning-bg) px-2 py-0.5
                 text-2xs text-(--color-badge-warning-ink)">{{ __('core.state.inactive') }}</span>
@endunless
