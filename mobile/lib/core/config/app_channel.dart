import 'package:flutter/foundation.dart';

/// ⭐ অ্যাপের দুই বিলি-পথ (২০ নভেম্বর ২০২৬, মালিকের সিদ্ধান্ত) — একটাই সুইচ, এখানে।
///
/// * `side` (ডিফল্ট) — আজকের APK, `com.abos.abos_mobile`, অফিসের লিংক থেকে বসানো। নিজের আপডেট নিজে নামায়
///   আর বসায়, অফিসের SMS ফোন (নিজের SIM) চালায়। কিছুই বদলায়নি।
/// * `play` — Google Play-র অ্যাপ `univer.abos`। Play-র নিয়মে অ্যাপ নিজের APK নামাতে/বসাতে পারে না, আর SEND_SMS
///   কেবল ডিফল্ট SMS অ্যাপের — তাই এই বিল্ডে নিজে-আপডেটের বদলে Play Store-এর পাতা খোলে, আর SMS ফোনের সারি/পথ নেই।
///
/// বিল্ডে: `flutter build appbundle --flavor play --dart-define=ABOS_CHANNEL=play` (tool/release-play.sh)।
/// ⛔ সুইচ কেবল এখানে পড়া হয় — অন্য কোথাও `String.fromEnvironment('ABOS_CHANNEL')` নয়।
class AppChannel {
  const AppChannel._();

  static const String _fromBuild = String.fromEnvironment('ABOS_CHANNEL', defaultValue: 'side');

  static String? _override;

  /// Play-র অ্যাপের আইডি — Gradle-এর `play` ফ্লেভারের applicationId-এর সাথে হুবহু
  static const String playApplicationId = 'univer.abos';

  /// Play Store-এ এই অ্যাপের পাতা — play বিল্ডে "আপডেট" মানে এটা খোলা
  static const String playStoreUrl = 'https://play.google.com/store/apps/details?id=$playApplicationId';

  /// `side` বা `play`
  static String get name => _override ?? _fromBuild;

  /// Google Play-র বিল্ড — নিজে-আপডেট আর SMS ফোন নেই
  static bool get isPlay => name == 'play';

  /// পরীক্ষার পথ — দুই বিল্ডই এক টেস্ট-চালনায় দেখা যায়; `null` দিলে বিল্ডের মানে ফেরে
  @visibleForTesting
  static set debugOverride(String? channel) => _override = channel;
}
