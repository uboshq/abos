import '../api_client/api_client.dart';
import 'money.dart';

/// ⭐ চলতি মূলধন — ফোনের হোমে টাকার কার্ডের নিচে (মালিক, ৯ নভেম্বর ২০২৬: "mobile app e za ekhon zemon ache tai thakbe sudu working
/// capital er card ti bosabe")। সার্ভারের `GET /dashboard/today`-এর `workingCapital` — ওয়েবের অর্থের ড্যাশবোর্ডের একই অঙ্ক
/// (fe-dash2 `WorkingCapital`)। ⓘ পুরনো সার্ভার বা অর্থের চাবি না থাকলে ঘরটাই আসে না, তখন কার্ডও নেই (চুক্তির নিয়ম ক)।
class WorkingCapitalRecord {
  const WorkingCapitalRecord({
    required this.date,
    required this.receivable,
    required this.stock,
    required this.money,
    required this.payable,
    required this.other,
    required this.capital,
    required this.ratio,
    required this.changePct,
    required this.span,
    required this.avgSales,
    required this.salesPct,
  });

  /// সার্ভারের হিসাবের তারিখ (`yyyy-mm-dd`)
  final String date;
  final double receivable;
  final double stock;
  final double money;
  final double payable;
  final double other;
  final double capital;

  /// চলতি অনুপাত — দায় না থাকলে null
  final double? ratio;

  /// আগের মাসের শেষের তুলনায় % — আগের মূলধন শূন্য হলে null
  final double? changePct;

  /// গড়ের মাস — ৬ বা ১২
  final int span;
  final double avgSales;

  /// চলতি মূলধন ÷ গড় মাসিক বিক্রি × ১০০ — বিক্রি না থাকলে null
  final int? salesPct;

  static WorkingCapitalRecord? fromJson(Object? block) {
    if (block is! Map) return null;
    double money(String key) => Money.valueOrZero(Money.value(block[key]));
    double? number(String key) => block[key] == null ? null : double.tryParse(block[key].toString());
    return WorkingCapitalRecord(
      date: (block['date'] ?? '').toString(),
      receivable: money('receivable'),
      stock: money('stock'),
      money: money('money'),
      payable: money('payable'),
      other: money('other'),
      capital: money('capital'),
      ratio: number('ratio'),
      changePct: number('changePct'),
      span: (block['span'] as num?)?.toInt() ?? 6,
      avgSales: money('avgSales'),
      salesPct: number('salesPct')?.round(),
    );
  }
}

/// চলতি মূলধন অন্য তারিখে বা অন্য গড়ে — একই দরজা, `wcDate` আর `wcSpan` দিয়ে
abstract class WorkingCapitalApi {
  Future<WorkingCapitalRecord?> load({String? date, int span = 6});
}

class ServerWorkingCapitalApi implements WorkingCapitalApi {
  const ServerWorkingCapitalApi();

  @override
  Future<WorkingCapitalRecord?> load({String? date, int span = 6}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/dashboard/today',
      queryParameters: {if (date != null) 'wcDate': date, 'wcSpan': span},
    );
    return WorkingCapitalRecord.fromJson((response.data ?? const {})['workingCapital']);
  }
}
