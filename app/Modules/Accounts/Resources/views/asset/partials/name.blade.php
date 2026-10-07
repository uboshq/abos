{{-- নামটাই সম্পদের পাতায় যাওয়ার লিংক — নিয়ম ১। --}}
<a href="{{ route('accounts.asset.show', $asset) }}"
   class="text-(--color-brand-500) hover:underline">{{ $asset->name }}</a>
