import 'dart:async';
import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:path_provider/path_provider.dart';

import '../api_client/api_client.dart';

/// "নতুন কিছু আছে?"-র উত্তর
enum LiveAnswer { unchanged, changed, unsupported }

/// ⭐ সামনে খোলা অ্যাপের ছোট প্রশ্ন — "নতুন কিছু আছে?" (a4-এর পরিকল্পনা সংস্করণ ২ §৪.১ ধাপ ২ আর ৬)।
///
/// <p>প্রতি ১৫–২০ সেকেন্ডে (একটু এলোমেলো, যাতে সব ফোন একসাথে না ডাকে) সার্ভারের ছোট দরজা `GET /sync/changes` — সাথে আগের
/// etag; উত্তর ৩০৪ (কিছু বদলায়নি, কয়েক বাইট) বা ২০০ নতুন etag-সহ — তখনই পুরো সিঙ্ক ([onChanged])।
/// ⓘ Battery Saver চালু থাকলে ৬০ সেকেন্ডে; একটানা ব্যর্থ হলে ২০ → ৪০ → ৮০ … সর্বোচ্চ ৫ মিনিট; পেছনে গেলে থামে, সামনে এলে
/// সাথে সাথে একবার। ⛔ পুরনো সার্ভারে দরজা নেই (৪০৪) — প্রশ্ন থামে, আগের ১০ মিনিটের গোলই থাকে ([AutoSync])।
class LivePoll with WidgetsBindingObserver {
  LivePoll({
    required this.signedIn,
    required this.onChanged,
    Future<(LiveAnswer, String?)> Function(String? etag)? ask,
    Future<bool> Function()? powerSave,
    Random? random,
  })  : _ask = ask ?? LiveApi.ask,
        _powerSave = powerSave ?? PowerSave.isOn,
        _random = random ?? Random();

  final bool Function() signedIn;

  /// নতুন কিছু এসেছে — পুরো গোল
  final Future<void> Function() onChanged;

  final Future<(LiveAnswer, String?)> Function(String? etag) _ask;
  final Future<bool> Function() _powerSave;
  final Random _random;

  static const Duration minimum = Duration(seconds: 15);
  static const Duration jitter = Duration(seconds: 5);
  static const Duration batterySaver = Duration(seconds: 60);
  static const Duration firstBackoff = Duration(seconds: 20);
  static const Duration maxBackoff = Duration(minutes: 5);

  Timer? _timer;
  String? _etag;
  int _failures = 0;
  bool _foreground = true;
  bool _unsupported = false;
  bool _asking = false;

  bool get unsupported => _unsupported;
  int get failures => _failures;

  void start() {
    WidgetsBinding.instance.addObserver(this);
    _schedule(Duration.zero);
  }

  void stop() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _timer = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _foreground = true;
      _schedule(Duration.zero);
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      // ⓘ পেছনে প্রশ্ন নয় — ব্যাটারি; পেছনের ভরসা নীরব ডাক আর ১৫ মিনিটের কাজ
      _foreground = false;
      _timer?.cancel();
      _timer = null;
    }
  }

  /// পরের প্রশ্নের আগে কত — ব্যর্থতা, Battery Saver, নইলে ১৫–২০ সেকেন্ড
  Future<Duration> nextDelay() async {
    if (_failures > 0) {
      final seconds = firstBackoff.inSeconds * pow(2, _failures - 1);
      return Duration(seconds: min(seconds.toInt(), maxBackoff.inSeconds));
    }
    bool saving = false;
    try {
      saving = await _powerSave();
    } catch (_) {}
    if (saving) return batterySaver;
    return minimum + Duration(milliseconds: _random.nextInt(jitter.inMilliseconds + 1));
  }

  void _schedule(Duration delay) {
    if (_unsupported || !_foreground) return;
    _timer?.cancel();
    _timer = Timer(delay, () => unawaited(tick()));
  }

  /// একটা প্রশ্ন — তারপর পরেরটা বসানো
  @visibleForTesting
  Future<void> tick() async {
    if (_asking || _unsupported || !_foreground) return;
    if (!signedIn()) {
      _schedule(await nextDelay());
      return;
    }
    _asking = true;
    try {
      final (answer, etag) = await _ask(_etag);
      switch (answer) {
        case LiveAnswer.unsupported:
          _unsupported = true;
          return;
        case LiveAnswer.unchanged:
          break;
        case LiveAnswer.changed:
          final first = _etag == null;
          _etag = etag;
          // ⓘ প্রথম উত্তর কেবল ছাপ শেখায় — খোলার মুহূর্তে [AutoSync] নিজেই একটা গোল চালায়
          if (!first) await onChanged();
      }
      _failures = 0;
    } catch (_) {
      _failures++;
    } finally {
      _asking = false;
    }
    _schedule(await nextDelay());
  }
}

/// `GET /sync/changes` — etag দিয়ে; ৩০৪ মানে কিছু বদলায়নি
class LiveApi {
  const LiveApi._();

  static Future<(LiveAnswer, String?)> ask(String? etag) async {
    try {
      final response = await ApiClient.dio.get<Map<String, dynamic>>(
        '/sync/changes',
        options: Options(headers: {if (etag != null) 'If-None-Match': etag}),
      );
      return (LiveAnswer.changed, response.headers.value('etag') ?? response.data?['stamp']?.toString());
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status == 304) return (LiveAnswer.unchanged, etag);
      if (status == 404 || status == 405) return (LiveAnswer.unsupported, null);
      rethrow;
    }
  }
}

/// Android-এর Battery Saver — চালু থাকলে প্রশ্ন কম (`MainActivity`-র `abos/power` চ্যানেল); অন্য প্ল্যাটফর্মে false
class PowerSave {
  const PowerSave._();

  static const MethodChannel _channel = MethodChannel('abos/power');

  static Future<bool> isOn() async {
    if (kIsWeb || !Platform.isAndroid) return false;
    try {
      return await _channel.invokeMethod<bool>('isPowerSaveMode') ?? false;
    } catch (_) {
      return false;
    }
  }
}

/// ⭐ পেছনে নীরব ডাক এসেছিল — অ্যাপ সামনে এলেই টানা, দুই মিনিটের ফাঁক না মেনে (a4-এর পরিকল্পনা §৪.১ ধাপ ৩)।
///
/// <p>ⓘ একটা ছোট ফাইল, বাক্স নয়: পেছনের isolate আর খোলা অ্যাপ একই Hive বাক্স একসাথে খুললে বাক্স ভাঙে; ফাইলে কেবল সময়।
class NudgeMark {
  const NudgeMark._();

  static Future<File> _file() async => File('${(await getApplicationSupportDirectory()).path}/abos-sync-nudge');

  static Future<void> set() async {
    try {
      await (await _file()).writeAsString(DateTime.now().toIso8601String(), flush: true);
    } catch (_) {}
  }

  /// ডাক জমে ছিল কি না — আর পড়ার সাথে সাথে মুছে ফেলা
  static Future<bool> take() async {
    try {
      final file = await _file();
      if (!await file.exists()) return false;
      await file.delete();
      return true;
    } catch (_) {
      return false;
    }
  }
}
