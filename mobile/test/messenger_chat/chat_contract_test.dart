import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ চ্যাটের ভাগের JSON — সার্ভারের MessengerPresenter-এর নামগুলো হুবহু (fe/msg: message, reply_to, meta, info)
void main() {
  test('a poll reads data, meta.typing, peer_online and states (deleted becomes a tombstone mark)', () {
    final p = MessagePoll.fromJson(const {
      'data': [
        {
          'id': '108',
          'thread_id': '01JTHREAD',
          'client_id': null,
          'kind': 'text',
          'at': '2026-11-21T10:49:00+06:00',
          'mine': false,
          'sender': {'id': '12', 'name': 'রাকিব হাসান', 'designation': 'হিসাবরক্ষক', 'branch': 'TD শাখা', 'photo_url': null, 'colour': null, 'online': false},
          'text': 'মিলেছে?',
          'state': 'sent',
          'read_count': 0,
          'forwarded': true,
          'attachment': null,
          'paper': null,
          'lat': null,
          'lng': null,
          'reply_to': {
            'id': '107',
            'kind': 'text',
            'text': 'ঠিক আছে',
            'sender': null,
            'mine': true,
          },
        },
      ],
      'meta': {
        'typing': [
          {'id': '12', 'name': 'রাকিব হাসান', 'online': true},
        ],
        'peer_online': true,
        'states': [
          {'id': '107', 'state': 'read', 'read_count': 1},
          {'id': '105', 'state': 'deleted'},
        ],
        'has_more': false,
      },
    });
    expect(p.messages.single.id, '108');
    expect(p.messages.single.forwarded, isTrue);
    expect(p.messages.single.replyTo!.id, '107');
    expect(p.messages.single.replyTo!.mine, isTrue);
    expect(p.typing.single.name, 'রাকিব হাসান');
    expect(p.peerOnline, isTrue);
    expect(p.states.first.state, MessageState.read);
    expect(p.states.first.readCount, 1);
    expect(p.states.last.deleted, isTrue);
  });

  test('a deleted message arrives as kind "deleted"; a group poll has peer_online false', () {
    final m = ChatMessage.fromJson(const {'id': '9', 'kind': 'deleted', 'mine': true, 'text': '', 'state': 'read', 'reply_to': null});
    expect(m.kind, MessageKind.deleted);
    expect(MessagePoll.fromJson(const {'data': [], 'meta': {'peer_online': false}}).peerOnline, isFalse);
  });

  test('info rows: person, delivered_at, read_at (null = not yet)', () {
    final r = MessageReceipt.fromJson(const {
      'person': {'id': '12', 'name': 'রাকিব হাসান'},
      'delivered_at': '2026-11-21T10:45:00+06:00',
      'read_at': null,
    });
    expect(r.person.id, '12');
    expect(r.deliveredAt, isNotNull);
    expect(r.readAt, isNull);
  });
}
