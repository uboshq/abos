import '../records/money.dart';
import '../api_client/api_client.dart';
import '../locale/app_locale.dart';

/// ⭐ চলতি অফার, ফোনে — SR দোকানে দাঁড়িয়ে দেখেন কোন পণ্যে কী ছাড়, কী ফ্রি, কবে পর্যন্ত (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬;
/// সার্ভার `PromotionApiController`)।
///
/// <p>কোনটা "চলতি", কোন শাখার, কোন দোকানের — সব সার্ভারের বিক্রির ইঞ্জিনের একই প্রশ্ন। নিয়মটা কাঠামো হয়ে আসে, আর বাংলা
/// বাক্য এখানে বানানো হয় ([[OfferBenefit.words]], [[OfferStep.words]])।

String _s(dynamic v) => v?.toString() ?? '';
String? _sn(dynamic v) => v == null || v.toString().isEmpty ? null : v.toString();
List<String> _list(dynamic v) => [if (v is List) for (final e in v) e.toString()];
List<Map<String, dynamic>> _maps(dynamic v) => [
      if (v is List)
        for (final e in v)
          if (e is Map) Map<String, dynamic>.from(e),
    ];

class OfferBenefit {
  const OfferBenefit({required this.kind, this.amount, this.gift, this.cap});

  /// percent · amount · goods · credit · points
  final String kind;
  final String? amount;
  final String? gift;
  final String? cap;

  factory OfferBenefit.fromJson(Map<String, dynamic> j) =>
      OfferBenefit(kind: _s(j['kind']), amount: _sn(j['amount']), gift: _sn(j['gift']), cap: _sn(j['cap']));

  /// "১০% ছাড়" নয় — "10% ছাড়": অঙ্ক যেমন সার্ভারে, কথা বাংলায়
  String get words {
    final a = amount ?? '';
    final w = AppLocale.l10n;
    final base = switch (kind) {
      'percent' => w.offerBenefitPercent(a),
      'amount' => w.offerBenefitAmount(Money.taka(a)),
      'goods' => gift == null ? w.offerBenefitFree(a) : w.offerBenefitFreeGift(a, gift!),
      'credit' => w.offerBenefitCredit(Money.taka(a)),
      'points' => w.offerBenefitPoints(a),
      _ => a,
    };
    return cap == null ? base : w.offerBenefitCap(base, Money.taka(cap));
  }
}

class OfferStep {
  const OfferStep({
    required this.kind,
    this.from,
    this.to,
    this.product,
    this.fromTime,
    this.toTime,
    this.benefits = const [],
  });

  /// quantity · value · product · time
  final String kind;
  final String? from;
  final String? to;
  final String? product;
  final String? fromTime;
  final String? toTime;
  final List<OfferBenefit> benefits;

  factory OfferStep.fromJson(Map<String, dynamic> j) => OfferStep(
        kind: _s(j['kind']),
        from: _sn(j['from']),
        to: _sn(j['to']),
        product: _sn(j['product']),
        fromTime: _sn(j['from_time']),
        toTime: _sn(j['to_time']),
        benefits: [for (final b in _maps(j['benefits'])) OfferBenefit.fromJson(b)],
      );

  /// শর্তটা কথায় — "১০টা বা বেশি কিনলে", "৳৫০০০ থেকে ৳৯৯৯৯ কিনলে"
  String get condition {
    // ⓘ "১০টা বা বেশি", "১০–২৪টা", "২৪টা পর্যন্ত" — এককটা সংখ্যার গায়ে
    final w = AppLocale.l10n;
    String range({bool money = false}) {
      // ⓘ টাকার শর্ত টাকার ধরনে (লাখের ভাগ, দুই দশমিক — [[Money.taka]]); পরিমাণ নিজের এককে
      String n(String v) => money ? Money.taka(v) : w.offerQuantityUnit(v);
      return switch ((from, to)) {
        (final f?, final t?) => '${n(f)}–${n(t)}',
        (final f?, null) => w.offerRangeOrMore(n(f)),
        (null, final t?) => w.offerRangeUpTo(n(t)),
        _ => '',
      };
    }

    return switch (kind) {
      'quantity' => from == null && to == null ? w.offerAnyQuantity : w.offerWhenBuying(range()),
      'value' => from == null && to == null ? w.offerAnyAmount : w.offerWhenBuying(range(money: true)),
      'product' => product == null ? w.offerSetProduct : w.offerWhenBuying(product!),
      'time' => fromTime == null ? w.offerSetTime : w.offerTimeWindow(fromTime!, toTime ?? ''),
      _ => '',
    };
  }

  String get words => [condition, benefits.map((b) => b.words).join(' + ')].where((s) => s.isNotEmpty).join(' → ');
}

class Offer {
  const Offer({
    required this.id,
    required this.code,
    required this.name,
    this.summary = '',
    this.type = '',
    this.startsOn,
    this.endsOn,
    this.endsAt,
    this.products = const [],
    this.categories = const [],
    this.brands = const [],
    this.shops = const [],
    this.partyTypes = const [],
    this.areas = const [],
    this.steps = const [],
    this.benefits = const [],
  });

  final String id;
  final String code;
  final String name;
  final String summary;
  final String type;
  final String? startsOn;
  final String? endsOn;
  final String? endsAt;
  final List<String> products;
  final List<String> categories;
  final List<String> brands;
  final List<String> shops;
  final List<String> partyTypes;
  final List<String> areas;
  final List<OfferStep> steps;

  /// শর্ত ছাড়া সুবিধা — সবসময়
  final List<OfferBenefit> benefits;

  factory Offer.fromJson(Map<String, dynamic> j) => Offer(
        id: _s(j['id']),
        code: _s(j['code']),
        name: _s(j['name']),
        summary: _s(j['summary']),
        type: _s(j['type']),
        startsOn: _sn(j['starts_on']),
        endsOn: _sn(j['ends_on']),
        endsAt: _sn(j['ends_at']),
        products: _list(j['products']),
        categories: _list(j['categories']),
        brands: _list(j['brands']),
        shops: _list(j['shops']),
        partyTypes: _list(j['party_types']),
        areas: _list(j['areas']),
        steps: [for (final s in _maps(j['steps'])) OfferStep.fromJson(s)],
        benefits: [for (final b in _maps(j['benefits'])) OfferBenefit.fromJson(b)],
      );

  /// কোন মালে — পণ্য, না থাকলে ধরন বা ব্র্যান্ড, কিছুই না থাকলে সব মাল
  String get goods {
    final parts = [
      ...products,
      ...categories.map(AppLocale.l10n.offerGoodsCategory),
      ...brands.map(AppLocale.l10n.offerGoodsBrand),
    ];
    return parts.isEmpty ? AppLocale.l10n.offerGoodsAll : parts.join(', ');
  }

  /// কার জন্য — নির্দিষ্ট দোকান, দোকানের ধরন, এলাকা; কিছুই না থাকলে সবার
  String? get forWhom {
    final parts = [
      if (shops.isNotEmpty) AppLocale.l10n.offerForShops(shops.join(', ')),
      if (partyTypes.isNotEmpty) AppLocale.l10n.offerForShopTypes(partyTypes.join(', ')),
      if (areas.isNotEmpty) AppLocale.l10n.offerForAreas(areas.join(', ')),
    ];
    return parts.isEmpty ? null : parts.join(' · ');
  }

  /// নিয়মের সারিগুলো — শর্তসহ ধাপ আগে, শর্তহীন সুবিধা পরে
  List<String> get rules => [
        for (final s in steps) s.words,
        for (final b in benefits) b.words,
      ].where((r) => r.trim().isNotEmpty).toList();
}

class OffersPage {
  const OffersPage({required this.enabled, required this.rows});

  /// এই শাখায় প্রচার বন্ধ হলে false
  final bool enabled;
  final List<Offer> rows;
}

abstract class OffersApi {
  Future<OffersPage> running();
}

class ServerOffersApi implements OffersApi {
  const ServerOffersApi();

  @override
  Future<OffersPage> running() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/promotion/offers');
    final body = response.data ?? const <String, dynamic>{};
    return OffersPage(
      enabled: body['enabled'] != false,
      rows: [for (final r in _maps(body['rows'])) Offer.fromJson(r)],
    );
  }
}
