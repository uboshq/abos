{{-- ⓘ অবস্থার রং — ব্যবহারে সবুজ, সইয়ের অপেক্ষা আলাদা (গ১), অলস/মেরামতে সতর্ক, খাতার বাইরে ধূসর (স্থায়ী সম্পদ ধাপ ১) --}}
<x-ui.badge :tone="match ($asset->status) {
    \App\Modules\Accounts\Models\FixedAsset::ACTIVE => 'success',
    \App\Modules\Accounts\Models\FixedAsset::AWAITING => 'pending',
    \App\Modules\Accounts\Models\FixedAsset::IDLE, \App\Modules\Accounts\Models\FixedAsset::UNDER_REPAIR => 'info',
    default => 'draft',
}">
    {{ $asset->statusLabel() }}
</x-ui.badge>
