import 'dart:async';

import 'package:flutter/widgets.dart';

import '../api_client/api_client.dart';

/// ⭐ দূর থেকে বের করা — সার্ভার সেশন বাতিল করলে ফোনও বের হয় (অ্যাপের নিরাপত্তা, OWASP MASVS-AUTH-1, ১৯ অক্টোবর ২০২৬)।
///
/// <p>⚠️ সার্ভার বের করে দিলে (ওয়েবের "অন্য সব জায়গা থেকে বের হন", অ্যাকাউন্ট বন্ধ, পাসওয়ার্ড বদল) ফোনের সব টোকেন মোছে,
/// কিন্তু ফোন সেটা জানত কেবল পরের ডাকে: সিঙ্ক ১০ মিনিট পরপর, আর সামনে আসার আগের ২ মিনিটে সিঙ্ক হয়ে থাকলে আর নয়। ফলে
/// হারানো বা কেড়ে নেওয়া ফোনে বাতিল সেশনের পর্দা ১০ মিনিট পর্যন্ত খোলা থাকত।
///
/// <p>⭐ এখন: অ্যাপ সামনে এলে, আর সামনে থাকলে প্রতি [interval]-এ, একটা হালকা যাচাই ([check]; আসলে `/me`) — ৩০ সেকেন্ডের মধ্যে
/// দুবার নয় ([minimumGap])। ⓘ বের করার কাজ [[ApiClient]]-এর নিজের: ৪০১ → নবায়ন → নবায়নও ফেরালে সেশন শেষ, লগইনের পর্দা।
/// নেট না থাকলে কিছুই হয় না — অফলাইনে মানুষ বের হন না ([[ApiClient]]-এর নিয়ম)। কিছুই ছোড়ে না।
class SessionWatch with WidgetsBindingObserver {
  SessionWatch({
    required this.signedIn,
    Future<void> Function()? check,
    DateTime Function()? clock,
    this.interval = const Duration(minutes: 2),
  })  : _check = check ?? _askTheServer,
        _clock = clock ?? DateTime.now;

  static const Duration minimumGap = Duration(seconds: 30);

  final bool Function() signedIn;
  final Future<void> Function() _check;
  final DateTime Function() _clock;
  final Duration interval;

  Timer? _timer;
  DateTime? _last;
  bool _inFront = true;

  void start() {
    WidgetsBinding.instance.addObserver(this);
    _startTimer();
  }

  void _startTimer() {
    _timer ??= Timer.periodic(interval, (_) {
      if (_inFront) unawaited(checkNow());
    });
  }

  void stop() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _timer = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _inFront = state == AppLifecycleState.resumed;
    if (_inFront) {
      _startTimer();
      unawaited(checkNow());
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      // ⛔ no timer while the app is in the background (polling cut, 23 Nov 2026) — back in front it checks at once
      _timer?.cancel();
      _timer = null;
    }
  }

  /// একবার যাচাই — ঢোকা না থাকলে, বা এইমাত্র হয়ে থাকলে নয়; ফেরত দেয় যাচাই হলো কি না
  Future<bool> checkNow() async {
    if (!signedIn()) return false;
    final last = _last;
    final now = _clock();
    if (last != null && now.difference(last) < minimumGap) return false;
    _last = now;
    try {
      await _check();
    } catch (_) {
      // ⓘ ৪০১-এর পথ ApiClient নিজেই সামলায় (বের করে দেয়); বাকি ভুল (নেট নেই, সার্ভার ব্যস্ত) — চুপ, পরের বার আবার
    }
    return true;
  }

  static Future<void> _askTheServer() async {
    await ApiClient.dio.get<void>('/me');
  }
}
