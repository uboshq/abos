import '../records/money.dart';

/// ⭐ ছাঁকা তালিকার মোট আর গোনা — সার্ভারের, পুরো ফলের (পাতার নয়)। মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"।
///
/// ⓘ সার্ভার পাতায় ৫০টা পাঠায়, তাই ফোনে যোগ করলে ৫১তম সারি বাদ পড়ত। পুরনো সার্ভার `total` না পাঠালে `null` —
/// তখন পট্টিটাই বসে না, ভুল মোট দেখানোর চেয়ে না দেখানো ভালো।
class PaperTotal {
  const PaperTotal({required this.sum, required this.count});

  final double sum;
  final int count;

  static PaperTotal? fromJson(Map<String, dynamic> data) {
    if (!data.containsKey('total')) return null;
    return PaperTotal(
      sum: Money.valueOrZero(data['total']),
      count: (data['count'] as num?)?.toInt() ?? 0,
    );
  }
}
