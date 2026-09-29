{{--
    থার্মাল ১ · বড় অঙ্ক আগে — এখনকার ডিজিটাল রসিদের ধাঁচ: মাথার নিচেই বড় অঙ্কে কত দিতে হবে।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'center', 'rule' => 'solid', 'total' => 'plain', 'hero' => true, 'items' => 'lines']])
