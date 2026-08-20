{{-- সম্পাদনার ঘরটা সারির ভেতরেই, কারণ পুরো ছকটা একটা ফর্ম: একবারে
     সবার টার্গেট বসিয়ে একবার সংরক্ষণ। প্রতি সারিতে আলাদা সংরক্ষণ
     হলে বিশজনের টার্গেট বসাতে বিশবার অপেক্ষা করতে হত। --}}
@if ($canManage)
    <input type="number" step="0.01" min="0" inputmode="decimal"
           name="amount[{{ $row['user']->id }}]"
           value="{{ $row['target'] !== null ? rtrim(rtrim($row['target'], '0'), '.') : '' }}"
           class="num h-(--spacing-field-compact) w-32 rounded-(--radius-field) border
                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
@else
    <span class="tabular">{{ $row['target'] === null ? '—' : \App\Core\Support\Money::format($row['target']) }}</span>
@endif
