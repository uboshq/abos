import '../api_client/api_client.dart';
import '../records/principal_commission.dart';

/// ⭐ প্রিন্সিপালের কমিশনের দরজা — `GET /supplier/principal-commission?month=YYYY-MM` (চুক্তি §১, cb, ২০ অক্টোবর ২০২৬)।
///
/// <p>ⓘ শাখা: অ্যাপ আলাদা কিছু পাঠায় না — হেডারে বাছা শাখা সার্ভারেই থাকে (`POST /workspace`, [[ViewedBranch]]), আর ওয়েবের
/// রিপোর্ট, অ্যাপের বাকি রিপোর্ট আর এই দরজা একই শাখা পড়ে। মাস না দিলে চলতি চক্র — ওয়েবের একই ডিফল্ট।
/// ⛔ ৪০৩ (চাবি নেই) আর ৪০৪ (মডিউল বন্ধ বা পুরনো সার্ভার) ছোড়া হয় — পর্দা নিজের বাংলা কথা দেখায়।
abstract class PrincipalCommissionApi {
  Future<PrincipalCommissionPage> load({String? month});
}

class ServerPrincipalCommissionApi implements PrincipalCommissionApi {
  const ServerPrincipalCommissionApi();

  @override
  Future<PrincipalCommissionPage> load({String? month}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/supplier/principal-commission',
      queryParameters: {if (month != null && month.isNotEmpty) 'month': month},
    );
    return PrincipalCommissionPage.fromJson(response.data ?? const {});
  }
}
