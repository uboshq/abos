{{-- খোঁজার ফলে কোডটা খাতের পাতায় নিয়ে যায় --}}
<a href="{{ route('accounts.coa.show', $account) }}"
   class="num text-(--color-brand-500) underline-offset-2 hover:underline">
    {{ $account->code }}
</a>
