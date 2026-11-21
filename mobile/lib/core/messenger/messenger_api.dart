import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client/api_client.dart';
import '../api_client/once_key.dart';

/// ⭐ বার্তা ও কল, প্রথম ধাপ (মালিক "ok", ২১ নভেম্বর ২০২৬; নমুনা 3nuwRUdn v5) — অ্যাপের দিকের চুক্তি।
///
/// <p>সার্ভারের দিক abos-63 (abos-a1) বানাচ্ছে; আসল চুক্তি এলে এই ফাইলই মেলানো হবে। ততদিন পথগুলো `/api/v1/messenger/…`-এর
/// নিচে ধরা আছে, আর পরীক্ষা চলে `test/fakes/fake_messenger_api.dart`-এ।
/// <ul>
///   <li>`GET  /messenger/threads?filter=all|unread|groups|papers&q=` → `{data:[thread…], unread_total}`</li>
///   <li>`GET  /messenger/threads/{id}/messages?before=&after=` → `{data:[message…]}` (পুরনো→নতুন)</li>
///   <li>`POST /messenger/threads/{id}/messages` (json: text · paper · location; multipart: image · file · voice) → message</li>
///   <li>`POST /messenger/threads/{id}/read` (`up_to`) · `POST /messenger/threads/{id}/mute` (`muted`)</li>
///   <li>`GET  /messenger/threads/{id}/members` · `GET /messenger/threads/{id}/media`</li>
///   <li>`GET  /messenger/people?q=` → যাঁদের বার্তা দেওয়া যায় (রোল দিয়ে ঠিক), প্রতিজনের `can_open_paper` (কাগজ পাঠানোর সময়)</li>
///   <li>`POST /messenger/direct` (`person`) → একজনের সাথের কথোপকথন (না থাকলে বানায়)</li>
///   <li>`POST /messenger/papers` (`paper_type`, `paper_id`, `to[]`, `note`) → কাগজ কয়েকজন/গ্রুপে একসাথে</li>
///   <li>`POST /messenger/calls` (`person`, `video`) → {call, livekit url, token}; `POST /messenger/calls/{id}/answer|decline|end`</li>
/// </ul>
/// <p>⛔ পুরনো সার্ভারে দরজা নেই (৪০৪/৪০৫) → [MessengerDoorsMissing]; পাতা তখন "এখনো চালু হয়নি" দেখায়, ভাঙে না।
abstract interface class MessengerApi {
  Future<ThreadPage> threads({ThreadFilter filter = ThreadFilter.all, String query = ''});

  /// পুরনো→নতুন। [before] দিলে তার আগের পাতা; [after] দিলে তার পরের নতুনগুলো (খোলা চ্যাটের ৩–৫ সেকেন্ডের টান)।
  Future<List<ChatMessage>> messages(String threadId, {String? before, String? after});

  Future<ChatMessage> sendText(String threadId, String text, {required String clientId});

  Future<ChatMessage> sendFile(String threadId, ChatUpload upload, {required String clientId, void Function(double sent)? onProgress});

  Future<ChatMessage> sendLocation(String threadId, double lat, double lng, {required String clientId});

  /// একটা কাগজ এক বা একাধিক মানুষ/গ্রুপে; [note] ইচ্ছা হলে
  Future<void> sendPaper(PaperRef paper, List<String> to, {String note = '', required String clientId});

  Future<void> markRead(String threadId, String upTo);

  Future<void> mute(String threadId, bool muted);

  Future<List<ChatPerson>> members(String threadId);

  Future<List<ChatMessage>> media(String threadId);

  /// [paper] দিলে প্রতিজনের [ChatPerson.canOpenPaper] সেই কাগজের জন্য ভরা আসে
  Future<List<ChatPerson>> people({String query = '', PaperRef? paper});

  Future<ChatThread> direct(String personId);

  Future<CallJoin> startCall(String personId, {required bool video});

  Future<CallJoin> answerCall(String callId);

  Future<void> declineCall(String callId);

  Future<void> endCall(String callId);
}

enum ThreadFilter { all, unread, groups, papers }

/// দরজা নেই — সার্ভার এখনো পুরনো
class MessengerDoorsMissing implements Exception {
  const MessengerDoorsMissing();
}

/// রোলে বার্তা/কলের অনুমতি নেই (৪০৩)
class MessengerNotAllowed implements Exception {
  const MessengerNotAllowed([this.message]);

  final String? message;
}

@immutable
class ChatPerson {
  const ChatPerson({
    required this.id,
    required this.name,
    this.designation = '',
    this.branch = '',
    this.photoUrl,
    this.colour,
    this.online = false,
    this.canOpenPaper = true,
  });

  final String id;
  final String name;
  final String designation;
  final String branch;
  final String? photoUrl;

  /// অবতারের রং (`#RRGGBB`) — সার্ভার না দিলে নাম থেকে স্থির রং
  final String? colour;
  final bool online;

  /// কাগজ পাঠানোর সময়: এঁর সেই কাগজ খোলার অনুমতি আছে কি — না থাকলে শুধু নম্বর দেখবেন
  final bool canOpenPaper;

  String get initial => name.isEmpty ? '?' : String.fromCharCodes(name.runes.take(1));

  /// "হিসাবরক্ষক · TD শাখা"
  String get role => [designation, branch].where((s) => s.isNotEmpty).join(' · ');

  factory ChatPerson.fromJson(Map<String, dynamic> j) => ChatPerson(
        id: '${j['id'] ?? ''}',
        name: '${j['name'] ?? ''}',
        designation: '${j['designation'] ?? ''}',
        branch: '${j['branch'] ?? ''}',
        photoUrl: _str(j['photo_url']),
        colour: _hex(j['colour']),
        online: j['online'] == true,
        canOpenPaper: j['can_open_paper'] != false,
      );
}

enum ThreadKind { direct, group }

@immutable
class ChatThread {
  const ChatThread({
    required this.id,
    required this.kind,
    required this.title,
    this.subtitle = '',
    this.person,
    this.groupIcon = '',
    this.colour,
    this.last,
    this.lastAt,
    this.unread = 0,
    this.muted = false,
    this.memberCount = 0,
    this.onlineCount = 0,
  });

  final String id;
  final ThreadKind kind;

  /// একজনের সাথে: তাঁর নাম; গ্রুপ: গ্রুপের নাম
  final String title;

  /// "হিসাবরক্ষক · TD শাখা" বা "গ্রুপ · 5 জন" — সার্ভারেই সাজানো
  final String subtitle;

  /// একজনের সাথে হলে সেই মানুষ (অনলাইন বিন্দু, ছবি, কল)
  final ChatPerson? person;

  /// গ্রুপের চিহ্ন: calc · tag · truck · users (অচেনা হলে users)
  final String groupIcon;
  final String? colour;

  /// তালিকার শেষ বার্তা
  final ChatMessage? last;
  final DateTime? lastAt;
  final int unread;
  final bool muted;
  final int memberCount;
  final int onlineCount;

  bool get isGroup => kind == ThreadKind.group;

  ChatThread copyWith({int? unread, bool? muted, ChatMessage? last, DateTime? lastAt}) => ChatThread(
        id: id,
        kind: kind,
        title: title,
        subtitle: subtitle,
        person: person,
        groupIcon: groupIcon,
        colour: colour,
        last: last ?? this.last,
        lastAt: lastAt ?? this.lastAt,
        unread: unread ?? this.unread,
        muted: muted ?? this.muted,
        memberCount: memberCount,
        onlineCount: onlineCount,
      );

  factory ChatThread.fromJson(Map<String, dynamic> j) {
    final last = j['last'];
    return ChatThread(
      id: '${j['id'] ?? ''}',
      kind: j['kind'] == 'group' ? ThreadKind.group : ThreadKind.direct,
      title: '${j['title'] ?? ''}',
      subtitle: '${j['subtitle'] ?? ''}',
      person: j['person'] is Map<String, dynamic> ? ChatPerson.fromJson(j['person'] as Map<String, dynamic>) : null,
      groupIcon: '${j['group_icon'] ?? ''}',
      colour: _hex(j['colour']),
      last: last is Map<String, dynamic> ? ChatMessage.fromJson(last) : null,
      lastAt: _date(j['last_at']),
      unread: _int(j['unread']),
      muted: j['muted'] == true,
      memberCount: _int(j['member_count']),
      onlineCount: _int(j['online_count']),
    );
  }
}

@immutable
class ThreadPage {
  const ThreadPage({required this.threads, this.unreadTotal = 0});

  final List<ChatThread> threads;

  /// উপরের বারের বার্তা-চিহ্নের গোনা
  final int unreadTotal;
}

enum MessageKind { text, image, file, voice, paper, location, system }

/// পাঠানো · পৌঁছেছে · পড়েছে (নীল) — আর পাঠানোর পথে/ব্যর্থ (ফোনের নিজের অবস্থা)
enum MessageState { sending, failed, sent, delivered, read }

@immutable
class ChatMessage {
  const ChatMessage({
    required this.id,
    required this.threadId,
    required this.kind,
    required this.at,
    this.clientId,
    this.mine = false,
    this.sender,
    this.text = '',
    this.state = MessageState.sent,
    this.readCount = 0,
    this.attachment,
    this.paper,
    this.lat,
    this.lng,
  });

  final String id;
  final String threadId;

  /// ফোনের নিজের চিহ্ন — একই বার্তা দুবার যায় না, আর পাঠানোর পথের বার্তা সার্ভারেরটার সাথে মেলে
  final String? clientId;
  final MessageKind kind;
  final DateTime at;
  final bool mine;

  /// গ্রুপে বুদবুদের ভেতরে নাম (আমার নিজেরটায় null)
  final ChatPerson? sender;

  /// লেখা, বা ছবি/কাগজের নিচের কথা, বা system লাইনের লেখা
  final String text;
  final MessageState state;

  /// গ্রুপে আমার বার্তা কতজন পড়েছেন ("4 জন পড়েছেন")
  final int readCount;
  final ChatAttachment? attachment;
  final PaperRef? paper;
  final double? lat;
  final double? lng;

  ChatMessage copyWith({String? id, MessageState? state, int? readCount}) => ChatMessage(
        id: id ?? this.id,
        threadId: threadId,
        kind: kind,
        at: at,
        clientId: clientId,
        mine: mine,
        sender: sender,
        text: text,
        state: state ?? this.state,
        readCount: readCount ?? this.readCount,
        attachment: attachment,
        paper: paper,
        lat: lat,
        lng: lng,
      );

  factory ChatMessage.fromJson(Map<String, dynamic> j) => ChatMessage(
        id: '${j['id'] ?? ''}',
        threadId: '${j['thread_id'] ?? ''}',
        clientId: _str(j['client_id']),
        kind: MessageKind.values.firstWhere((k) => k.name == j['kind'], orElse: () => MessageKind.text),
        at: _date(j['at']) ?? DateTime.fromMillisecondsSinceEpoch(0),
        mine: j['mine'] == true,
        sender: j['sender'] is Map<String, dynamic> ? ChatPerson.fromJson(j['sender'] as Map<String, dynamic>) : null,
        text: '${j['text'] ?? ''}',
        state: MessageState.values.firstWhere((s) => s.name == j['state'], orElse: () => MessageState.sent),
        readCount: _int(j['read_count']),
        attachment: j['attachment'] is Map<String, dynamic> ? ChatAttachment.fromJson(j['attachment'] as Map<String, dynamic>) : null,
        paper: j['paper'] is Map<String, dynamic> ? PaperRef.fromJson(j['paper'] as Map<String, dynamic>) : null,
        lat: (j['lat'] as num?)?.toDouble(),
        lng: (j['lng'] as num?)?.toDouble(),
      );
}

@immutable
class ChatAttachment {
  const ChatAttachment({required this.url, this.name = '', this.mime = '', this.size = 0, this.durationMs = 0, this.waveform = const []});

  final String url;
  final String name;
  final String mime;

  /// বাইট
  final int size;

  /// ভয়েস বার্তার দৈর্ঘ্য
  final int durationMs;

  /// ভয়েস বার্তার ঢেউ, প্রতিটা 0–1 (রেকর্ডের সময় ফোনেই মাপা)
  final List<double> waveform;

  factory ChatAttachment.fromJson(Map<String, dynamic> j) => ChatAttachment(
        url: '${j['url'] ?? ''}',
        name: '${j['name'] ?? ''}',
        mime: '${j['mime'] ?? ''}',
        size: _int(j['size']),
        durationMs: _int(j['duration_ms']),
        waveform: [
          for (final v in (j['waveform'] is List ? j['waveform'] as List : const [])) (v is num ? v.toDouble() : 0.0).clamp(0.0, 1.0),
        ],
      );
}

/// ফোন থেকে যাওয়া সংযুক্তি
@immutable
class ChatUpload {
  const ChatUpload({required this.kind, required this.bytes, required this.name, required this.mime, this.durationMs = 0, this.waveform = const []});

  /// image · file · voice
  final MessageKind kind;
  final Uint8List bytes;
  final String name;
  final String mime;
  final int durationMs;
  final List<double> waveform;
}

/// বার্তায় পাঠানো ERP কাগজ — খোলে কেবল যাঁর দেখার অনুমতি আছে ([canOpen]); বাকিরা শুধু নম্বর
@immutable
class PaperRef {
  const PaperRef({
    required this.type,
    required this.id,
    required this.no,
    this.title = '',
    this.amount = '',
    this.party = '',
    this.status = '',
    this.canOpen = true,
    this.path,
  });

  /// সার্ভারের নাম — `SalesInvoice`, `MoneyReceipt`, `SalesOrder` … ([DocumentActionsSheet.type]-এর মতোই)
  final String type;
  final String id;

  /// "RCV-0142"
  final String no;

  /// "রসিদ" · "বিক্রয় চালান"
  final String title;

  /// সার্ভারেই সাজানো, "৳1,24,500"
  final String amount;
  final String party;

  /// "অনুমোদনের অপেক্ষায়" — খালি হলে চিহ্ন নেই
  final String status;
  final bool canOpen;

  /// অ্যাপের ভেতরের পথ (ধরা: `paper/<kind>/<id>`); null মানে অ্যাপে খোলার পাতা নেই
  final String? path;

  Map<String, dynamic> toJson() => {'paper_type': type, 'paper_id': id};

  factory PaperRef.fromJson(Map<String, dynamic> j) => PaperRef(
        type: '${j['type'] ?? ''}',
        id: '${j['id'] ?? ''}',
        no: '${j['no'] ?? ''}',
        title: '${j['title'] ?? ''}',
        amount: '${j['amount'] ?? ''}',
        party: '${j['party'] ?? ''}',
        status: '${j['status'] ?? ''}',
        canOpen: j['can_open'] != false,
        path: _str(j['path']),
      );
}

/// কলে ঢোকার চাবি — অফিসের LiveKit সার্ভার
@immutable
class CallJoin {
  const CallJoin({required this.callId, required this.url, required this.token, required this.video, this.peer});

  final String callId;

  /// `wss://…` — অফিসের LiveKit
  final String url;
  final String token;
  final bool video;
  final ChatPerson? peer;

  factory CallJoin.fromJson(Map<String, dynamic> j) => CallJoin(
        callId: '${j['call_id'] ?? j['id'] ?? ''}',
        url: '${j['url'] ?? ''}',
        token: '${j['token'] ?? ''}',
        video: j['video'] == true,
        peer: j['peer'] is Map<String, dynamic> ? ChatPerson.fromJson(j['peer'] as Map<String, dynamic>) : null,
      );
}

String? _str(Object? v) => v is String && v.isNotEmpty ? v : null;

String? _hex(Object? v) => v is String && RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(v) ? v : null;

int _int(Object? v) => v is num ? v.toInt() : int.tryParse('$v') ?? 0;

DateTime? _date(Object? v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;

class ServerMessengerApi implements MessengerApi {
  const ServerMessengerApi({this.dio});

  final Dio? dio;

  Dio get _dio => dio ?? ApiClient.dio;

  static Object _error(DioException e) {
    final status = e.response?.statusCode;
    if (status == 404 || status == 405) return const MessengerDoorsMissing();
    if (status == 403) {
      final data = e.response?.data;
      return MessengerNotAllowed(data is Map && data['message'] is String ? data['message'] as String : null);
    }
    return e;
  }

  Future<T> _run<T>(Future<T> Function() body) async {
    try {
      return await body();
    } on DioException catch (e) {
      throw _error(e);
    }
  }

  static List<Map<String, dynamic>> _list(Object? data) {
    final rows = data is Map ? data['data'] : data;
    return [for (final r in (rows is List ? rows : const [])) if (r is Map<String, dynamic>) r];
  }

  static Map<String, dynamic> _one(Object? data) {
    if (data is Map<String, dynamic>) return data['data'] is Map<String, dynamic> ? data['data'] as Map<String, dynamic> : data;
    return const {};
  }

  @override
  Future<ThreadPage> threads({ThreadFilter filter = ThreadFilter.all, String query = ''}) => _run(() async {
        final r = await _dio.get<Map<String, dynamic>>('/messenger/threads',
            queryParameters: {'filter': filter.name, if (query.isNotEmpty) 'q': query});
        return ThreadPage(threads: [for (final t in _list(r.data)) ChatThread.fromJson(t)], unreadTotal: _int(r.data?['unread_total']));
      });

  @override
  Future<List<ChatMessage>> messages(String threadId, {String? before, String? after}) => _run(() async {
        final r = await _dio.get<Map<String, dynamic>>('/messenger/threads/$threadId/messages',
            queryParameters: {if (before != null) 'before': before, if (after != null) 'after': after});
        return [for (final m in _list(r.data)) ChatMessage.fromJson(m)];
      });

  @override
  Future<ChatMessage> sendText(String threadId, String text, {required String clientId}) => _run(() async {
        final r = await OnceKey.of('msg:$clientId').send(() => _dio.post<Map<String, dynamic>>('/messenger/threads/$threadId/messages',
            data: {'kind': 'text', 'text': text, 'client_id': clientId}));
        return ChatMessage.fromJson(_one(r.data));
      });

  @override
  Future<ChatMessage> sendFile(String threadId, ChatUpload upload, {required String clientId, void Function(double sent)? onProgress}) =>
      _run(() async {
        final parts = upload.mime.split('/');
        final r = await OnceKey.of('msg:$clientId').send(() => _dio.post<Map<String, dynamic>>(
              '/messenger/threads/$threadId/messages',
              data: FormData.fromMap({
                'kind': upload.kind.name,
                'client_id': clientId,
                if (upload.durationMs > 0) 'duration_ms': upload.durationMs,
                if (upload.waveform.isNotEmpty) 'waveform': upload.waveform.map((v) => v.toStringAsFixed(2)).join(','),
                'file': MultipartFile.fromBytes(upload.bytes,
                    filename: upload.name, contentType: parts.length == 2 ? DioMediaType(parts[0], parts[1]) : null),
              }),
              onSendProgress: onProgress == null
                  ? null
                  : (sent, total) {
                      if (total > 0) onProgress((sent / total).clamp(0.0, 1.0));
                    },
            ));
        return ChatMessage.fromJson(_one(r.data));
      });

  @override
  Future<ChatMessage> sendLocation(String threadId, double lat, double lng, {required String clientId}) => _run(() async {
        final r = await OnceKey.of('msg:$clientId').send(() => _dio.post<Map<String, dynamic>>('/messenger/threads/$threadId/messages',
            data: {'kind': 'location', 'lat': lat, 'lng': lng, 'client_id': clientId}));
        return ChatMessage.fromJson(_one(r.data));
      });

  @override
  Future<void> sendPaper(PaperRef paper, List<String> to, {String note = '', required String clientId}) => _run(() async {
        await OnceKey.of('msg-paper:$clientId').send(() => _dio.post<void>('/messenger/papers',
            data: {...paper.toJson(), 'to': to, if (note.trim().isNotEmpty) 'note': note.trim(), 'client_id': clientId}));
      });

  @override
  Future<void> markRead(String threadId, String upTo) => _run(() async {
        await _dio.post<void>('/messenger/threads/$threadId/read', data: {'up_to': upTo});
      });

  @override
  Future<void> mute(String threadId, bool muted) => _run(() async {
        await _dio.post<void>('/messenger/threads/$threadId/mute', data: {'muted': muted});
      });

  @override
  Future<List<ChatPerson>> members(String threadId) => _run(() async {
        final r = await _dio.get<Map<String, dynamic>>('/messenger/threads/$threadId/members');
        return [for (final p in _list(r.data)) ChatPerson.fromJson(p)];
      });

  @override
  Future<List<ChatMessage>> media(String threadId) => _run(() async {
        final r = await _dio.get<Map<String, dynamic>>('/messenger/threads/$threadId/media');
        return [for (final m in _list(r.data)) ChatMessage.fromJson(m)];
      });

  @override
  Future<List<ChatPerson>> people({String query = '', PaperRef? paper}) => _run(() async {
        final r = await _dio.get<Map<String, dynamic>>('/messenger/people',
            queryParameters: {if (query.isNotEmpty) 'q': query, if (paper != null) ...paper.toJson()});
        return [for (final p in _list(r.data)) ChatPerson.fromJson(p)];
      });

  @override
  Future<ChatThread> direct(String personId) => _run(() async {
        final r = await OnceKey.of('msg-direct:$personId').send(() => _dio.post<Map<String, dynamic>>('/messenger/direct', data: {'person': personId}));
        return ChatThread.fromJson(_one(r.data));
      });

  @override
  Future<CallJoin> startCall(String personId, {required bool video}) => _run(() async {
        final r = await OnceKey.of('call:$personId:${DateTime.now().millisecondsSinceEpoch}')
            .send(() => _dio.post<Map<String, dynamic>>('/messenger/calls', data: {'person': personId, 'video': video}));
        return CallJoin.fromJson(_one(r.data));
      });

  @override
  Future<CallJoin> answerCall(String callId) => _run(() async {
        final r = await OnceKey.of('call-answer:$callId').send(() => _dio.post<Map<String, dynamic>>('/messenger/calls/$callId/answer'));
        return CallJoin.fromJson(_one(r.data));
      });

  @override
  Future<void> declineCall(String callId) => _run(() async {
        await OnceKey.of('call-decline:$callId').send(() => _dio.post<void>('/messenger/calls/$callId/decline'));
      });

  @override
  Future<void> endCall(String callId) => _run(() async {
        await OnceKey.of('call-end:$callId').send(() => _dio.post<void>('/messenger/calls/$callId/end'));
      });
}

final messengerApiProvider = Provider<MessengerApi>((ref) => const ServerMessengerApi());
