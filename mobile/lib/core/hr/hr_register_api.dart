import '../../core/api_client/once_key.dart';
import '../api_client/api_client.dart';
import '../orders/sales_desk_api.dart';

export '../orders/sales_desk_api.dart' show DeskRow;

/// ⭐ হাজিরা আর ছুটির রেজিস্টার — নাগালের সব কর্মীর (মালিক, ১৭ অক্টোবর ২০২৬; fe: "দুটোই রেজিস্টার, নিজেরটা নয়")।
///
/// <p>⛔ সব নিয়ম সার্ভারের: শাখার দেয়াল, চাবি, নিজের ছুটি নিজে মঞ্জুর নয়। পর্দা কেবল উত্তরটা বলে। বেতনের কোনো অঙ্ক এখানে আসে না।
abstract class HrRegisterApi {
  Future<DeskRow> attendance(String date);
  Future<DeskRow> leaves({bool pending = false, int page = 1});
  Future<void> approve(String id, {String? remarks});
  Future<void> reject(String id, {required String remarks});
}

class ServerHrRegisterApi implements HrRegisterApi {
  const ServerHrRegisterApi();

  @override
  Future<DeskRow> attendance(String date) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/hr/attendance', queryParameters: {'date': date});
    return DeskRow(response.data ?? const {});
  }

  @override
  Future<DeskRow> leaves({bool pending = false, int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/hr/leave/register',
        queryParameters: {if (pending) 'pending': 1, if (page > 1) 'page': page});
    return DeskRow(response.data ?? const {});
  }

  @override
  Future<void> approve(String id, {String? remarks}) async {
    await OnceKey.of('leave-approve:$id').send(() => ApiClient.dio.post<Map<String, dynamic>>('/hr/leave/register/$id/approve',
        data: {if (remarks != null && remarks.trim().isNotEmpty) 'remarks': remarks.trim()}));
  }

  @override
  Future<void> reject(String id, {required String remarks}) async {
    await OnceKey.of('leave-reject:$id').send(() => ApiClient.dio.post<Map<String, dynamic>>('/hr/leave/register/$id/reject', data: {'remarks': remarks.trim()}));
  }
}
