{{-- একটা রাখা খসড়ার দুই কাজ — খোলা আর কারণসহ বাতিল ([[direct/drafts]])।
     ⚠️ বাতিলের ফর্ম সারির নিজের, আলাদা — তালিকার খোঁজার ফর্মের ভিতরে নয়। --}}
<div class="flex flex-wrap items-center justify-end gap-2">
    <a href="{{ route('sales.direct.create', ['draft' => $draft->id]) }}"
       class="rounded-(--radius-field) bg-(--color-brand-600) px-3 py-1 text-xs font-semibold text-white
              hover:bg-(--color-brand-700)">
        {{ __('sales::action.open_draft') }}
    </a>

    <form method="POST" action="{{ route('sales.direct.discard', $draft->id) }}" class="flex items-center gap-1">
        @csrf
        <input type="hidden" name="back" value="drafts">
        <input type="text" name="reason" required maxlength="500"
               aria-label="{{ __('sales::message.cancel_reason') }}"
               placeholder="{{ __('sales::message.cancel_reason') }}"
               class="h-(--spacing-field-dense) w-40 rounded-(--radius-field) border border-(--color-border)
                      bg-(--color-surface-app) px-2 text-xs">
        <button type="submit"
                class="rounded-(--radius-field) bg-(--color-danger) px-3 py-1 text-xs font-semibold text-white
                       hover:bg-(--color-danger-hover)">
            {{ __('core.action.cancel') }}
        </button>
    </form>
</div>
