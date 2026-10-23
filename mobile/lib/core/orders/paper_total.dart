import '../records/money.dart';

/// ⭐ ছাঁকা তালিকার মোট আর গোনা — সার্ভারের, পুরো ফলের (পাতার নয়)। মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"।
///
/// ⓘ সার্ভার পাতায় ৫০টা পাঠায়, তাই ফোনে যোগ করলে ৫১তম সারি বাদ পড়ত। পুরনো সার্ভার `total` না পাঠালে `null` —
/// তখন পট্টিটাই বসে না, ভুল মোট দেখানোর চেয়ে না দেখানো ভালো।
class PaperTotal {
  const PaperTotal({required this.sum, required this.count});

  final double sum;
  final int count;

  /// ⭐ মোটে না পড়া অবস্থা — প্রত্যাখ্যাত আর বাতিল আদেশ সারিতে থাকে, মোটে নয়। সার্ভারের `SalesOrderStatus::UNCOUNTED` আর
  /// `DeliveryOrderStatus::UNCOUNTED`-এর হুবহু (ওয়েবের আদেশের তালিকা `SalesOrderController` আর ফোনের দরজা একই নিয়ম পড়ে),
  /// তাই ফোনে কষা মোট আর ওয়েবের মোট এক কথা বলে।
  static const Set<String> uncounted = {'rejected', 'cancelled'};

  /// এই অবস্থার কাগজ মোটে পড়ে কি না ([uncounted]-এ নেই তো পড়ে)
  static bool counts(String? status) => !uncounted.contains(status);

  static PaperTotal? fromJson(Map<String, dynamic> data) {
    if (!data.containsKey('total')) return null;
    return PaperTotal(
      sum: Money.valueOrZero(data['total']),
      count: (data['count'] as num?)?.toInt() ?? 0,
    );
  }
}
