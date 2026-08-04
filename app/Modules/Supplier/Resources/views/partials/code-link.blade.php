{{-- সরবরাহকারীর কোড, তার নিজের পাতায় ক্লিকযোগ্য — নিয়ম ১। --}}
<a href="{{ route('supplier.show', $supplier) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $supplier->code }}</a>
