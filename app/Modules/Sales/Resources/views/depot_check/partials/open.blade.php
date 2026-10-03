{{-- ⭐ "যাচাই করে বিক্রয়ে খুলুন" — POST, কারণ খোলা মানে উৎস ডিপো যাচাইয়ে ওঠে; GET কিছু বদলায় না ([[DepotCheckController::open()]]) --}}
<form method="POST" action="{{ route('sales.direct.depot_check.open') }}">
    @csrf
    <input type="hidden" name="source" value="do">
    <input type="hidden" name="source_id" value="{{ $order->id }}">
    <button type="submit"
            class="inline-block rounded-(--radius-field) bg-(--color-brand-600) px-3 py-1 text-xs font-semibold
                   text-white hover:bg-(--color-brand-700)">
        {{ __('sales::counter_source.open') }}
    </button>
</form>
