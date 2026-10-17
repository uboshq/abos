import 'package:dio/dio.dart';

import '../api_client/api_client.dart';

/// ⭐ ফোন থেকে কাগজ তোলা আর ক্যামেরায় স্ক্যান — `GET /documents/library/upload/setup`, `POST /documents/library` (মালিক,
/// ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*)।
///
/// <p>⛔ সব নিয়ম সার্ভারের — ওয়েবের তোলার একই যাচাই (ফাইলের ধরন ও মাপ, নাগালের শাখা, নিজের ধাপের গোপনীয়তা)। শাখা যায় public_id-তে।
class UploadChoice {
  const UploadChoice(this.code, this.label, {this.isDefault = false});

  final String code;
  final String label;
  final bool isDefault;
}

class UploadSetup {
  const UploadSetup(this.payload);

  final Map<String, dynamic> payload;

  List<UploadChoice> _list(String key, {String code = 'code'}) => ((payload[key] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => UploadChoice((e[code] ?? '').toString(), (e['label'] ?? '').toString(), isDefault: e['default'] == true))
      .toList();

  List<UploadChoice> get folders => _list('folders');
  List<UploadChoice> get types => _list('types');
  List<UploadChoice> get levels => _list('levels');
  List<UploadChoice> get branches => _list('branches', code: 'id');

  /// শাখা ফাঁকা রাখা (গোটা কোম্পানি) চলে কি না — কেবল যিনি সব শাখা দেখেন
  bool get companyWide => payload['company_wide'] == true;
  int get maxFiles => (payload['max_files'] as num?)?.toInt() ?? 20;
}

class UploadDraft {
  const UploadDraft({
    required this.paths,
    required this.name,
    required this.folder,
    required this.type,
    required this.level,
    this.branch,
    this.description = '',
  });

  final List<String> paths;
  final String name;
  final String folder;
  final String type;
  final String level;
  final String? branch;
  final String description;
}

abstract class DocumentUploadApi {
  Future<UploadSetup> setup();

  /// কয়টা কাগজ বসল, আর সার্ভারের কথা
  Future<(int, String)> upload(UploadDraft draft);
}

class ServerDocumentUploadApi implements DocumentUploadApi {
  const ServerDocumentUploadApi();

  @override
  Future<UploadSetup> setup() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/documents/library/upload/setup');
    return UploadSetup(response.data ?? const {});
  }

  @override
  Future<(int, String)> upload(UploadDraft draft) async {
    final form = FormData.fromMap({
      if (draft.name.trim().isNotEmpty) 'name': draft.name.trim(),
      'doc_type': draft.type,
      'folder': draft.folder,
      'confidentiality': draft.level,
      if (draft.branch != null && draft.branch!.isNotEmpty) 'branch': draft.branch,
      if (draft.description.trim().isNotEmpty) 'description': draft.description.trim(),
    });
    // ⓘ প্রতিটা ফাইল `files[]` নামে, হাতে — Laravel-এর `files` তালিকা ঠিক এই নামেই চায়
    for (final p in draft.paths) {
      form.files.add(MapEntry('files[]', await MultipartFile.fromFile(p, filename: p.split(RegExp(r'[\\/]')).last)));
    }
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/documents/library', data: form);
    final rows = (response.data?['rows'] as List?) ?? const [];
    return (rows.length, (response.data?['message'] ?? '').toString());
  }
}
