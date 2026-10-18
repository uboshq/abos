import 'package:flutter/foundation.dart';

/// ⭐ সার্ভার বলল "এই বিল্ড আর চলে না" (৪২৬) — পূর্ণ নিরীক্ষা §৯ ⚠️৯, ১৮ অক্টোবর ২০২৬ (সার্ভারের [[RefuseRetiredAppBuilds]])।
///
/// <p>⛔ আগে সর্বনিম্ন সংস্করণ কেবল ফোনের নিজের পরীক্ষা — `/app/version` না পৌঁছালে বা খোলার পরে সর্বনিম্ন উঠলে বন্ধ বিল্ড
/// চলতেই থাকত। ⭐ এখন সার্ভার লেখায় ৪২৬ দেয়, ইন্টারসেপ্টর এখানে গোনে, আর আপডেটের দরজা ([[UpdateGate]]) দেয়াল তোলে।
/// ⓘ অফলাইন সারি অক্ষত — পুরো পাঠানো ব্যর্থ হয়, কোনো অর্ডার "ফেরত" হয় না; নতুন সংস্করণ বসালে যায়।
class RetiredBuild {
  const RetiredBuild._();

  /// কয়বার সার্ভার ফেরাল — দরজা শোনে
  static final ValueNotifier<int> refusals = ValueNotifier<int>(0);

  static void noteRefusal() => refusals.value++;
}
