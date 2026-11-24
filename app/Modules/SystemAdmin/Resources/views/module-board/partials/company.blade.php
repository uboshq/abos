{{-- কোম্পানি বাছা — বারের ভিতরে, নাম আর ছাঁকনির মাঝে; একটাই হলে কেবল সেটাই দেখায় --}}
<select name="company" data-company-picker data-action="submit-form" aria-label="{{ __('system_admin::module_board.companies') }}"
        class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
    @foreach ($companies as $one)
        <option value="{{ $one->id }}" @selected((int) $one->id === (int) $chosen)>{{ $one->name() }}</option>
    @endforeach
</select>
