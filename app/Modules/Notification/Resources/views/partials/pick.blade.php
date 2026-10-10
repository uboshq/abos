{{--
    ⓘ কয়েকটা বাছাই — মানুষ, রোল, শাখা, বিভাগ, দল। সাধারণ `<select multiple>`: জাভাস্ক্রিপ্ট ছাড়াই চলে, CSP-তে কিছু লাগে না।
    $name — ফর্মের নাম (`recipients[users]`), $options — আইডি => নাম, $chosen — বাছা আইডি
--}}
<label class="grid gap-1 text-2xs text-(--color-ink-muted)">
    {{ $label }}
    <select name="{{ $name }}[]" multiple size="5"
            class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-sm">
        @foreach ($options as $id => $text)
            <option value="{{ $id }}" @selected(in_array((int) $id, array_map('intval', (array) $chosen), true))>{{ $text }}</option>
        @endforeach
    </select>
</label>
