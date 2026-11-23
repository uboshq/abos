import 'package:abos_mobile/core/messenger/messenger_alerts.dart';
import 'package:abos_mobile/core/messenger/messenger_push.dart';
import 'package:abos_mobile/core/push/push_service.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_messenger_api.dart';

class _Sink implements MessengerAlertSink {
  final List<String> log = [];

  @override
  Future<void> showCall(MessengerEvent e, {String caller = ''}) async => log.add('call:${e.callId}:$caller');

  @override
  Future<void> cancelCall(String? callId) async => log.add('cancel:$callId');

  @override
  Future<void> showChat(MessengerEvent e, {String? title, String? body}) async => log.add('chat:${e.threadId}:${title ?? ''}');
}

/// ⭐ বার্তা ও কলের push-এর সিদ্ধান্ত (সার্ভারের data: open=messenger, type chat|call|call_end, …) — সামনে আর পেছনে।
void main() {
  MessengerEvent ev(String type, Map<String, String> more) => MessengerPush.fromData({'open': 'messenger', 'type': type, ...more})!;

  tearDown(() => MessengerPush.instance.visibleThread.value = null);

  test('a chat push marks the message delivered and shows a notification that opens the chat', () async {
    final api = FakeMessengerApi(), sink = _Sink();
    await handleMessengerPush(ev('chat', {'thread_id': 't-rak', 'message_id': 'm7'}),
        foreground: true, title: 'রাকিব হাসান', api: api, alerts: sink);
    await Future<void>.delayed(Duration.zero);
    expect(api.calls, contains('delivered:t-rak=m7'));
    expect(sink.log, ['chat:t-rak:রাকিব হাসান']);
  });

  test('no notification for the chat that is open on screen', () async {
    final sink = _Sink();
    MessengerPush.instance.visibleThread.value = 't-rak';
    await handleMessengerPush(ev('chat', {'thread_id': 't-rak', 'message_id': 'm7'}), foreground: true, alerts: sink);
    expect(sink.log, isEmpty);
  });

  test('in the background a chat push with its own notification is left to Android; a data-only one is shown', () async {
    final sink = _Sink();
    await handleMessengerPush(ev('chat', {'thread_id': 't1'}), foreground: false, hasNotification: true, alerts: sink);
    expect(sink.log, isEmpty);
    await handleMessengerPush(ev('chat', {'thread_id': 't1'}), foreground: false, alerts: sink);
    expect(sink.log, ['chat:t1:']);
  });

  test('a failing delivered receipt never breaks the push', () async {
    final api = FakeMessengerApi(doorsMissing: true), sink = _Sink();
    await handleMessengerPush(ev('chat', {'thread_id': 't1', 'message_id': 'm1'}), foreground: true, api: api, alerts: sink);
    await Future<void>.delayed(Duration.zero);
    expect(sink.log, ['chat:t1:']);
  });

  test('a call push rings, and in the foreground opens the incoming screen', () async {
    final sink = _Sink(), opened = <String>[];
    await handleMessengerPush(ev('call', {'call_id': 'c9', 'video': 'true', 'from': '17', 'thread_id': 't-rak'}),
        foreground: true, alerts: sink, open: opened.add);
    expect(sink.log, ['call:c9:'], reason: 'from কেবল user id — নামের জায়গায় সংখ্যা নয়');
    expect(opened.single, startsWith('messenger:call?'));
    final t = MessengerPaths.parse(opened.single)! as CallTarget;
    expect((t.callId, t.video, t.from, t.threadId, t.action), ('c9', true, '17', 't-rak', ''));
  });

  test('the ring shows the caller from the push title (the server puts the caller name there)', () async {
    final sink = _Sink();
    await handleMessengerPush(ev('call', {'call_id': 'c9', 'video': '0', 'from': '17'}),
        foreground: false, hasNotification: true, title: 'রাকিব হাসান', alerts: sink);
    expect(sink.log, ['call:c9:রাকিব হাসান']);
  });

  test('the server\'s missed-call notice (type call, status missed) never rings; it stops a ring and opens the chat', () async {
    final sink = _Sink(), opened = <String>[];
    final missed = ev('call', {'call_id': 'c9', 'video': '0', 'from': '17', 'thread_id': 't-rak', 'status': 'missed'});
    await handleMessengerPush(missed, foreground: true, title: 'মিসড কল — রাকিব হাসান', alerts: sink, open: opened.add);
    expect(sink.log, ['cancel:c9']);
    expect(opened, isEmpty);
    expect(messengerShowsItself(missed), isFalse, reason: 'সাধারণ নোটিশের পথে দেখায়');
    expect(destinationOf(missed.data), 'messenger:chat?thread=t-rak');
  });

  test('call_end cancels the ring, whatever the status', () async {
    final sink = _Sink();
    for (final s in ['answered', 'declined', 'missed', 'ended']) {
      await handleMessengerPush(ev('call_end', {'call_id': 'c9', 'status': s}), foreground: false, alerts: sink);
    }
    expect(sink.log, List.filled(4, 'cancel:c9'));
  });

  test('chat and call show their own notification; other messenger pushes go the ordinary notice way', () {
    expect(messengerShowsItself(ev('chat', {})), isTrue);
    expect(messengerShowsItself(ev('call', {})), isTrue);
    expect(messengerShowsItself(ev('call_end', {})), isFalse);
  });

  test('paths: a tap on a messenger notification finds its chat or call, and the call buttons ride along', () {
    expect(destinationOf({'open': 'messenger', 'type': 'chat', 'thread_id': 't 1'}), 'messenger:chat?thread=t+1');
    expect((MessengerPaths.parse('messenger:chat?thread=t+1')! as ChatTarget).threadId, 't 1');
    expect(destinationOf({'open': 'messenger', 'type': 'call_end', 'thread_id': 't1', 'status': 'missed'}), 'messenger:chat?thread=t1');
    expect(destinationOf({'open': 'messenger', 'type': 'call_end'}), isNull);
    final p = MessengerPaths.withAction('messenger:call?id=c9&video=0', callActionAnswer)!;
    expect((MessengerPaths.parse(p)! as CallTarget).action, 'answer');
    expect(MessengerPaths.withAction('/home/notifications', 'x'), '/home/notifications');
    expect(MessengerPaths.parse('/home/today'), isNull);
    expect(destinationOf({'open': 'tracking'}), '/home/tracking', reason: 'আগের পথ অটুট');
  });

  test('the caller name on the ring is the server name, never a bare user id', () {
    expect(callerNameOf(ev('call', {'from': '17'})), '');
    expect(callerNameOf(ev('call', {'from': '17', 'from_name': 'রাকিব হাসান'})), 'রাকিব হাসান');
  });
}
