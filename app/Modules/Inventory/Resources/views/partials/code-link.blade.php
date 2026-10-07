{{-- পণ্যের কোড, তার নিজের পাতায় ক্লিকযোগ্য — নিয়ম ১। --}}
<a href="{{ route('inventory.product.show', $product) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $product->code }}</a>
