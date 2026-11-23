import 'package:abos_mobile/core/messenger/messenger_alerts.dart';
import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/messenger/messenger_push.dart';
import 'package:abos_mobile/features/messenger/call/call_screen.dart';
import 'package:abos_mobile/features/messenger/call/calls.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_messenger_api.dart';
import 'call_fakes.dart';

class _RefusingApi extends FakeMessengerApi {
  _RefusingApi(this.message);

  final String message;

  @override
  Future<CallJoin> startCall(String personId, {required bool video}) async => throw MessengerNotAllowed(message);
}

/// ⭐ কলের পথ, নকল যন্ত্রে (নমুনা 3nuwRUdn v5 "ঘ"): দেওয়া কল রিং → কথা (ঘড়ি) → কাটা; ৪৫ সেকেন্ডে "উত্তর দেননি";
/// ওপাশে কেটে দিলে (call_end push); আসা কল ধরা/কেটে দেওয়া; মাইকের অনুমতি না দিলে বাংলা ব্যাখ্যা; সার্ভারে কল বন্ধ।
void main() {
  late FakeMessengerApi api;
  late FakeCallEngine engine;
  late FakeCallDevice device;

  setUp(() {
    api = FakeMessengerApi();
    engine = FakeCallEngine();
    device = FakeCallDevice();
    Calls.forgetActiveCall();
  });

  /// ফোনের মাপে (৪১২×৯০০) পাতা খোলা; পাতা ঢোকার চলন (৪০০ ms-এর কম) শেষ হওয়া পর্যন্ত
  Future<void> openWith(WidgetTester tester, Future<void> Function(BuildContext) open) async {
    tester.view.physicalSize = const Size(412, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(CallHarness(api: api, engine: engine, device: device, open: open));
    await tester.tap(find.byKey(const Key('open-call')));
    await tester.pump(Duration.zero);
    await tester.pump(Duration.zero);
    await tester.pump(const Duration(milliseconds: 600));
  }

  String status(WidgetTester tester) => tester.widget<Text>(find.byKey(const Key('call-status'))).data!;

  testWidgets('outgoing voice: rings, the clock runs once they join, my end button ends it on the server', (tester) async {
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    expect(api.calls, contains('startCall:rak:false'));
    expect(find.text('রাকিব হাসান'), findsOneWidget);
    expect(find.text('হিসাবরক্ষক · TD শাখা'), findsOneWidget);
    expect(status(tester), 'রিং হচ্ছে…');
    expect(engine.log.first, 'connect:wss://example.invalid:false:false');
    expect(device.log, containsAll(<String>['ask:false', 'screen:true', 'ongoing:false']));

    engine.peerJoins();
    await tester.pump(Duration.zero);
    expect(status(tester), '00:00');
    await tester.pump(const Duration(seconds: 3));
    expect(status(tester), '00:03');

    await tester.tap(find.byKey(const Key('call-mute')));
    await tester.pump(Duration.zero);
    expect(engine.log, contains('mic:false'));

    await tester.tap(find.byKey(const Key('call-end')));
    await tester.pump(Duration.zero);
    expect(api.calls, contains('endCall:c1'));
    expect(status(tester), 'কল শেষ');
    expect(engine.log, contains('leave'));
    expect(device.log, containsAll(<String>['ongoing-off', 'screen:false']));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(find.byType(CallScreen), findsNothing);
  });

  testWidgets('nobody answers in 45 seconds: "উত্তর দেননি", and the server hears the end', (tester) async {
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    await tester.pump(const Duration(seconds: 44));
    expect(status(tester), 'রিং হচ্ছে…');
    await tester.pump(const Duration(seconds: 1));
    expect(status(tester), 'উত্তর দেননি');
    expect(api.calls, contains('endCall:c1'));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(find.byType(CallScreen), findsNothing);
  });

  testWidgets('a call_end push with status declined ends it with "কেটে দিয়েছেন"', (tester) async {
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    MessengerPush.instance.emit(const MessengerEvent('call_end', {'call_id': 'other', 'status': 'declined'}));
    await tester.pump(Duration.zero);
    expect(status(tester), 'রিং হচ্ছে…', reason: 'অন্য কলের খবর এই কলকে থামায় না');
    MessengerPush.instance.emit(const MessengerEvent('call_end', {'call_id': 'c1', 'status': 'declined'}));
    await tester.pump(Duration.zero);
    expect(status(tester), 'কেটে দিয়েছেন');
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('the other person leaving ends a running call', (tester) async {
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: true));
    engine.peerJoins();
    await tester.pump(Duration.zero);
    expect(find.byKey(const Key('call-self-view')), findsOneWidget);
    expect(find.text('আপনি'), findsOneWidget);
    engine.peerLeaves();
    await tester.pump(Duration.zero);
    expect(status(tester), 'কল শেষ');
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('incoming: rings, shows the caller from the people list, answer joins the call', (tester) async {
    engine = FakeCallEngine(peerAlreadyThere: true);
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak', threadId: 't-rak')));
    await tester.pump(Duration.zero);
    expect(device.log, contains('ring:true'));
    expect(find.text('রাকিব হাসান'), findsOneWidget);
    expect(status(tester), 'ABOS ভয়েস কল আসছে…');
    expect(find.text('বার্তা দিন'), findsOneWidget);
    expect(find.text('পরে মনে করান'), findsOneWidget);

    await tester.tap(find.byKey(const Key('call-answer')));
    await tester.pump(Duration.zero);
    await tester.pump(Duration.zero);
    expect(api.calls, contains('answerCall:c9'));
    expect(device.log, contains('ring:false'));
    expect(status(tester), '00:00');
    await tester.tap(find.byKey(const Key('call-end')));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('incoming: decline tells the server and closes at once', (tester) async {
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: true, from: 'rak')));
    expect(status(tester), 'ABOS ভিডিও কল আসছে…');
    await tester.tap(find.byKey(const Key('call-decline')));
    await tester.pumpAndSettle();
    expect(api.calls, contains('declineCall:c9'));
    expect(find.byType(CallScreen), findsNothing);
  });

  testWidgets('incoming: remind later declines and sets a 10-minute reminder that opens the chat', (tester) async {
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak', threadId: 't-rak')));
    await tester.pump(Duration.zero);
    await tester.tap(find.byKey(const Key('call-remind')));
    await tester.pumpAndSettle();
    expect(api.calls, contains('declineCall:c9'));
    expect(device.log.where((l) => l.startsWith('remind:10:messenger:chat?thread=t-rak')), hasLength(1));
    expect(find.text('10 মিনিট পরে মনে করানো হবে'), findsOneWidget);
  });

  testWidgets('a call_end push stops an incoming ring', (tester) async {
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak')));
    MessengerPush.instance.emit(const MessengerEvent('call_end', {'call_id': 'c9', 'status': 'missed'}));
    await tester.pump(Duration.zero);
    expect(device.log, contains('ring:false'));
    expect(status(tester), 'কল শেষ');
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(find.byType(CallScreen), findsNothing);
  });

  testWidgets('answered on another of my phones: this phone stops ringing and closes; the answering phone ignores it', (tester) async {
    engine = FakeCallEngine(peerAlreadyThere: true);
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak')));
    await tester.tap(find.byKey(const Key('call-answer')));
    await tester.pump(Duration.zero);
    await tester.pump(Duration.zero);
    expect(status(tester), '00:00');
    // ⓘ সার্ভার answered পাঠায় ধরা ফোনেও — এই ফোন কলে আছে, তাই কিছুই নয়
    MessengerPush.instance.emit(const MessengerEvent('call_end', {'call_id': 'c9', 'status': 'answered'}));
    await tester.pump(const Duration(seconds: 1));
    expect(status(tester), '00:01');
    await tester.tap(find.byKey(const Key('call-end')));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('answered elsewhere while this phone still rings: the ring stops and the page closes at once', (tester) async {
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak')));
    MessengerPush.instance.emit(const MessengerEvent('call_end', {'call_id': 'c9', 'status': 'answered'}));
    await tester.pump(Duration.zero);
    await tester.pumpAndSettle();
    expect(device.log, contains('ring:false'));
    expect(find.byType(CallScreen), findsNothing);
    expect(api.calls.where((c) => c.startsWith('declineCall')), isEmpty, reason: 'অন্য ফোনে ধরা কল কাটা নয়');
  });

  testWidgets('answering a call that stopped ringing (409 call_over): a calm sentence, then closed', (tester) async {
    api = FakeMessengerApi(callOver: true);
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak')));
    await tester.tap(find.byKey(const Key('call-answer')));
    await tester.pump(Duration.zero);
    await tester.pump(Duration.zero);
    expect(status(tester), 'কল শেষ হয়ে গেছে — আর ধরা যাবে না');
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(find.byType(CallScreen), findsNothing);
  });

  testWidgets('a missed call_end on the caller says "উত্তর দেননি"', (tester) async {
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    MessengerPush.instance.emit(const MessengerEvent('call_end', {'call_id': 'c1', 'status': 'missed'}));
    await tester.pump(Duration.zero);
    expect(status(tester), 'উত্তর দেননি');
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('microphone refused: a Bengali explanation, no call is made', (tester) async {
    device = FakeCallDevice(mic: false);
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    await tester.pump(Duration.zero);
    expect(find.byKey(const Key('call-mic-refused')), findsOneWidget);
    expect(find.textContaining('মাইকের অনুমতি ছাড়া কল হয় না'), findsOneWidget);
    expect(api.calls.where((c) => c.startsWith('startCall')), isEmpty);
    await tester.tap(find.text('ঠিক আছে'));
    await tester.pumpAndSettle();
    expect(find.byType(CallScreen), findsNothing);
  });

  testWidgets('camera refused on a video call: goes on as a voice call and says so', (tester) async {
    device = FakeCallDevice(camera: false);
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: true));
    expect(api.calls, contains('startCall:rak:false'));
    expect(find.text('ক্যামেরার অনুমতি নেই — ভিডিও ছাড়া, কেবল কথায় কল চলছে'), findsOneWidget);
    await tester.tap(find.byKey(const Key('call-end')));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('calls not switched on at the server: a calm sentence, then the page closes', (tester) async {
    api = FakeMessengerApi(callsOff: true);
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    expect(status(tester), 'কল এখনো চালু হয়নি — অফিস চালু করলে কল দেওয়া যাবে');
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(find.byType(CallScreen), findsNothing);
  });

  testWidgets('a voice call turns into a video call from the video button', (tester) async {
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    engine.peerJoins();
    await tester.pump(Duration.zero);
    expect(find.byKey(const Key('call-voice-bar')), findsOneWidget);
    await tester.tap(find.byKey(const Key('call-to-video')));
    await tester.pump(Duration.zero);
    await tester.pump(Duration.zero);
    expect(engine.log, contains('camera:true'));
    expect(find.byKey(const Key('call-video-bar')), findsOneWidget);
    await tester.tap(find.byKey(const Key('call-end')));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('forThread: a direct chat calls its person; a group says শীঘ্রই and calls nobody', (tester) async {
    const group = ChatThread(id: 't-acc', kind: ThreadKind.group, title: 'হিসাব বিভাগ');
    await openWith(tester, (c) => Calls.forThread(c, group, video: true));
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text('শীঘ্রই আসছে — গ্রুপ ভিডিও সভা দ্বিতীয় ধাপে'), findsOneWidget);
    expect(api.calls.where((c) => c.startsWith('startCall')), isEmpty);
    expect(find.byType(CallScreen), findsNothing);
    await tester.pump(const Duration(seconds: 3));
    await tester.pumpAndSettle();

    const direct = ChatThread(id: 't-rak', kind: ThreadKind.direct, title: 'রাকিব হাসান', person: FakeMessengerApi.rakib);
    await tester.pumpWidget(CallHarness(api: api, engine: engine, device: device, open: (c) => Calls.forThread(c, direct, video: false)));
    await tester.tap(find.byKey(const Key('open-call')));
    await tester.pump(Duration.zero);
    await tester.pump(Duration.zero);
    await tester.pump(const Duration(milliseconds: 600));
    expect(api.calls, contains('startCall:rak:false'));
    await tester.tap(find.byKey(const Key('call-end')));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('a 403 shows the server\'s own sentence', (tester) async {
    api = _RefusingApi('রাকিবকে কল দেওয়ার অনুমতি আপনার রোলে নেই');
    await openWith(tester, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
    expect(status(tester), 'রাকিবকে কল দেওয়ার অনুমতি আপনার রোলে নেই');
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });

  testWidgets('every call button is 48dp or larger and says what it does; 200% text does not overflow', (tester) async {
    tester.view.physicalSize = const Size(412, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    tester.platformDispatcher.textScaleFactorTestValue = 2;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
    await openWith(tester, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak')));
    await tester.pump(Duration.zero);
    for (final k in ['call-message', 'call-remind', 'call-decline', 'call-answer']) {
      final size = tester.getSize(find.byKey(Key(k)));
      expect(size.width, greaterThanOrEqualTo(48), reason: k);
      expect(find.descendant(of: find.byKey(Key(k)), matching: find.byType(Tooltip)), findsOneWidget, reason: k);
    }
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const Key('call-answer')));
    await tester.pump(Duration.zero);
    await tester.pump(Duration.zero);
    engine.peerJoins();
    await tester.pump(Duration.zero);
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const Key('call-end')));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  });
}
