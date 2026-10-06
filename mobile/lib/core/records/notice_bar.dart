import '../api_client/api_client.dart';

/// ওয়েবের পাদদেশে চলা নোটিশের একটা — ফোনের চলমান লাইনের জন্য (মালিক, ৬ অক্টোবর ২০২৬)।
///
/// <p>⭐ নিয়ম সব সার্ভারের ([[NoticeBoard::forTicker]] — ওয়েবের বারের একই উৎস): নিজের কোম্পানির, চালু আর
/// বারে টিক দেওয়া নোটিশ, জরুরিটা আগে, সীমা মেনে। ফোন কেবল দেখায়; লেখা ওয়েবের একই — শিরোনাম।
class NoticeBarItem {
  const NoticeBarItem({required this.title, this.id, this.priority});

  final String? id;
  final String title;
  final String? priority;

  factory NoticeBarItem.fromJson(Map<String, dynamic> json) => NoticeBarItem(
        id: json['id']?.toString(),
        title: json['title']?.toString().trim() ?? '',
        priority: json['priority']?.toString(),
      );
}

class NoticeBarApi {
  const NoticeBarApi._();

  /// `GET /notices/bar` — ফাঁকা তালিকা মানে বারটা চুপ
  static Future<List<NoticeBarItem>> fetch() async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/notices/bar');
    final list = response.data?['data'];
    return [
      if (list is List)
        for (final row in list)
          if (row is Map)
            NoticeBarItem.fromJson(Map<String, dynamic>.from(row)),
    ].where((n) => n.title.isNotEmpty).toList();
  }
}
