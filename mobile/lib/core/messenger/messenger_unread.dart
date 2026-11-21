import 'package:flutter/foundation.dart';

import 'messenger_api.dart';

/// ⭐ উপরের বারের বার্তার গোনা (নমুনা 3nuwRUdn v5-এর লাল "8") — হোম আর বার্তার তালিকা লেখে, প্রতিটা উপরের বার পড়ে;
/// null = জানা নেই বা বার্তা চালু নেই (বিন্দু নেই)।
class MessengerUnread {
  MessengerUnread._();

  static final ValueNotifier<int?> count = ValueNotifier<int?>(null);

  /// সার্ভার থেকে নতুন গোনা — ⓘ না-পড়ার ছাঁকনিতে (ছোট উত্তর)। দরজা নেই বা অনুমতি নেই → null; সিগন্যাল নেই → আগের গোনা থাকে
  static Future<void> refresh(MessengerApi api) async {
    try {
      count.value = (await api.threads(filter: ThreadFilter.unread)).unreadTotal;
    } on MessengerDoorsMissing {
      count.value = null;
    } on MessengerNotAllowed {
      count.value = null;
    } catch (_) {
      // ⓘ সিগন্যাল নেই — আগের গোনা থাকে
    }
  }
}
