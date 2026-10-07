{{--
    কোম্পানির ঠিকানা-ফোন-BIN — নামের নিচের ছোট লাইনগুলো। চাই: $v; নকশা সাজায় `.co-meta`।
--}}
@if ($v->head['address'] !== '')<div class="co-meta" data-head-address>{{ $v->head['address'] }}</div>@endif
@php($contact = implode(' · ', array_filter([$v->head['phone'], $v->head['email'], $v->head['website']])))
@if ($contact !== '')<div class="co-meta">{{ $contact }}</div>@endif
@if ($v->taxIds !== '')<div class="co-meta" data-tax-ids>{{ $v->taxIds }}</div>@endif
