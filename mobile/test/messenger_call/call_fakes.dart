import 'dart:async';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/features/messenger/call/call_device.dart';
import 'package:abos_mobile/features/messenger/call/call_engine.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../fakes/fake_messenger_api.dart';

/// নেটিভ WebRTC ছাড়া কলের যন্ত্র — পরীক্ষা ওপাশের মানুষ আনে/সরায় ([peerJoins], [peerLeaves])
class FakeCallEngine implements CallEngine {
  FakeCallEngine({this.peerAlreadyThere = false, this.failConnect = false});

  final bool peerAlreadyThere;
  final bool failConnect;
  final StreamController<CallEngineEvent> _events = StreamController<CallEngineEvent>.broadcast();
  final List<String> log = [];
  bool remoteVideoOn = false;

  @override
  Stream<CallEngineEvent> get events => _events.stream;

  @override
  Future<void> connect(String url, String token, {required bool video, required bool speaker}) async {
    log.add('connect:$url:$video:$speaker');
    if (failConnect) throw StateError('no network');
  }

  @override
  bool get peerPresent => peerAlreadyThere;

  void peerJoins() => _events.add(const CallEngineEvent(CallEngineEventType.peerJoined));

  void peerLeaves() => _events.add(const CallEngineEvent(CallEngineEventType.peerLeft));

  @override
  Future<void> setMic(bool on) async => log.add('mic:$on');

  @override
  Future<void> setCamera(bool on) async => log.add('camera:$on');

  @override
  Future<void> switchCamera() async => log.add('flip');

  @override
  Future<void> setSpeaker(bool on) async => log.add('speaker:$on');

  @override
  Widget? remoteVideo() => remoteVideoOn ? const ColoredBox(color: Color(0xFF335577)) : null;

  @override
  Widget? localVideo() => null;

  @override
  Future<void> leave() async => log.add('leave');
}

class FakeCallDevice implements CallDevice {
  FakeCallDevice({this.mic = true, this.camera = true});

  bool mic;
  bool camera;
  final List<String> log = [];

  @override
  Future<CallPermits> ask({required bool video}) async {
    log.add('ask:$video');
    return CallPermits(mic: mic, camera: camera);
  }

  @override
  Future<void> startOngoing({required String title, required String text, required bool video}) async => log.add('ongoing:$video');

  @override
  Future<void> stopOngoing() async => log.add('ongoing-off');

  @override
  Future<void> keepScreenOn(bool on) async => log.add('screen:$on');

  @override
  Future<void> ring(bool on) async => log.add('ring:$on');

  @override
  Future<void> showOverLock(bool on) async => log.add('lock:$on');

  @override
  Future<void> remindLater({required int id, required String title, required String body, required String payload, required Duration after}) async =>
      log.add('remind:${after.inMinutes}:$payload:$title');
}

/// কলের পাতা খোলার ছোট অ্যাপ — একটা বোতাম, চাপলে [open]
class CallHarness extends StatelessWidget {
  const CallHarness(
      {super.key, required this.api, required this.engine, required this.device, required this.open, this.theme, this.frame, this.home});

  final FakeMessengerApi api;
  final FakeCallEngine engine;
  final FakeCallDevice device;
  final Future<void> Function(BuildContext context) open;
  final ThemeData? theme;

  /// রূপের মোড়ক (Liquid Glass-এ কাচের পট) — অ্যাপের main.dart-এর মতো
  final TransitionBuilder? frame;

  /// বোতামের বদলে নিজের পাতা (যেমন গ্রুপের চ্যাটের মাথা)
  final Widget? home;

  @override
  Widget build(BuildContext context) => ProviderScope(
        overrides: [
          messengerApiProvider.overrideWithValue(api),
          callEngineFactoryProvider.overrideWithValue(() => engine),
          callDeviceProvider.overrideWithValue(device),
          callRingNoticeCancelProvider.overrideWithValue((id) async => device.log.add('notice-off:$id')),
        ],
        child: MaterialApp(
          debugShowCheckedModeBanner: false,
          theme: theme,
          locale: AppLocale.locale,
          supportedLocales: AppLocale.supported,
          localizationsDelegates: AppLocale.delegates,
          builder: frame,
          home: home ?? Scaffold(
            body: Builder(
              builder: (context) => Center(
                child: TextButton(key: const Key('open-call'), onPressed: () => open(context), child: const Text('open')),
              ),
            ),
          ),
        ),
      );
}
