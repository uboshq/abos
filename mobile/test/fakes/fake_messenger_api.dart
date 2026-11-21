import 'package:abos_mobile/core/messenger/messenger_api.dart';

/// ⭐ নমুনা 3nuwRUdn v5-এর মানুষ আর বার্তা — পরীক্ষা আর সোনালি ছবির জন্য (সার্ভারের চুক্তি আসার আগ পর্যন্ত এটাই "সার্ভার")।
///
/// <p>সময় স্থির: "আজ" = ২১ নভেম্বর ২০২৬। প্রতিটা ডাক [calls]-এ লেখা থাকে, যাতে পরীক্ষা দেখতে পারে কী গেল।
class FakeMessengerApi implements MessengerApi {
  FakeMessengerApi({this.doorsMissing = false, List<ChatThread>? threads, Map<String, List<ChatMessage>>? messages})
      : _threads = threads ?? sampleThreads(),
        _messages = messages ?? sampleMessages();

  final bool doorsMissing;
  final List<ChatThread> _threads;
  final Map<String, List<ChatMessage>> _messages;
  final List<String> calls = [];

  /// পরের [messages] (after সহ) ডাকে এগুলো নতুন হয়ে আসে — টানার পরীক্ষা
  final List<ChatMessage> incoming = [];
  int _seq = 1000;

  static final DateTime today = DateTime(2026, 11, 21);
  static DateTime at(int h, int m, {int daysAgo = 0}) => today.subtract(Duration(days: daysAgo)).add(Duration(hours: h, minutes: m));

  static const shuvo = ChatPerson(id: 'shu', name: 'শুভ আহমেদ', designation: 'মালিক', branch: 'TD শাখা', colour: '#EA580C', online: true);
  static const rakib = ChatPerson(id: 'rak', name: 'রাকিব হাসান', designation: 'হিসাবরক্ষক', branch: 'TD শাখা', colour: '#4338CA', online: true);
  static const nasir = ChatPerson(id: 'nas', name: 'নাসির', designation: 'SR', branch: 'TD শাখা', colour: '#0F766E', online: true);
  static const farzana = ChatPerson(id: 'far', name: 'ফারজানা', designation: 'অফিস', branch: 'TD শাখা', colour: '#BE185D', online: true, canOpenPaper: false);
  static const jamal = ChatPerson(id: 'jam', name: 'জামাল', designation: 'গুদাম', branch: 'TD শাখা', colour: '#4D7C0F');
  static const mitu = ChatPerson(id: 'mit', name: 'মিতু', designation: 'বিক্রয়', branch: 'TD শাখা', colour: '#0E7490');

  static const rcv142 = PaperRef(
    type: 'MoneyReceipt',
    id: '142',
    no: 'RCV-0142',
    title: 'রসিদ',
    amount: '৳1,24,500',
    party: 'রহমান এন্টারপ্রাইজ',
    status: 'অনুমোদনের অপেক্ষায়',
    path: 'paper/money-in/142',
  );

  static ChatMessage _m(String id, String thread, MessageKind kind, DateTime at,
          {bool mine = false, ChatPerson? sender, String text = '', MessageState state = MessageState.sent, int readCount = 0, ChatAttachment? attachment, PaperRef? paper}) =>
      ChatMessage(id: id, threadId: thread, kind: kind, at: at, mine: mine, sender: sender, text: text, state: state, readCount: readCount, attachment: attachment, paper: paper);

  static List<ChatThread> sampleThreads() => [
        ChatThread(
            id: 't-rak', kind: ThreadKind.direct, title: rakib.name, subtitle: 'হিসাবরক্ষক · TD শাখা', person: rakib, unread: 2, lastAt: at(10, 42),
            last: _m('l1', 't-rak', MessageKind.paper, at(10, 42), sender: rakib, text: 'রসিদ · অনুমোদন দিন', paper: rcv142)),
        ChatThread(
            id: 't-acc', kind: ThreadKind.group, title: 'হিসাব বিভাগ', subtitle: 'গ্রুপ · 5 জন', groupIcon: 'calc', colour: '#1E3A8A', unread: 5, memberCount: 5, onlineCount: 3, lastAt: at(10, 15),
            last: _m('l2', 't-acc', MessageKind.text, at(10, 15), sender: farzana, text: 'আজকের জমার স্লিপ দিয়েছি')),
        ChatThread(
            id: 't-nas', kind: ThreadKind.direct, title: nasir.name, subtitle: 'SR · TD শাখা', person: nasir, unread: 1, lastAt: at(9, 58),
            last: _m('l3', 't-nas', MessageKind.text, at(9, 58), sender: nasir, text: 'রহমান এন্টারপ্রাইজের অর্ডার নিয়েছি')),
        ChatThread(
            id: 't-tds', kind: ThreadKind.group, title: 'TD শাখা — বিক্রয়', subtitle: 'গ্রুপ · 12 জন', groupIcon: 'tag', colour: '#166534', memberCount: 12, onlineCount: 6, lastAt: at(9, 20),
            last: _m('l4', 't-tds', MessageKind.text, at(9, 20), mine: true, text: 'কাল সকাল 10টায় সভা', state: MessageState.read)),
        ChatThread(
            id: 't-far', kind: ThreadKind.direct, title: farzana.name, subtitle: 'অফিস · TD শাখা', person: farzana, lastAt: at(16, 30, daysAgo: 1),
            last: _m('l5', 't-far', MessageKind.voice, at(16, 30, daysAgo: 1), sender: farzana, attachment: const ChatAttachment(url: '', durationMs: 18000))),
        ChatThread(
            id: 't-srn', kind: ThreadKind.group, title: 'SR টিম উত্তর', subtitle: 'গ্রুপ · 8 জন', groupIcon: 'truck', colour: '#9A3412', memberCount: 8, onlineCount: 2, lastAt: at(15, 10, daysAgo: 1),
            last: _m('l6', 't-srn', MessageKind.image, at(15, 10, daysAgo: 1), sender: nasir, attachment: const ChatAttachment(url: ''))),
        ChatThread(
            id: 't-jam', kind: ThreadKind.direct, title: jamal.name, subtitle: 'গুদাম · TD শাখা', person: jamal, lastAt: at(11, 0, daysAgo: 3),
            last: _m('l7', 't-jam', MessageKind.text, at(11, 0, daysAgo: 3), mine: true, text: 'মাল নামানো হলে জানাবেন', state: MessageState.delivered)),
      ];

  static const List<double> wave = [.2, .35, .55, .3, .7, .9, .5, .28, .62, .85, .42, .24, .52, .76, .95, .6, .35, .2, .42, .66, .8, .5, .3, .55, .38, .24, .45, .62, .3, .2];

  static Map<String, List<ChatMessage>> sampleMessages() => {
        't-rak': [
          _m('101', 't-rak', MessageKind.text, at(18, 5, daysAgo: 1), sender: rakib, text: 'স্যার, রহমান এন্টারপ্রাইজ আজ ব্যাংকে টাকা জমা দিয়েছে।'),
          _m('102', 't-rak', MessageKind.text, at(18, 7, daysAgo: 1), mine: true, text: 'কত টাকা? রসিদ বানিয়ে পাঠান।', state: MessageState.read),
          _m('103', 't-rak', MessageKind.paper, at(10, 40), sender: rakib, text: 'রসিদ বানিয়েছি, অনুমোদন দিন।', paper: rcv142),
          _m('104', 't-rak', MessageKind.image, at(10, 41), sender: rakib, text: 'ব্যাংকের জমা স্লিপ', attachment: const ChatAttachment(url: '', name: 'slip.jpg', mime: 'image/jpeg')),
          _m('105', 't-rak', MessageKind.voice, at(10, 44), mine: true, state: MessageState.delivered, attachment: const ChatAttachment(url: '', durationMs: 24000, waveform: wave)),
          _m('106', 't-rak', MessageKind.file, at(10, 46), sender: rakib,
              attachment: const ChatAttachment(url: '', name: 'নভেম্বর-জমার-তালিকা.pdf', mime: 'application/pdf', size: 240 * 1024)),
          _m('107', 't-rak', MessageKind.text, at(10, 47), mine: true, text: 'ঠিক আছে, স্লিপ মিলিয়ে দেখছি।'),
        ],
        't-acc': [
          _m('200', 't-acc', MessageKind.system, at(10, 0), text: 'ফারজানা নাসিরকে গ্রুপে যোগ করেছেন'),
          _m('201', 't-acc', MessageKind.text, at(10, 12), sender: farzana, text: 'আজকের জমার স্লিপগুলো দিয়েছি, মোট 6টা।'),
          _m('202', 't-acc', MessageKind.text, at(10, 14), sender: rakib, text: 'RCV-0142 থেকে RCV-0147 পর্যন্ত রসিদ বানানো হয়েছে।'),
          _m('203', 't-acc', MessageKind.text, at(10, 15), sender: nasir, text: 'রহমান এন্টারপ্রাইজের বাকি টাকা কাল তুলব।'),
          _m('204', 't-acc', MessageKind.text, at(10, 18), mine: true, text: 'সবাই দিন বন্ধের আগে জমা মিলিয়ে নিন।', state: MessageState.read, readCount: 4),
          _m('205', 't-acc', MessageKind.paper, at(10, 20), sender: rakib, paper: rcv142),
        ],
      };

  void _note(String c) {
    calls.add(c);
    if (doorsMissing) throw const MessengerDoorsMissing();
  }

  @override
  Future<ThreadPage> threads({ThreadFilter filter = ThreadFilter.all, String query = ''}) async {
    _note('threads:${filter.name}:$query');
    var list = _threads.where((t) => switch (filter) {
          ThreadFilter.all => true,
          ThreadFilter.unread => t.unread > 0,
          ThreadFilter.groups => t.isGroup,
          ThreadFilter.papers => t.last?.kind == MessageKind.paper,
        });
    if (query.isNotEmpty) list = list.where((t) => t.title.contains(query) || (t.last?.text.contains(query) ?? false));
    return ThreadPage(threads: list.toList(), unreadTotal: _threads.fold(0, (s, t) => s + t.unread));
  }

  @override
  Future<List<ChatMessage>> messages(String threadId, {String? before, String? after}) async {
    _note('messages:$threadId:${before ?? ''}:${after ?? ''}');
    if (after != null) {
      final fresh = [...incoming.where((m) => m.threadId == threadId)];
      incoming.removeWhere((m) => m.threadId == threadId);
      _messages.putIfAbsent(threadId, () => []).addAll(fresh);
      return fresh;
    }
    if (before != null) return const [];
    return [...?_messages[threadId]];
  }

  ChatMessage _mine(String threadId, MessageKind kind, String clientId,
      {String text = '', ChatAttachment? attachment, double? lat, double? lng, String? replyToId}) {
    final quoted = replyToId == null ? null : _messages[threadId]?.where((m) => m.id == replyToId).firstOrNull;
    final m = ChatMessage(
        id: '${_seq++}', threadId: threadId, clientId: clientId, kind: kind, at: at(10, 48), mine: true, text: text, attachment: attachment, lat: lat, lng: lng,
        replyTo: quoted == null
            ? null
            : ReplyRef(id: quoted.id, kind: quoted.kind, text: quoted.text, sender: quoted.sender, mine: quoted.mine));
    _messages.putIfAbsent(threadId, () => []).add(m);
    return m;
  }

  /// পরের [poll]-এ "লিখছেন…", অন্যজন অনলাইন কি, আর আগের বার্তার নতুন টিক
  List<ChatPerson> typingNow = [];
  bool? peerOnline;
  final List<MessageStateUpdate> states = [];

  @override
  Future<MessagePoll> poll(String threadId, {required String after}) async {
    final fresh = await messages(threadId, after: after);
    final changed = [...states];
    states.clear();
    return MessagePoll(messages: fresh, typing: typingNow, peerOnline: peerOnline, states: changed);
  }

  @override
  Future<void> typing(String threadId) async => _note('typing:$threadId');

  @override
  Future<List<ChatMessage>> search(String threadId, String query) async {
    _note('search:$threadId:$query');
    final q = query.toLowerCase();
    return [
      ...?_messages[threadId]?.where((m) =>
          m.text.toLowerCase().contains(q) ||
          (m.paper?.no.toLowerCase().contains(q) ?? false) ||
          (m.attachment?.name.toLowerCase().contains(q) ?? false)),
    ];
  }

  @override
  Future<ChatMessage> sendText(String threadId, String text, {required String clientId, String? replyToId}) async {
    _note('sendText:$threadId:$text${replyToId == null ? '' : ':reply=$replyToId'}');
    if (failNextSend) {
      failNextSend = false;
      throw Exception('offline');
    }
    return _mine(threadId, MessageKind.text, clientId, text: text, replyToId: replyToId);
  }

  @override
  Future<ChatMessage> sendFile(String threadId, ChatUpload upload,
      {required String clientId, void Function(double sent)? onProgress, String? replyToId}) async {
    _note('sendFile:$threadId:${upload.kind.name}:${upload.name}');
    onProgress?.call(1);
    return _mine(threadId, upload.kind, clientId,
        attachment: ChatAttachment(url: '', name: upload.name, mime: upload.mime, size: upload.bytes.length, durationMs: upload.durationMs, waveform: upload.waveform));
  }

  @override
  Future<ChatMessage> sendLocation(String threadId, double lat, double lng, {required String clientId, String? replyToId}) async {
    _note('sendLocation:$threadId:$lat,$lng');
    return _mine(threadId, MessageKind.location, clientId, lat: lat, lng: lng, replyToId: replyToId);
  }

  /// পরের [sendText] একবার ব্যর্থ হয় — "যায়নি, আবার চাপুন"-এর পরীক্ষা
  bool failNextSend = false;

  /// [deleteMessage]-এ সবার কাছ থেকে মোছা মানা (সার্ভারের ৪০৩ delete_not_allowed)
  bool deleteEveryoneRefused = false;

  @override
  Future<void> forward(List<String> messageIds, List<String> to, {required String clientId}) async {
    _note('forward:${messageIds.join(',')}:${to.join(',')}');
  }

  @override
  Future<void> deleteMessage(String messageId, {required bool everyone}) async {
    _note('delete:$messageId:${everyone ? 'everyone' : 'me'}');
    if (everyone && deleteEveryoneRefused) throw const MessengerNotAllowed('এক ঘণ্টা পেরিয়ে গেছে — এখন কেবল নিজের কাছ থেকে মোছা যায়');
  }

  @override
  Future<List<MessageReceipt>> info(String messageId) async {
    _note('info:$messageId');
    return [MessageReceipt(person: rakib, deliveredAt: at(10, 45), readAt: at(10, 46))];
  }

  @override
  Future<void> sendPaper(PaperRef paper, List<String> to, {String note = '', required String clientId}) async {
    _note('sendPaper:${paper.type}:${paper.id}:${to.join(',')}:$note');
  }

  @override
  Future<void> markRead(String threadId, String upTo) async => _note('markRead:$threadId:$upTo');

  @override
  Future<void> mute(String threadId, bool muted) async => _note('mute:$threadId:$muted');

  @override
  Future<List<ChatPerson>> members(String threadId) async {
    _note('members:$threadId');
    return const [shuvo, rakib, farzana, nasir, mitu];
  }

  @override
  Future<List<ChatMessage>> media(String threadId) async {
    _note('media:$threadId');
    return [...?_messages[threadId]?.where((m) => m.kind == MessageKind.image || m.kind == MessageKind.file || m.kind == MessageKind.paper)];
  }

  @override
  Future<List<ChatPerson>> people({String query = '', PaperRef? paper}) async {
    _note('people:$query:${paper?.id ?? ''}');
    const all = [rakib, nasir, farzana, jamal, mitu];
    return [for (final p in all) if (query.isEmpty || p.name.contains(query)) p];
  }

  @override
  Future<ChatThread> direct(String personId) async {
    _note('direct:$personId');
    return _threads.firstWhere((t) => t.person?.id == personId,
        orElse: () => ChatThread(id: 't-$personId', kind: ThreadKind.direct, title: personId, person: ChatPerson(id: personId, name: personId)));
  }

  @override
  Future<CallJoin> startCall(String personId, {required bool video}) async {
    _note('startCall:$personId:$video');
    return CallJoin(callId: 'c1', url: 'wss://example.invalid', token: 'tok', video: video, peer: rakib);
  }

  @override
  Future<CallJoin> answerCall(String callId) async {
    _note('answerCall:$callId');
    return CallJoin(callId: callId, url: 'wss://example.invalid', token: 'tok', video: false, peer: rakib);
  }

  @override
  Future<void> declineCall(String callId) async => _note('declineCall:$callId');

  @override
  Future<void> endCall(String callId) async => _note('endCall:$callId');
}
