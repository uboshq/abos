import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'livekit_call_engine.dart';

/// ⭐ কলের কথা-ছবির যন্ত্র — আসলটা অফিসের LiveKit ([LiveKitCallEngine]); পরীক্ষায় নকল (নেটিভ WebRTC ছাড়া)।
///
/// <p>পাতা ([CallController]) কেবল এটুকু জানে: ঢোকা, মাইক/ক্যামেরা/স্পিকার, ক্যামেরা ঘোরানো, ওপাশের মানুষ এল/গেল, আর
/// দুই ছবির উইজেট। কোন সার্ভার, কোন টোকেন — [CallJoin] থেকে আসে।
abstract interface class CallEngine {
  /// ওপাশের মানুষ এল/গেল, ছবি বদলাল, সংযোগ ছিঁড়ল
  Stream<CallEngineEvent> get events;

  /// ঘরে ঢোকা আর মাইক (ভিডিওতে ক্যামেরাও) চালু। ⛔ ব্যর্থ হলে ছোড়ে।
  Future<void> connect(String url, String token, {required bool video, required bool speaker});

  /// ঢোকার সময় ওপাশের মানুষ আগে থেকেই ঘরে আছেন কি
  bool get peerPresent;

  Future<void> setMic(bool on);

  Future<void> setCamera(bool on);

  Future<void> switchCamera();

  Future<void> setSpeaker(bool on);

  /// ওপাশের ভিডিও — এখনো আসেনি বা বন্ধ হলে null (তখন পাতা অবতার দেখায়)
  Widget? remoteVideo();

  /// আমার নিজের ক্যামেরার ছবি — বন্ধ হলে null
  Widget? localVideo();

  /// ঘর ছাড়া — একাধিকবার ডাকলেও ক্ষতি নেই
  Future<void> leave();
}

enum CallEngineEventType { peerJoined, peerLeft, videoChanged, disconnected }

@immutable
class CallEngineEvent {
  const CallEngineEvent(this.type);

  final CallEngineEventType type;
}

/// প্রতিটা কলে নতুন যন্ত্র — পরীক্ষা এটা বদলায়
final callEngineFactoryProvider = Provider<CallEngine Function()>((ref) => LiveKitCallEngine.new);
