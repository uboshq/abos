import 'package:abos_mobile/core/push/local_alerts.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ সামনে খোলা (বা তালাবদ্ধ) অ্যাপেও বার্তা দেখা যায় (মালিক, ২০ নভেম্বর ২০২৬) — নীরব সিঙ্ক-ডাক নয়, লেখাসহ বার্তা হ্যাঁ,
/// আর চাপলে সেই কাগজটাই ([destinationOf])।
class _FakeShower implements AlertShower {
  final List<Map<String, Object?>> shown = [];

  @override
  Future<void> show({required int id, required String title, required String body, String? payload}) async {
    shown.add({'id': id, 'title': title, 'body': body, 'payload': payload});
  }
}

class _BrokenShower implements AlertShower {
  @override
  Future<void> show({required int id, required String title, required String body, String? payload}) =>
      Future.error(StateError('no plugin'));
}

void main() {
  test('the silent sync nudge shows nothing, even if it carried words', () async {
    final shower = _FakeShower();
    final shown = await showInForeground(
      const RemoteMessage(
        messageId: 'm1',
        data: {'abos': 'sync'},
        notification: RemoteNotification(title: 'x', body: 'y'),
      ),
      shower,
    );
    expect(shown, isFalse);
    expect(shower.shown, isEmpty);
  });

  test('a data-only message (no words) shows nothing', () async {
    final shower = _FakeShower();
    expect(await showInForeground(const RemoteMessage(data: {'open': 'notification', 'id': 'a'}), shower), isFalse);
    expect(
      await showInForeground(
          const RemoteMessage(notification: RemoteNotification(title: '  ', body: '')), shower),
      isFalse,
    );
    expect(shower.shown, isEmpty);
  });

  test('a notification is shown with its words and the path of that exact item', () async {
    final shower = _FakeShower();
    final shown = await showInForeground(
      const RemoteMessage(
        messageId: 'm2',
        data: {'open': 'notification', 'id': '01J9-abc'},
        notification: RemoteNotification(title: 'অনুমোদন চাই', body: 'জমা ৳৫,০০০'),
      ),
      shower,
    );
    expect(shown, isTrue);
    expect(shower.shown, hasLength(1));
    expect(shower.shown.single['title'], 'অনুমোদন চাই');
    expect(shower.shown.single['body'], 'জমা ৳৫,০০০');
    expect(shower.shown.single['payload'], '/home/notifications?open=01J9-abc');
  });

  test('a notification with an unknown destination is still shown, opening nothing new', () async {
    final shower = _FakeShower();
    expect(
      await showInForeground(
          const RemoteMessage(notification: RemoteNotification(title: 'কল', body: 'রহিম'), data: {}), shower),
      isTrue,
    );
    expect(shower.shown.single['payload'], isNull);
  });

  test('the same message id gives the same row; a broken plugin never throws', () async {
    const m = RemoteMessage(messageId: 'same', notification: RemoteNotification(title: 't'));
    expect(alertIdOf(m), alertIdOf(m));
    expect(alertIdOf(m), greaterThanOrEqualTo(0));
    expect(await showInForeground(m, _BrokenShower()), isFalse);
  });

  test('a tap opens its path; a tap before the opener is set waits for it', () {
    final alerts = LocalAlerts.forTest();
    alerts.handleTap('/home/tracking');
    final opened = <String>[];
    alerts.onTap(opened.add);
    expect(opened, ['/home/tracking']);
    alerts.handleTap('/home/notifications?open=x');
    alerts.handleTap(null);
    alerts.handleTap('');
    expect(opened, ['/home/tracking', '/home/notifications?open=x']);
  });
}
