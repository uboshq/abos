{{-- খাতটা ক্লিকযোগ্য — নিয়ম ১ --}}
<a href="{{ route('accounts.coa.show', $line->account) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $line->account->label() }}</a>
