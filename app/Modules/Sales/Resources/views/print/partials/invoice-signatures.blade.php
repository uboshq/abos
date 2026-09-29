{{--
    সইয়ের ঘর — কয়টা আর কী নাম "Set Invoice Information"-এ ([[InvoicePrintLook::signatures()]])।
    চাই: $v। নকশা সাজায়: `table.signatures`, `.sig-line`।
--}}
<table class="signatures">
    <tr>
        @foreach ($v->signatures as $label)
            <td style="width: {{ round(100 / max(1, count($v->signatures)), 1) }}%"><div class="sig-line" data-signature>{{ $label }}</div></td>
        @endforeach
    </tr>
</table>
