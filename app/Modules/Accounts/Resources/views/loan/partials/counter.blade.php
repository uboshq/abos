{{-- বিপরীত খাতটা ক্লিকযোগ্য — নিয়ম ১: টাকাটা কোথা থেকে এল বা কোথায়
     গেল, সেটা এক ক্লিকে দেখা যায়। --}}
@if ($movement->counterAccount)
    <a href="{{ route('accounts.coa.show', $movement->counterAccount) }}"
       class="text-(--color-brand-500) underline-offset-2 hover:underline">
        {{ $movement->counterAccount->label() }}
    </a>
@else
    —
@endif
