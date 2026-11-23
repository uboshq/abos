import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_alerts.dart';
import '../../../core/messenger/messenger_api.dart';
import '../chat/chat_screen.dart';
import 'call_controller.dart';
import 'call_device.dart';
import 'call_engine.dart';
import 'call_screen.dart';

/// ⭐ একজনের সাথে ভয়েস/ভিডিও কল (অফিসের LiveKit) — নমুনা 3nuwRUdn v5 "ঘ"।
///
/// <p>দেওয়া কল: চ্যাটের মাথা আর "আমি" পাতা [start] ডাকে। আসা কল: push → নোটিফিকেশন/পাতা → [incoming]
/// ([openMessengerPath] থেকে)। ⓘ একসাথে একটাই কল — চলতি কলের সময় নতুন আসা কল বাজে না (সার্ভার "উত্তর দেননি" করে)।
class Calls {
  const Calls._();

  static CallController? _active;

  /// এখন কোনো কল চলছে কি
  static bool get busy => _active != null && !_active!.isOver;

  /// পরীক্ষা: আগের পরীক্ষার ঝুলে থাকা কল ভুলে যাওয়া
  @visibleForTesting
  static void forgetActiveCall() => _active = null;

  /// চ্যাটের মাথা আর "আমি" পাতার কল/ভিডিও বোতাম এটাই ডাকে
  static Future<void> start(BuildContext context, ChatPerson person, {required bool video}) async {
    if (busy) return;
    final c = _controller(context, person: person, video: video);
    unawaited(c.startOutgoing());
    await _show(context, c);
  }

  /// ⭐ কথোপকথনের মাথার কল/ভিডিও বোতাম — একজনের সাথে হলে [start], গ্রুপে "শীঘ্রই" ([groupSoon])
  static Future<void> forThread(BuildContext context, ChatThread thread, {required bool video}) async {
    final person = thread.person;
    if (thread.kind == ThreadKind.group || person == null) {
      groupSoon(context, video: video);
      return;
    }
    await start(context, person, video: video);
  }

  /// ⭐ গ্রুপ কল আর ভিডিও সভা দ্বিতীয় ধাপে — নমুনার ছোট বার্তা (ঘড়ির চিহ্ন, "শীঘ্রই আসছে — …"), দুই সেকেন্ড
  static void groupSoon(BuildContext context, {required bool video}) {
    final l = context.l10n;
    final messenger = ScaffoldMessenger.maybeOf(context);
    if (messenger == null) return;
    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        key: const Key('call-group-soon'),
        duration: const Duration(seconds: 2),
        behavior: SnackBarBehavior.floating,
        content: Row(
          children: [
            const Icon(Symbols.schedule, size: 18, color: Colors.white), // dark-ok: ছোট বার্তার পট সবসময় গাঢ়
            const SizedBox(width: 8),
            Expanded(child: Text(video ? l.msgCallGroupVideoSoon : l.msgCallGroupSoon)),
          ],
        ),
      ));
  }

  /// আসা কলের পাতা — [CallTarget.action] answer হলে সাথে সাথে ধরে, decline হলে পাতা ছাড়াই কেটে দেয়
  static Future<void> incoming(BuildContext context, CallTarget target) async {
    final container = ProviderScope.containerOf(context, listen: false);
    final api = container.read(messengerApiProvider);
    if (target.action == callActionDecline) {
      unawaited(container.read(callRingNoticeCancelProvider)(target.callId));
      try {
        await api.declineCall(target.callId);
      } catch (_) {}
      return;
    }
    if (busy) return;
    final c = _controller(
      context,
      person: ChatPerson(id: target.from, name: ''),
      video: target.video,
      threadId: target.threadId,
      incomingCallId: target.callId,
    );
    unawaited(c.startRinging());
    unawaited(_findCaller(api, c, target.from));
    if (target.action == callActionAnswer) unawaited(c.answer());
    await _show(context, c);
  }

  static CallController _controller(BuildContext context,
      {required ChatPerson person, required bool video, String threadId = '', String? incomingCallId}) {
    final container = ProviderScope.containerOf(context, listen: false);
    final c = CallController(
      api: container.read(messengerApiProvider),
      engine: container.read(callEngineFactoryProvider),
      device: container.read(callDeviceProvider),
      person: person,
      video: video,
      ongoingText: context.l10n.msgCallOngoing,
      incomingCallId: incomingCallId,
      threadId: threadId,
      cancelRingNotice: container.read(callRingNoticeCancelProvider),
    );
    _active = c;
    return c;
  }

  /// আসা কলে push কেবল user id দেয় — নাম, পদ আর ছবি "যাঁদের বার্তা দেওয়া যায়" তালিকা থেকে
  static Future<void> _findCaller(MessengerApi api, CallController c, String id) async {
    if (id.isEmpty) return;
    try {
      final people = await api.people();
      for (final p in people) {
        if (p.id == id) return c.updateCaller(p);
      }
    } catch (_) {}
  }

  static Future<void> _show(BuildContext context, CallController c) async {
    final container = ProviderScope.containerOf(context, listen: false);
    await Navigator.of(context, rootNavigator: true).push(MaterialPageRoute<void>(
      fullscreenDialog: true,
      builder: (_) => CallScreen(
        controller: c,
        onMessage: (ctx) => _message(ctx, container, c),
        onRemindLater: (ctx) => _remindLater(ctx, container, c),
      ),
    ));
    if (identical(_active, c)) _active = null;
    c.dispose();
  }

  /// কোন কথোপকথন — push-এ এলে সেটা, নইলে সার্ভার থেকে (একজনের সাথের)
  static Future<String> _threadOf(ProviderContainer container, CallController c) async {
    if (c.threadId.isNotEmpty) return c.threadId;
    try {
      final t = await container.read(messengerApiProvider).direct(c.person.id);
      c.threadId = t.id;
    } catch (_) {}
    return c.threadId;
  }

  /// "বার্তা দিন" — কল কেটে সেই মানুষের চ্যাট
  static Future<void> _message(BuildContext context, ProviderContainer container, CallController c) async {
    final navigator = Navigator.of(context, rootNavigator: true);
    final threadFuture = _threadOf(container, c);
    await c.decline();
    final thread = await threadFuture;
    final ctx = navigator.context;
    if (thread.isNotEmpty && ctx.mounted) await openChatById(ctx, thread);
  }

  /// "পরে মনে করান" — কল কেটে ১০ মিনিট পরে একটা বার্তা, চাপলে চ্যাট
  static Future<void> _remindLater(BuildContext context, ProviderContainer container, CallController c) async {
    final l = context.l10n;
    final messenger = ScaffoldMessenger.maybeOf(context);
    final threadFuture = _threadOf(container, c);
    await c.decline();
    final thread = await threadFuture;
    final id = c.callId ?? c.person.id;
    await container.read(callDeviceProvider).remindLater(
          id: ('remind:$id').hashCode & 0x7fffffff,
          title: l.msgCallReminderTitle(c.person.name),
          body: l.msgCallReminderBody,
          payload: thread.isEmpty ? '' : MessengerPaths.chat(thread),
          after: const Duration(minutes: 10),
        );
    messenger?.showSnackBar(SnackBar(content: Text(l.msgCallReminderSet)));
  }
}

/// ⭐ নোটিফিকেশন চেপে আসা `messenger:` পথ — চ্যাট বা আসা কল। true = আমাদের পথ (খোলা হলো বা চুপচাপ বাদ)।
/// ⓘ main.dart ডাকে, অ্যাপ-তালা খোলার পরে।
bool openMessengerPath(BuildContext context, String path) {
  final target = MessengerPaths.parse(path);
  if (target == null) return MessengerPaths.isOurs(path);
  switch (target) {
    case ChatTarget(:final threadId):
      unawaited(openChatById(context, threadId));
    case CallTarget():
      unawaited(Calls.incoming(context, target));
  }
  return true;
}
