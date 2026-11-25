import 'dart:async';

import 'package:flutter/foundation.dart';

/// ⭐ বার্তা ও কলের push — FCM-এর `data` থেকে অ্যাপের ভেতরের খবর (এক জায়গা থেকে, সবাই শোনে)।
///
/// <p>সার্ভার পাঠায় `data: {open: messenger, type: chat|call|call_end, thread_id, message_id, call_id, video, from}`।
/// push_service এটাকে [MessengerPush.emit] করে; শোনে উপরের বারের না-পড়ার গোনা, খোলা চ্যাট (সাথে সাথে টানে), আর কলের পাতা।
@immutable
class MessengerEvent {
  const MessengerEvent(this.type, [this.data = const {}]);

  /// chat · call · call_end
  final String type;
  final Map<String, String> data;

  String? get threadId => data['thread_id'];
  String? get callId => data['call_id'];

  String? get messageId => data['message_id'];

  /// যিনি পাঠালেন বা কল দিলেন — তাঁর user id (লেখায়)
  // ⓘ 'from' Firebase-এর সংরক্ষিত নাম — সার্ভার এখন 'sender' পাঠায় (২৫ নভেম্বর ২০২৬)
  String? get from => data['sender'] ?? data['from'];

  /// কলে ভিডিও কিনা — সার্ভার `true`/`1` পাঠায়
  bool get video => const {'1', 'true'}.contains(data['video']?.toLowerCase());

  /// `call_end`-এ: missed · ended · declined
  String? get status => data['status'];
}

class MessengerPush {
  MessengerPush._();

  static final MessengerPush instance = MessengerPush._();

  final StreamController<MessengerEvent> _events = StreamController<MessengerEvent>.broadcast();

  Stream<MessengerEvent> get events => _events.stream;

  void emit(MessengerEvent e) => _events.add(e);

  /// ⭐ এখন পর্দায় খোলা কথোপকথন — চ্যাটের পাতা বসায়/মোছে; সেই কথোপকথনের নতুন বার্তায় সামনে নোটিফিকেশন দেখানো হয় না
  final ValueNotifier<String?> visibleThread = ValueNotifier<String?>(null);

  /// FCM-এর `data` আমাদের কিনা — হলে খবর, না হলে null
  static MessengerEvent? fromData(Map<String, dynamic> data) {
    if (data['open'] != 'messenger') return null;
    final type = '${data['type'] ?? ''}';
    if (type.isEmpty) return null;
    return MessengerEvent(type, {for (final e in data.entries) e.key: '${e.value}'});
  }
}
