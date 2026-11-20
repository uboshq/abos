import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../locale/app_locale.dart';
import 'push_service.dart' show destinationOf, isSyncNudge;

/// ⭐ বার্তা সবসময় দেখা যায় — অ্যাপ বন্ধ, পেছনে, অথবা খোলা/তালাবদ্ধ (মালিক, ২০ নভেম্বর ২০২৬): অনুমোদন, জমা, বার্তা, কল।
///
/// <p>অ্যাপ বন্ধ বা পেছনে থাকলে Android নিজেই ট্রেতে দেখায় — সার্ভার (FcmSender) পাঠায়
/// `android.notification.channel_id = abos_alerts`, আর ম্যানিফেস্টে একই চ্যানেল ডিফল্ট। ⛔ কিন্তু অ্যাপ সামনে খোলা থাকলে
/// (তালার পর্দা ঢাকা থাকলেও) Firebase কিছুই দেখায় না — বার্তাটা কেবল `onMessage`-এ আসে। তাই এখানে নিজে দেখানো
/// ([showInForeground]), একই চ্যানেলে, আর চাপলে একই ঠিকানা ([destinationOf]) — আঙুলের তালা খোলার পরে সেই পাতা।
///
/// <p>⛔ কিছুই অ্যাপ থামায় না: প্লাগইন না উঠলে সব চুপচাপ বাদ — ঠিক [PushService]-এর মতো।
const String alertsChannelId = 'abos_alerts';

/// ছোট আইকন — লঞ্চার আইকনের একরঙা স্তর (Android ছোট আইকনের কেবল আলফা পড়ে); res/raw/keep.xml এটা ছাঁটা থেকে বাঁচায়
const String alertsSmallIcon = '@mipmap/ic_launcher_monochrome';

/// সামনের বার্তা দেখানোর জন্য যা লাগে — প্লাগইনের পেছনে ছোট একটা ফাঁক, পরীক্ষায় নকল বসে
abstract class AlertShower {
  Future<void> show({required int id, required String title, required String body, String? payload});
}

/// সামনে খোলা অ্যাপে একটা বার্তা কীভাবে দেখাবে
@immutable
class ForegroundAlert {
  const ForegroundAlert({required this.id, required this.title, required this.body, this.payload});

  final int id;
  final String title;
  final String body;

  /// চাপলে যে পথ খুলবে ([destinationOf]); null = অ্যাপ যেখানে ছিল সেখানেই
  final String? payload;
}

/// ⭐ সামনের সিদ্ধান্ত: নীরব সিঙ্ক-ডাক → কিছু দেখানো নয়; লেখাসহ বার্তা (notification অংশ) → দেখানো, পথটা payload-এ।
/// ⓘ লেখা নেই (কেবল data) → কিছু নয় — ওটা সার্ভারের ভেতরের ডাক, মানুষের জন্য নয়।
ForegroundAlert? foregroundAlertOf(RemoteMessage message) {
  if (isSyncNudge(message.data)) return null;
  final n = message.notification;
  if (n == null) return null;
  final title = n.title?.trim() ?? '';
  final body = n.body?.trim() ?? '';
  if (title.isEmpty && body.isEmpty) return null;
  return ForegroundAlert(
    id: alertIdOf(message),
    title: title,
    body: body,
    payload: destinationOf(message.data),
  );
}

/// একই বার্তা দুবার এলে একটাই সারি (Firebase-এর messageId ধরে); id না থাকলে সময় ধরে
int alertIdOf(RemoteMessage message) {
  final key = message.messageId ?? '${DateTime.now().microsecondsSinceEpoch}';
  return key.hashCode & 0x7fffffff;
}

/// সামনে খোলা অ্যাপে আসা বার্তা — দেখানো হলে true। ⓘ কখনো ছোড়ে না।
Future<bool> showInForeground(RemoteMessage message, AlertShower shower) async {
  final alert = foregroundAlertOf(message);
  if (alert == null) return false;
  try {
    await shower.show(id: alert.id, title: alert.title, body: alert.body, payload: alert.payload);
    return true;
  } catch (e) {
    debugPrint('ABOS push: alert not shown ($e)');
    return false;
  }
}

/// ⭐ flutter_local_notifications-এর মোড়ক — চ্যানেল বানানো, দেখানো, আর চাপ ধরা।
///
/// <p>চাপ দুই পথে আসে: অ্যাপ চলতে থাকলে প্লাগইনের response-ডাক, আর বার্তায় চেপে অ্যাপ নতুন করে চালু হলে
/// `getNotificationAppLaunchDetails`। দুটোই [onTap]-এর ডাকে যায় — যেটা [PushService.listenForTaps] Firebase-এর চাপের
/// সাথে একই `open` দেয়, তাই তালার পর্দা খোলা পর্যন্ত ঢেকে রাখে, ঠিক Firebase-এর চাপের মতো।
/// ⓘ চাপ আগে এলে আর `open` পরে বসলে পথটা ধরে রাখা হয়, বসামাত্র খোলে।
class LocalAlerts implements AlertShower {
  LocalAlerts._();

  @visibleForTesting
  LocalAlerts.forTest();

  static final LocalAlerts instance = LocalAlerts._();

  bool _ready = false;
  void Function(String path)? _open;
  String? _heldPath;

  FlutterLocalNotificationsPlugin get _plugin => FlutterLocalNotificationsPlugin();

  /// অ্যাপ শুরুতে একবার — প্লাগইন তোলা, "জরুরি বার্তা" চ্যানেল বানানো (প্রতিবার; নাম এখনকার ভাষায় হালনাগাদ হয়),
  /// আর বার্তায় চেপে চালু হলে সেই পথ। ⓘ Android ছাড়া কিছু নয়; ব্যর্থ হলে চুপ।
  Future<void> init() async {
    if (_ready || kIsWeb || defaultTargetPlatform != TargetPlatform.android) return;
    try {
      await _plugin.initialize(
        settings: const InitializationSettings(android: AndroidInitializationSettings(alertsSmallIcon)),
        onDidReceiveNotificationResponse: (r) => handleTap(r.payload),
      );
      final words = AppLocale.l10n;
      await _plugin
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(AndroidNotificationChannel(
            alertsChannelId,
            words.pushChannelAlertsName,
            description: words.pushChannelAlertsDescription,
            importance: Importance.high,
          ));
      _ready = true;
      final launch = await _plugin.getNotificationAppLaunchDetails();
      if (launch?.didNotificationLaunchApp == true) handleTap(launch?.notificationResponse?.payload);
    } catch (e) {
      debugPrint('ABOS push: local alerts did not start ($e)');
    }
  }

  @override
  Future<void> show({required int id, required String title, required String body, String? payload}) async {
    if (!_ready) return;
    final words = AppLocale.l10n;
    await _plugin.show(
      id: id,
      title: title,
      body: body,
      payload: payload,
      notificationDetails: NotificationDetails(
        android: AndroidNotificationDetails(
          alertsChannelId,
          words.pushChannelAlertsName,
          channelDescription: words.pushChannelAlertsDescription,
          importance: Importance.high,
          priority: Priority.high,
          icon: alertsSmallIcon,
          styleInformation: BigTextStyleInformation(body),
        ),
      ),
    );
  }

  /// চাপলে কী খুলবে — [PushService.listenForTaps] বসায়; আগে ধরে রাখা পথ থাকলে এখনই
  void onTap(void Function(String path) open) {
    _open = open;
    final held = _heldPath;
    _heldPath = null;
    if (held != null) open(held);
  }

  /// একটা চাপ — payload-এর পথ খোলা (না থাকলে কিছু নয়; অ্যাপ নিজেই সামনে আসে)
  @visibleForTesting
  void handleTap(String? payload) {
    if (payload == null || payload.isEmpty) return;
    final open = _open;
    if (open == null) {
      _heldPath = payload;
    } else {
      open(payload);
    }
  }
}
