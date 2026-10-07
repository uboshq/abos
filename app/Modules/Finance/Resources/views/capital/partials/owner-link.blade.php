{{-- ⓘ মালিক ও বিনিয়োগকারী ট্যাবে নাম — লেনদেন ট্যাবে কেবল তাঁর সারিগুলো খোলে ([[capital/index]])। --}}
<a href="{{ route('finance.capital.index', ['person' => $position['person_id']]) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $position['name'] }}</a>
