import 'dart:async';
import 'dart:math';

import 'package:dio/dio.dart';

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

  static final Map<String, OnceKey> _byAction = {};

  /// ⭐ একটা কাজের চাবি — একই কাজ (একই কাগজে "সই", একই টোকেনে "গেট-আউট") আবার পাঠালে একই চাবি, যতক্ষণ না সার্ভার "হলো" বা
  /// "না" বলে ([[send]], [[settle]]) (পূর্ণ নিরীক্ষা §৯ ⚠️৮, ১৮ অক্টোবর ২০২৬)। ⓘ পর্দা ধরে রাখতে হয় না — দুবার চাপা বা উত্তর
  /// হারানো যেখান থেকেই আসুক, সার্ভারে একটাই বসে।
  static OnceKey of(String action) => _byAction.putIfAbsent(action, OnceKey.new);

  /// ⛔ বেরোলে সব চাবি ভুলে যাওয়া — পরের জনের একই কাগজের কাজ আগের জনের চাবিতে গেলে সার্ভার আগের জনের উত্তর ফেরাত
  static void forgetAll() => _byAction.clear();

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

  /// ⛔ নতুন চাবি কেবল সার্ভার সত্যিই ফেরালে (৪xx)। নেট না থাকলে, ৫xx-এ (প্রক্সির 502/504, লেনদেনের পরে 500) বা উত্তর পড়তে
  /// না পারলে লেখাটা হয়তো বসে গেছে — একই চাবিতে আবার চাপলে সার্ভার আগের উত্তর ফেরায়; নতুন চাবিতে দুবার বসত
  /// (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)।
  void settle(Object error) {
    final status = error is DioException ? error.response?.statusCode : null;
    if (status != null && status >= 400 && status < 500) _key = null;
  }
}
