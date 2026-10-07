{{--
    বিলের QR — কর্মী স্ক্যান করে ডেলিভারির পরের ধাপ দেন, ডিলার নিজের হিসাব-বিল দেখে মাল পাওয়া
    নিশ্চিত করেন ([[DeliveryScanController]])। চাই: $v ([[InvoicePaperView]]); ঠিকানা না থাকলে কিছুই না।
--}}
@if ($v->qr !== '')
    <div data-scan-qr style="text-align: center">
        {{-- ⚠️ mPDF-এর <barcode type="QR"> নয়: ওটা `mpdf/qrcode` প্যাকেজ চায়, আর লাইভের ডিপ্লয় composer
             চালায় না — প্যাকেজটা লাইভে পৌঁছাতই না, আর বিলের পাতা ৫০০ দিত। ⭐ ঘরে বানানো [[QrCode]] (দুই ধাপের
             লগইনের সেই একই), SVG ছবি হয়ে। --}}
        <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Core\Support\QrCode::svg($v->qr, scale: 4, quiet: 2)) }}"
             style="width: {{ $width ?? '22mm' }}; height: {{ $width ?? '22mm' }};" alt="">
        @if ($v->scanHint() !== '')
            <div style="font-family: hindsiliguri, sans-serif; font-size: 7pt">{{ $v->scanHint() }}</div>
        @endif
    </div>
@endif
