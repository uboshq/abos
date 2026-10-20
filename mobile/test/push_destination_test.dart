import 'package:abos_mobile/core/push/push_service.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ বার্তায় চাপলে কোথায় — সার্ভার পাঠায় {open: tracking, kind, id} ([[TrackingNotices]])।
void main() {
  test('a tracking push opens delivery tracking', () {
    expect(destinationOf({'open': 'tracking', 'kind': 'challan', 'id': 'x'}), '/home/tracking');
  });

  // ⭐ সাধারণ নোটিফিকেশন — সার্ভার পাঠায় {open: notification, id: <public_id>} ([[MobilePushChannel]]); আগে চাপলে কিছুই হত না
  test('a notification push opens that very notification, or the list without an id', () {
    expect(destinationOf({'open': 'notification', 'id': '01J9-abc'}), '/home/notifications?open=01J9-abc');
    expect(destinationOf({'open': 'notification', 'id': ''}), '/home/notifications');
    expect(destinationOf({'open': 'notification'}), '/home/notifications');
  });

  test('anything unknown opens nothing new', () {
    expect(destinationOf({'open': 'somewhere'}), isNull);
    expect(destinationOf(null), isNull);
    expect(destinationOf(const {}), isNull);
  });

  // ⭐ রিয়েল-টাইম সিঙ্ক — সার্ভারের নীরব ডাক কেবল `{abos: sync}` ([[SyncNudge::DATA]])
  test('only the silent sync nudge of the server is recognised', () {
    expect(isSyncNudge({'abos': 'sync'}), isTrue);
    expect(isSyncNudge({'open': 'tracking'}), isFalse);
    expect(isSyncNudge({'abos': 'other'}), isFalse);
    expect(isSyncNudge(null), isFalse);
  });

  test('a sync nudge opens no screen', () {
    expect(destinationOf({'abos': 'sync'}), isNull);
  });
}
