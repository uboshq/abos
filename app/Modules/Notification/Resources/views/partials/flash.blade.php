{{-- ⓘ সংরক্ষণ বা ভুলের ছোট বার্তা — ধাপ ৩-এর সব ফর্মে একই --}}
@if (session('saved'))
    <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                            text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
@endif
@if (session('failed'))
    <p role="alert" class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                           text-sm text-(--color-badge-danger-ink)">{{ session('failed') }}</p>
@endif
@if ($errors->any())
    <ul role="alert" class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
        @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
@endif
