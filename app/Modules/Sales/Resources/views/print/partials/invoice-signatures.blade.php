{{--
    সইয়ের ঘর — কয়টা আর কী নাম "Set Invoice Information"-এ ([[InvoicePrintLook::signatures()]])।
    চাই: $v। নকশা সাজায়: `table.signatures`, `.sig-line`।

    ⚠️ দাগটা ঘরের নিজের (`td.sig-line`), div-এর নয় — mPDF ছকের ঘরের ভেতরের div-এর দাগ কেবল লেখার মাপে আঁকে,
    তাই সইয়ের দাগ "প্রস্তুতকারী"-র সমান ছোট হয়ে থাকত, সই করার জায়গা থাকত না (৩০ সেপ্টেম্বর ২০২৬)।
--}}
<table class="signatures">
    <tr>
        @foreach ($v->signatures as $label)
            <td style="width: {{ round(100 / max(1, count($v->signatures)), 1) }}%"><table style="width: 100%"><tr><td class="sig-line" style="text-align: center" data-signature>{{ $label }}</td></tr></table></td>
        @endforeach
    </tr>
</table>
