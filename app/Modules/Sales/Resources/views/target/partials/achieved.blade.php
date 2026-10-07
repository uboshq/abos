{{-- অঙ্কটাই লিংক — নিয়ম ১: সংখ্যা দেখে "এটা কোথা থেকে এল" জানতে চাইলে
     ওখানেই ক্লিক করার কথা। --}}
<a href="{{ route('sales.invoice.index', [
        'from' => $month->toDateString(),
        'to' => $month->copy()->endOfMonth()->toDateString(),
   ]) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">
    {{ \App\Core\Support\Money::format($row['achieved']) }}
</a>
