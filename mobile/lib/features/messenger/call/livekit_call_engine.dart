import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:livekit_client/livekit_client.dart';

import 'call_engine.dart';

/// ⭐ অফিসের নিজের LiveKit সার্ভারে (Mac mini) একজনের সাথে কল — কথা কোনো বাইরের সেবায় যায় না (নমুনা 3nuwRUdn v5 "ঘ")।
///
/// <p>প্রতিটা কল একটা ঘর; সার্ভার টোকেন দেয় ([CallJoin]), এখানে ঢোকা, মাইক/ক্যামেরা পাঠানো আর ওপাশের ছবি দেখানো।
/// ⓘ ভয়েস কলে কানের স্পিকার, ভিডিওতে বড় স্পিকার — মানুষ বোতামে বদলাতে পারেন।
class LiveKitCallEngine implements CallEngine {
  LiveKitCallEngine();

  Room? _room;
  EventsListener<RoomEvent>? _listener;
  final StreamController<CallEngineEvent> _events = StreamController<CallEngineEvent>.broadcast();
  CameraPosition _position = CameraPosition.front;
  bool _left = false;

  @override
  Stream<CallEngineEvent> get events => _events.stream;

  void _emit(CallEngineEventType t) {
    if (!_events.isClosed) _events.add(CallEngineEvent(t));
  }

  @override
  Future<void> connect(String url, String token, {required bool video, required bool speaker}) async {
    final room = Room(
      roomOptions: RoomOptions(
        adaptiveStream: true,
        dynacast: true,
        defaultAudioOutputOptions: AudioOutputOptions(speakerOn: speaker),
        defaultCameraCaptureOptions: const CameraCaptureOptions(cameraPosition: CameraPosition.front),
      ),
    );
    _room = room;
    final listener = room.createListener();
    _listener = listener;
    listener
      ..on<ParticipantConnectedEvent>((_) => _emit(CallEngineEventType.peerJoined))
      ..on<ParticipantDisconnectedEvent>((_) {
        if (room.remoteParticipants.isEmpty) _emit(CallEngineEventType.peerLeft);
      })
      ..on<TrackSubscribedEvent>((_) => _emit(CallEngineEventType.videoChanged))
      ..on<TrackUnsubscribedEvent>((_) => _emit(CallEngineEventType.videoChanged))
      ..on<TrackMutedEvent>((_) => _emit(CallEngineEventType.videoChanged))
      ..on<TrackUnmutedEvent>((_) => _emit(CallEngineEventType.videoChanged))
      ..on<LocalTrackPublishedEvent>((_) => _emit(CallEngineEventType.videoChanged))
      ..on<RoomDisconnectedEvent>((_) {
        if (!_left) _emit(CallEngineEventType.disconnected);
      });
    await room.connect(url, token);
    await room.localParticipant?.setMicrophoneEnabled(true);
    if (video) await room.localParticipant?.setCameraEnabled(true);
  }

  @override
  bool get peerPresent => _room?.remoteParticipants.isNotEmpty ?? false;

  @override
  Future<void> setMic(bool on) async => _room?.localParticipant?.setMicrophoneEnabled(on);

  @override
  Future<void> setCamera(bool on) async {
    await _room?.localParticipant?.setCameraEnabled(on, cameraCaptureOptions: CameraCaptureOptions(cameraPosition: _position));
    _emit(CallEngineEventType.videoChanged);
  }

  @override
  Future<void> switchCamera() async {
    final track = _localTrack();
    if (track == null) return;
    _position = _position.switched();
    await track.setCameraPosition(_position);
    _emit(CallEngineEventType.videoChanged);
  }

  @override
  Future<void> setSpeaker(bool on) => AudioManager.instance.setSpeakerOutputPreferred(on);

  LocalVideoTrack? _localTrack() {
    for (final p in _room?.localParticipant?.videoTrackPublications ?? const <LocalTrackPublication<LocalVideoTrack>>[]) {
      final t = p.track;
      if (t != null && !p.muted) return t;
    }
    return null;
  }

  @override
  Widget? remoteVideo() {
    for (final person in _room?.remoteParticipants.values ?? const <RemoteParticipant>[]) {
      for (final p in person.videoTrackPublications) {
        final t = p.track;
        if (t != null && p.subscribed && !p.muted) return VideoTrackRenderer(t, fit: VideoViewFit.cover);
      }
    }
    return null;
  }

  @override
  Widget? localVideo() {
    final t = _localTrack();
    return t == null ? null : VideoTrackRenderer(t, fit: VideoViewFit.cover);
  }

  @override
  Future<void> leave() async {
    if (_left) return;
    _left = true;
    final room = _room;
    _room = null;
    try {
      await _listener?.dispose();
      await room?.disconnect();
      await room?.dispose();
    } catch (_) {
      // ⓘ ছাড়ার সময়ের ভুল কাউকে থামায় না
    }
    await _events.close();
  }
}
