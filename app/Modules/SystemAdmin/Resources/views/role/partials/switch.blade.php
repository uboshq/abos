{{--
    অনুমতির একটা ঘর — চেকবক্স (মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬: সবুজ ✓ ঘর, আগের টগল নয়)।

    ⓘ জমা দিলে `permissions[]`-এ পুরো নামটাই যায়, ঠিক আগের মতো। ⚠️ লেখাটা (`$label`) পর্দা-পাঠকের জন্য, আর সংরক্ষণের আগের
      সারাংশেও এটাই ("কোন ঘর বদলেছে") — `data-cell-label`।
    ℹ `$cell` — কোন কলামের ঘর, যাতে কলামের মাথার "সব" টিকটা ওকে খুঁজে পায়। ⚠️ না দিলে ঘরটা কেবল মডিউলের টিকে পড়ে।
    ⓘ `$readonly` — "কে আসলে কী পারে": নাম ছাড়া, বন্ধ — কিছু জমা হয় না।
--}}
<label class="role-cell" title="{{ $name }}">
    @if (! empty($readonly))
        <span @class(['role-mark', 'is-on' => $checked]) aria-hidden="true">{{ $checked ? '✓' : '' }}</span>
    @else
        <input type="checkbox" name="permissions[]" value="{{ $name }}" class="role-ck"
               @isset($cell) data-permission-cell="{{ $cell }}" @endisset
               data-cell-label="{{ $label }}"
               @checked($checked)>
    @endif
    <span class="sr-only">{{ $label }}@if (! empty($readonly)) — {{ $checked ? __('system_admin::permission.state_granted') : __('system_admin::permission.state_denied') }}@endif</span>
    @isset($caption)
        <span class="role-caption" aria-hidden="true">{{ $caption }}</span>
    @endisset
</label>
