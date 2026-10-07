{{--
    থার্মাল ৩ · পরিষ্কার হাওয়া — বড় ফাঁক, সরু দাগ, বাঁয়ে লোগো — নতুন অ্যাপের রসিদের মতো।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'left', 'rule' => 'solid', 'items' => 'lines', 'total' => 'plain', 'hero' => false, 'size' => 8.5]])
