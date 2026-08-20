{{-- নম্বরটা ডায়াল করা যায়। রিকল হলে একশোজনকে ফোন করতে হয়, আর
     প্রতিটা নম্বর হাতে টাইপ করা মানে একশোটা ভুলের সুযোগ। --}}
@if ($row->customer_phone)
    <a href="tel:{{ $row->customer_phone }}" class="num hover:underline">{{ $row->customer_phone }}</a>
@endif
