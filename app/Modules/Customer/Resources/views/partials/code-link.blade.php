{{-- গ্রাহকের কোড, তার নিজের পাতায় ক্লিকযোগ্য — নিয়ম ১। --}}
<a href="{{ route('customer.show', $customer) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $customer->code }}</a>
