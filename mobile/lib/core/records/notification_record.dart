import '../api_client/api_client.dart';

/// One message in the bell — the same messages as the web's bell
/// (server `NotificationApiController`, 6 Oct 2026: the owner asked for "a
/// notification icon next to the user photo, so the notifications can be
/// seen").
class NotificationRecord {
  const NotificationRecord(this.payload);

  final Map<String, dynamic> payload;

  /// The `public_id` — never the sequential id.
  String get id => (payload['id'] ?? '').toString();

  String get title => (payload['title'] ?? '').toString();

  String get body => (payload['body'] ?? '').toString();

  bool get read => payload['read'] == true;

  DateTime? get at {
    final raw = payload['at'];
    return raw == null ? null : DateTime.tryParse(raw.toString())?.toLocal();
  }
}

/// The bell's list and its unread count.
class NotificationPage {
  const NotificationPage({required this.items, required this.unread});

  final List<NotificationRecord> items;
  final int unread;
}

/// `GET /notifications`, `POST /notifications/{id}/read`,
/// `POST /notifications/read-all`. Only this person's own messages come
/// back; somebody else's is a 404 on the server.
class NotificationApi {
  const NotificationApi._();

  static Future<NotificationPage> fetch() async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/notifications');
    final body = response.data ?? const <String, dynamic>{};
    final list = body['data'];
    final meta = body['meta'];

    return NotificationPage(
      items: [
        if (list is List)
          for (final row in list)
            if (row is Map) NotificationRecord(Map<String, dynamic>.from(row)),
      ],
      unread: meta is Map ? ((meta['unread'] as num?)?.toInt() ?? 0) : 0,
    );
  }

  static Future<void> markRead(String id) =>
      ApiClient.dio.post<dynamic>('/notifications/$id/read');

  static Future<void> markAllRead() =>
      ApiClient.dio.post<dynamic>('/notifications/read-all');
}
