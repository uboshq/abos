import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../locale/app_locale.dart';
import '../push/local_alerts.dart' show alertsChannelId, alertsSmallIcon;
import 'messenger_push.dart';

/// ⭐ বার্তা আর কলের নোটিফিকেশনের পথ — চাপলে অ্যাপের কোন পাতা (রাউটারের পথ নয়, তাই নিজের উপসর্গ `messenger:`)।
///
/// <ul>
///   <li>`messenger:chat?thread=<id>` — সেই কথোপকথন ([openChatById])</li>
///   <li>`messenger:call?id=<call>&video=1&from=<id>&thread=<id>[&action=answer|decline]` — আসা কলের পাতা</li>
/// </ul>
/// ⓘ main.dart-এর চাপ-খোলা পথ এগুলো রাউটারে না দিয়ে কলের ভাগে দেয় (`openMessengerPath`); তালা থাকলে আগের মতোই ধরে রাখে।
class MessengerPaths {
  const MessengerPaths._();

  static const String prefix = 'messenger:';

  static bool isOurs(String path) => path.startsWith(prefix);

  static String chat(String threadId) => '${prefix}chat?${Uri(queryParameters: {'thread': threadId}).query}';

  static String call(MessengerEvent e, {String? action}) => '${prefix}call?${Uri(queryParameters: {
        'id': e.callId ?? '',
        'video': e.video ? '1' : '0',
        if ((e.from ?? '').isNotEmpty) 'from': e.from!,
        if ((e.threadId ?? '').isNotEmpty) 'thread': e.threadId!,
        if (action != null) 'action': action,
      }).query}';

  /// FCM-এর `data` থেকে পথ — আমাদের না হলে null
  static String? fromData(Map<String, dynamic>? data) {
    if (data == null) return null;
    final e = MessengerPush.fromData(data);
    if (e == null) return null;
    return switch (e.type) {
      'chat' when (e.threadId ?? '').isNotEmpty => chat(e.threadId!),
      'call' when (e.callId ?? '').isNotEmpty && e.status != 'missed' => call(e),
      // ⓘ মিসড কল (type call, status missed) আর অন্য খবর — কথোপকথন জানা থাকলে সেটা খোলে
      _ when (e.threadId ?? '').isNotEmpty && (e.type != 'call' || e.status == 'missed') => chat(e.threadId!),
      _ => null,
    };
  }

  /// নোটিফিকেশনের বোতাম (ধরুন/কেটে দিন) চাপলে সেটা পথে জোড়া
  static String? withAction(String? payload, String? actionId) {
    if (payload == null || actionId == null || actionId.isEmpty || !payload.startsWith('${prefix}call')) return payload;
    return '$payload&action=${Uri.encodeQueryComponent(actionId)}';
  }

  /// পথ → কী খুলবে; আমাদের না হলে null
  static MessengerTarget? parse(String path) {
    if (!isOurs(path)) return null;
    final uri = Uri.tryParse('https://x/${path.substring(prefix.length)}');
    if (uri == null) return null;
    final q = uri.queryParameters;
    return switch (uri.path) {
      '/chat' when (q['thread'] ?? '').isNotEmpty => ChatTarget(q['thread']!),
      '/call' when (q['id'] ?? '').isNotEmpty => CallTarget(
          callId: q['id']!,
          video: q['video'] == '1',
          from: q['from'] ?? '',
          threadId: q['thread'] ?? '',
          action: q['action'] ?? '',
        ),
      _ => null,
    };
  }
}

sealed class MessengerTarget {
  const MessengerTarget();
}

class ChatTarget extends MessengerTarget {
  const ChatTarget(this.threadId);

  final String threadId;
}

class CallTarget extends MessengerTarget {
  const CallTarget({required this.callId, required this.video, this.from = '', this.threadId = '', this.action = ''});

  final String callId;
  final bool video;

  /// যিনি কল দিলেন — তাঁর id (সার্ভারের user id, লেখায়)
  final String from;
  final String threadId;

  /// '' · answer · decline — নোটিফিকেশনের বোতাম
  final String action;
}

/// আসা কলের চ্যানেল — নিজের, কারণ রিংটোন চ্যানেলেই বাঁধা (Android 8+)
const String callsChannelId = 'abos_calls';

/// নোটিফিকেশনের বোতাম
const String callActionAnswer = 'answer';
const String callActionDecline = 'decline';

/// কতক্ষণ রিং — তারপর "উত্তর দেননি"
const Duration callRingFor = Duration(seconds: 45);

int callNotificationId(String callId) => ('call:$callId').hashCode & 0x7fffffff;

int chatNotificationId(String threadId) => ('chat:$threadId').hashCode & 0x7fffffff;

/// ⭐ বার্তা আর কলের নোটিফিকেশন — অ্যাপ সামনে, পেছনে বা বন্ধ (পেছনের isolate-ও এটাই ডাকে)।
///
/// <p>আসা কল: পূর্ণ-পর্দা বার্তা, কলের ধরন, ফোনের রিংটোন বারবার (FLAG_INSISTENT), "ধরুন"/"কেটে দিন" বোতাম; ৪৫ সেকেন্ডে
/// নিজে সরে যায়। `call_end` এলে সরানো ([cancelCall])। ⛔ কিছুই ছোড়ে না।
class MessengerAlerts {
  MessengerAlerts._();

  static final MessengerAlerts instance = MessengerAlerts._();

  FlutterLocalNotificationsPlugin get _plugin => FlutterLocalNotificationsPlugin();

  bool _channelReady = false;

  bool get _android => !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

  /// পেছনের isolate-এ প্লাগইন তোলা (চাপ ধরে সামনের অ্যাপ — [LocalAlerts])
  Future<void> initInBackground() async {
    if (!_android) return;
    try {
      await _plugin.initialize(settings: const InitializationSettings(android: AndroidInitializationSettings(alertsSmallIcon)));
    } catch (e) {
      debugPrint('ABOS messenger: notifications did not start ($e)');
    }
  }

  Future<void> _ensureCallChannel() async {
    if (_channelReady) return;
    final words = AppLocale.l10n;
    await _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.createNotificationChannel(
          AndroidNotificationChannel(
            callsChannelId,
            words.msgCallChannelName,
            description: words.msgCallChannelDescription,
            importance: Importance.max,
            playSound: true,
            sound: const UriAndroidNotificationSound('content://settings/system/ringtone'),
            audioAttributesUsage: AudioAttributesUsage.notificationRingtone,
            enableVibration: true,
          ),
        );
    _channelReady = true;
  }

  /// ⭐ আসা কল — রিং আর পূর্ণ-পর্দা
  Future<void> showCall(MessengerEvent e, {String caller = ''}) async {
    final callId = e.callId;
    if (!_android || callId == null || callId.isEmpty) return;
    try {
      await _ensureCallChannel();
      final words = AppLocale.l10n;
      final who = caller.isNotEmpty ? caller : (e.from ?? '');
      final what = e.video ? words.msgCallIncomingVideo : words.msgCallIncomingVoice;
      await _plugin.show(
        id: callNotificationId(callId),
        title: who.isEmpty ? what : who,
        body: what,
        payload: MessengerPaths.call(e),
        notificationDetails: NotificationDetails(
          android: AndroidNotificationDetails(
            callsChannelId,
            words.msgCallChannelName,
            channelDescription: words.msgCallChannelDescription,
            importance: Importance.max,
            priority: Priority.max,
            icon: alertsSmallIcon,
            category: AndroidNotificationCategory.call,
            fullScreenIntent: true,
            ongoing: true,
            autoCancel: false,
            playSound: true,
            sound: const UriAndroidNotificationSound('content://settings/system/ringtone'),
            audioAttributesUsage: AudioAttributesUsage.notificationRingtone,
            timeoutAfter: callRingFor.inMilliseconds,
            // ⓘ ৪ = FLAG_INSISTENT — রিংটোন থামে না যতক্ষণ বার্তা থাকে
            additionalFlags: Int32List.fromList(const [4]),
            visibility: NotificationVisibility.public,
            actions: [
              AndroidNotificationAction(callActionDecline, words.msgCallDecline, showsUserInterface: true, cancelNotification: true),
              AndroidNotificationAction(callActionAnswer, words.msgCallAnswer, showsUserInterface: true, cancelNotification: true),
            ],
          ),
        ),
      );
    } catch (e) {
      debugPrint('ABOS messenger: call not shown ($e)');
    }
  }

  Future<void> cancelCall(String? callId) async {
    if (!_android || callId == null || callId.isEmpty) return;
    try {
      await _plugin.cancel(id: callNotificationId(callId));
    } catch (_) {}
  }

  /// ⭐ নতুন বার্তা — সার্ভারের লেখা না থাকলে (কেবল data) নিজের সাধারণ লেখা; চাপলে সেই কথোপকথন
  Future<void> showChat(MessengerEvent e, {String? title, String? body}) async {
    final threadId = e.threadId;
    if (!_android || threadId == null || threadId.isEmpty) return;
    try {
      final words = AppLocale.l10n;
      final t = (title ?? '').trim().isNotEmpty ? title!.trim() : words.msgChatNotifTitle;
      final b = (body ?? '').trim().isNotEmpty ? body!.trim() : words.msgChatNotifBody;
      await _plugin.show(
        id: chatNotificationId(threadId),
        title: t,
        body: b,
        payload: MessengerPaths.chat(threadId),
        notificationDetails: NotificationDetails(
          android: AndroidNotificationDetails(
            alertsChannelId,
            words.pushChannelAlertsName,
            channelDescription: words.pushChannelAlertsDescription,
            importance: Importance.high,
            priority: Priority.high,
            icon: alertsSmallIcon,
            category: AndroidNotificationCategory.message,
            styleInformation: BigTextStyleInformation(b),
          ),
        ),
      );
    } catch (e) {
      debugPrint('ABOS messenger: chat not shown ($e)');
    }
  }
}
