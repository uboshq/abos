{{-- নাম, আর ছেড়ে গিয়ে থাকলে সেটাও — নাহলে তালিকায় দুইজন একরকম দেখাত --}}
<span>{{ $employee->name() }}</span>

@if ($employee->leaving_date !== null)
    <span class="ms-2 rounded-(--radius-field) bg-(--color-surface-hover) px-1.5 py-0.5 text-2xs
                 text-(--color-ink-muted)">
        {{ __('hr::message.left_on', ['date' => $employee->leaving_date->format('d M Y')]) }}
    </span>
@endif
