import '../api_client/api_client.dart';
import '../records/money.dart';

/// ⭐ আমার আজকের রুট — `GET /sales/my-route` (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)।
///
/// <p>সাপ্তাহিক ছকে এই দিনে যে রুটে আমার নাম, সেই রুটের দোকান — পাতায় ৫০, প্রতিটায় এ মাসের বিক্রি, আদায় আর বকেয়া।
/// অঙ্ক সার্ভারের, ওয়েবের রুট-পাতার একই হিসাবে; ফোন কিছু গোনে না।
class RouteShop {
  const RouteShop({
    required this.id,
    required this.route,
    required this.code,
    required this.name,
    this.phone,
    this.address,
    required this.sales,
    required this.collections,
    required this.outstanding,
  });

  final String id;
  final String route;
  final String code;
  final String name;
  final String? phone;
  final String? address;
  final double sales;
  final double collections;
  final double outstanding;

  factory RouteShop.fromJson(Map<String, dynamic> json) => RouteShop(
        id: json['id']?.toString() ?? '',
        route: json['route']?.toString() ?? '',
        code: json['code']?.toString() ?? '',
        name: json['name']?.toString() ?? '—',
        phone: json['phone']?.toString(),
        address: json['address']?.toString(),
        sales: Money.valueOrZero(json['sales']),
        collections: Money.valueOrZero(json['collections']),
        outstanding: Money.valueOrZero(json['outstanding']),
      );
}

class RouteDay {
  const RouteDay({required this.date, required this.routes, required this.shops, this.nextPage});

  final String date;

  /// রুটের public id → নাম, ছকের ক্রমে।
  final Map<String, String> routes;
  final List<RouteShop> shops;
  final int? nextPage;

  factory RouteDay.fromJson(Map<String, dynamic> json) => RouteDay(
        date: json['date']?.toString() ?? '',
        routes: {
          for (final r in (json['routes'] as List?) ?? const [])
            if (r is Map) r['id'].toString(): r['name']?.toString() ?? '—',
        },
        shops: [
          for (final s in (json['shops'] as List?) ?? const [])
            if (s is Map) RouteShop.fromJson(Map<String, dynamic>.from(s)),
        ],
        nextPage: (json['next_page'] as num?)?.toInt(),
      );
}

class MyRouteApi {
  const MyRouteApi._();

  /// `date` — `YYYY-MM-DD`; না দিলে সার্ভারের আজ।
  static Future<RouteDay> day({String? date, int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/sales/my-route', queryParameters: {
      if (date != null) 'date': date,
      if (page > 1) 'page': page,
    });
    return RouteDay.fromJson(response.data ?? const {});
  }
}
