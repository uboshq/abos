import 'package:flutter/services.dart';

import '../config/app_channel.dart';
import '../config/app_config.dart';

/// ⭐ অফিসের SMS ফোন — Android-এর সামনের-সেবার সাথে কথা (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)।
///
/// ⓘ কাজটা Kotlin-এ (`android/.../sms/SmsGatewayService.kt`) — অ্যাপ বন্ধ থাকলেও মিনিটে একবার সারি দেখে, নিজের সিমে পাঠায়,
/// খবর দেয়। এখানে কেবল চালু/বন্ধ, অবস্থা আর অনুমতি।
/// ⓘ ঠিকানা অ্যাপের নিজের সার্ভার (API-র ঠিকানা থেকে `/api/v1` বাদ) — মানুষকে লিখতে হয় শুধু চাবি।
class SmsGatewayChannel {
  const SmsGatewayChannel([this._channel = const MethodChannel('com.abos.abos_mobile/sms_gateway')]);

  final MethodChannel _channel;

  /// সার্ভারের গোড়া — `https://erp.adi.com.bd/api/v1` → `https://erp.adi.com.bd`
  static String serverRoot([String? api]) {
    final base = api ?? AppConfig.apiBaseUrl;
    return base.endsWith('/api/v1') ? base.substring(0, base.length - '/api/v1'.length) : base;
  }

  Future<SmsGatewayStatus> status() async {
    final raw = await _channel.invokeMapMethod<String, Object?>('status') ?? const <String, Object?>{};
    return SmsGatewayStatus.fromMap(raw);
  }

  Future<bool> requestPermission() async {
    if (AppChannel.isPlay) return false;
    return await _channel.invokeMethod<bool>('requestPermission') ?? false;
  }

  /// চাবি খালি হলে আগের চাবিই থাকে (একবার বসানো চাবি আবার লিখতে হয় না)
  Future<void> start({required String key}) async {
    // ⛔ দ্বিতীয় তালা: play বিল্ডে সেবাটা ম্যানিফেস্টেই নেই, চালু করার চেষ্টাও নয়
    if (AppChannel.isPlay) return;
    await _channel.invokeMethod<bool>('start', {
      'baseUrl': serverRoot(),
      'key': key.trim(),
      'appVersion': AppConfig.appVersion,
    });
  }

  Future<void> stop() => _channel.invokeMethod<bool>('stop');
}

/// ফোনের এখনকার অবস্থা — পাতায় দেখানোর জন্য
class SmsGatewayStatus {
  const SmsGatewayStatus({
    required this.enabled,
    required this.hasKey,
    required this.permission,
    required this.sentToday,
    required this.waitingReports,
    this.lastRun,
    this.lastError = '',
  });

  factory SmsGatewayStatus.fromMap(Map<String, Object?> raw) {
    final last = (raw['lastRun'] as num?)?.toInt() ?? 0;
    return SmsGatewayStatus(
      enabled: raw['enabled'] == true,
      hasKey: raw['hasKey'] == true,
      permission: raw['permission'] == true,
      sentToday: (raw['sentToday'] as num?)?.toInt() ?? 0,
      waitingReports: (raw['waitingReports'] as num?)?.toInt() ?? 0,
      lastRun: last == 0 ? null : DateTime.fromMillisecondsSinceEpoch(last),
      lastError: (raw['lastError'] as String?) ?? '',
    );
  }

  static const off = SmsGatewayStatus(enabled: false, hasKey: false, permission: false, sentToday: 0, waitingReports: 0);

  final bool enabled;
  final bool hasKey;
  final bool permission;
  final int sentToday;
  final int waitingReports;
  final DateTime? lastRun;
  final String lastError;

  /// ⛔ সার্ভার চাবি ফিরিয়ে দিয়েছে — সেবা নিজেই থেমেছে
  bool get keyRefused => lastError == '401';
}
