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
}

class MessengerPush {
  MessengerPush._();

  static final MessengerPush instance = MessengerPush._();

  final StreamController<MessengerEvent> _events = StreamController<MessengerEvent>.broadcast();

  Stream<MessengerEvent> get events => _events.stream;

  void emit(MessengerEvent e) => _events.add(e);

  /// FCM-এর `data` আমাদের কিনা — হলে খবর, না হলে null
  static MessengerEvent? fromData(Map<String, dynamic> data) {
    if (data['open'] != 'messenger') return null;
    final type = '${data['type'] ?? ''}';
    if (type.isEmpty) return null;
    return MessengerEvent(type, {for (final e in data.entries) e.key: '${e.value}'});
  }
}
