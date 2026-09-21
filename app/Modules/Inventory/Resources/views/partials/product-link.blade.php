{{-- পণ্যের কোড ও নাম, তার নিজের পাতায় ক্লিকযোগ্য — নিয়ম ১। --}}
<a href="{{ route('inventory.product.show', $product) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">
    {{ $product->code }} - {{ $product->name() }}
</a>

{{--
    ⭐ নিষ্ক্রিয় অথচ তালিকায় — কারণটা সারিতেই লেখা, ২১ সেপ্টেম্বর ২০২৬।

    ⓘ মজুদের তালিকা এখন নিষ্ক্রিয় পণ্যও দেখায় যদি তার গায়ে মাল থাকে
    ([[StockController::index]])। ⚠️ ওটা না বললে পাঠক ভাবতেন তালিকাটা
    ভেঙেছে — "এই পণ্যটা তো বন্ধ করে দিয়েছিলাম, ফিরে এল কেন"।

    ⛔ আর চিহ্নটা জরুরি উল্টো কারণেও: সংখ্যাটা সত্যি, কিন্তু পণ্যটা আর
    কেনা হচ্ছে না। ⓘ দুইটা তথ্য একসাথে না দিলে যেকোনো একটা ভুল
    সিদ্ধান্তে নিয়ে যায় — হয় মাল ভুলে যাওয়া, নয় বন্ধ পণ্য আবার অর্ডার
    করা।
--}}
@unless ($product->is_active)
    <span class="ms-1.5 inline-flex items-center rounded-(--radius-field) bg-(--color-surface-sunken)
                 px-1.5 py-0.5 text-2xs text-(--color-ink-muted)"
          title="{{ __('inventory::message.inactive_but_held') }}">
        {{ __('core.state.inactive') }}
    </span>
@endunless
