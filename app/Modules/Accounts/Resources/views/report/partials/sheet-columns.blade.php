{{--
    স্থিতিপত্রের এক পক্ষের কলামের মাথা — এই দিনের অঙ্ক, আর পাশে আগের অর্থবছরের শেষ দিনের অঙ্ক (IAS 1.38: প্রতিটা অঙ্কের
    আগের সময়ের অঙ্ক; ERP ১০০%-এর হিসাবের ফাঁক #৭, ২০ অক্টোবর ২০২৬)। তারিখ দুটো লেখা, যাতে কোন কলাম কোন দিনের তা অনুমান করতে না হয়।
--}}
<div class="flex items-baseline justify-end gap-3 border-b border-(--color-border) px-4 py-1.5 text-xs text-(--color-ink-muted)">
    <span class="w-32 text-end">{{ \App\Core\Support\DateFormat::format($sheet['as_of']) }}</span>
    <span class="w-32 text-end">{{ \App\Core\Support\DateFormat::format($sheet['prior']['as_of']) }}</span>
</div>
