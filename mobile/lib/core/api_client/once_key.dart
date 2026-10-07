import 'dart:async';
import 'dart:math';

import 'network_errors.dart';

/// ⭐ একটা লেখা একবারই — `Idempotency-Key` (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১২: নেট ধীর হলে বা দুবার চাপলে জমার
/// বিজ্ঞপ্তি, আদেশ, DO, ফেরত, লিড, কোটেশন আর খরচের দাবি দুবার বসত; সার্ভার [RemembersAPhoneWrite] 2914831d)।
///
/// <p>পর্দা একটা কাজের জন্য একটা [OnceKey] রাখে আর লেখাটা [send] দিয়ে পাঠায়: চাবিটা এই ডাকের জোনে বসে, আর [ApiClient]-এর
/// ইন্টারসেপ্টর সেই জোনের প্রতিটা POST-এ হেডার জোড়ে — তাই কোনো API-র চুক্তি বদলায় না। নেট গেলে চাবি থাকে (আবার চাপলে একই
/// কাজ, সার্ভার আগের উত্তর ফেরায়); বসে গেলে বা সার্ভার ফেরালে নতুন চাবি ([settle])।
class OnceKey {
  String? _key;

  static const Symbol _zoneKey = #abosOnceKey;
  static final Random _random = Random.secure();

  String get key => _key ??= List.generate(32, (_) => _random.nextInt(16).toRadixString(16)).join();

  /// এই জোনের চাবি — ইন্টারসেপ্টর পড়ে; জোনের বাইরে null
  static String? get current => Zone.current[_zoneKey] as String?;

  /// লেখাটা চাবিসহ; শেষে [settle] — ভুল হলে ছুড়ে দেয়, যেমন ছিল
  Future<T> send<T>(Future<T> Function() write) async {
    try {
      final result = await runZoned(write, zoneValues: {_zoneKey: key});
      _key = null;
      return result;
    } catch (error) {
      settle(error);
      rethrow;
    }
  }

  /// নেট না থাকলে চাবি থাকে; বাকি সব ভুলে (সার্ভার ফেরাল) নতুন চাবি
  void settle(Object error) {
    if (!isNetworkError(error)) _key = null;
  }
}
