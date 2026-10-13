import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../approvals/approvals_api.dart';

/// ⭐ ডকুমেন্টস, ফোনে — যে কাগজ দেখার অধিকার আছে তার তালিকা, একটা কাগজ, তার ফাইল আর সই (মালিকের আদেশ,
/// ১৩ অক্টোবর ২০২৬; সার্ভার `DocumentLibraryApiController`)।
///
/// <p>দেয়াল সব সার্ভারের — গোপনীয়তার ধাপ, বিভাগ, শাখা, অধিকার। সই নতুন দরজা নয়: কাগজ বলে কোন অনুমোদনে সই চাওয়া, আর
/// সই যায় অনুমোদনের দরজায় ([[ApprovalsApi]]) — ওয়েবের একই অনুমোদন-ইঞ্জিন।

String _s(dynamic v) => v?.toString() ?? '';
String? _sn(dynamic v) => v == null || v.toString().isEmpty ? null : v.toString();

class LibraryDocument {
  const LibraryDocument({
    required this.id,
    required this.no,
    required this.name,
    this.folder = '',
    this.folderName = '',
    this.type = '',
    this.typeName = '',
    this.level = '',
    this.status = '',
    this.documentDate,
    this.expiryDate,
    this.version,
    this.description = '',
    this.tags = const [],
    this.owner = '',
    this.file,
    this.signature,
  });

  final String id;
  final String no;
  final String name;
  final String folder;
  final String folderName;
  final String type;
  final String typeName;

  /// public · internal · confidential · highly_confidential · restricted
  final String level;
  final String status;
  final String? documentDate;
  final String? expiryDate;
  final String? version;
  final String description;
  final List<String> tags;
  final String owner;
  final LibraryFile? file;
  final SignatureState? signature;

  factory LibraryDocument.fromJson(Map<String, dynamic> j) {
    final file = j['file'];
    final sig = j['signature'];
    return LibraryDocument(
      id: _s(j['id']),
      no: _s(j['no']),
      name: _s(j['name']),
      folder: _s(j['folder']),
      folderName: _s(j['folder_name']),
      type: _s(j['type']),
      typeName: _s(j['type_name']),
      level: _s(j['level']),
      status: _s(j['status']),
      documentDate: _sn(j['document_date']),
      expiryDate: _sn(j['expiry_date']),
      version: _sn(j['version']),
      description: _s(j['description']),
      tags: [if (j['tags'] is List) for (final t in j['tags'] as List) t.toString()],
      owner: _s(j['owner']),
      file: file is Map
          ? LibraryFile(
              name: _s(file['name']),
              mime: _s(file['mime']),
              size: (file['size'] as num?)?.toInt() ?? 0,
              version: _s(file['version']),
              inline: file['inline'] == true,
              download: file['download'] == true,
            )
          : null,
      signature: sig is Map
          ? SignatureState(
              state: _s(sig['state']),
              approvalId: _sn(sig['approval_id']),
              signed: [
                if (sig['signed'] is List)
                  for (final s in sig['signed'] as List)
                    if (s is Map) (by: _s(s['by']), at: _sn(s['at']), current: s['current'] == true),
              ],
            )
          : null,
    );
  }
}

class LibraryFile {
  const LibraryFile({
    required this.name,
    required this.mime,
    this.size = 0,
    this.version = '',
    this.inline = false,
    this.download = false,
  });

  final String name;
  final String mime;
  final int size;
  final String version;

  /// পাতার ভিতরে দেখা যায় — PDF আর ছবি
  final bool inline;

  /// নামানোর অধিকার আছে
  final bool download;

  bool get isPdf => mime == 'application/pdf';
  bool get isImage => mime.startsWith('image/');
}

class SignatureState {
  const SignatureState({required this.state, this.approvalId, this.signed = const []});

  /// none · pending · signed · stale
  final String state;

  /// সই দিতে পারলে তবেই — অনুমোদনের public_id
  final String? approvalId;
  final List<({String by, String? at, bool current})> signed;
}

class LibraryPage {
  const LibraryPage(this.rows, {this.nextPage});

  final List<LibraryDocument> rows;
  final int? nextPage;
}

abstract class DocumentLibraryApi {
  Future<LibraryPage> list({String view = 'center', String q = '', int page = 1});
  Future<LibraryDocument> one(String id);

  /// ফাইলের বাইট — `preview` পাতার ভিতরে দেখা, `download` নামানো
  Future<Uint8List> file(String id, {bool download = false});

  /// সই — অনুমোদনের দরজায়, ওয়েবের একই ইঞ্জিন
  Future<void> sign(String approvalId, {String? remarks});
  Future<void> refuse(String approvalId, {required String remarks});
}

class ServerDocumentLibraryApi implements DocumentLibraryApi {
  const ServerDocumentLibraryApi();

  @override
  Future<LibraryPage> list({String view = 'center', String q = '', int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/documents/library',
        queryParameters: {'view': view, if (q.trim().isNotEmpty) 'q': q.trim(), 'page': page});
    final body = response.data ?? const <String, dynamic>{};
    return LibraryPage(
      [
        if (body['rows'] is List)
          for (final r in body['rows'] as List)
            if (r is Map) LibraryDocument.fromJson(Map<String, dynamic>.from(r)),
      ],
      nextPage: (body['next_page'] as num?)?.toInt(),
    );
  }

  @override
  Future<LibraryDocument> one(String id) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/documents/library/${Uri.encodeComponent(id)}');
    return LibraryDocument.fromJson(response.data ?? const <String, dynamic>{});
  }

  @override
  Future<Uint8List> file(String id, {bool download = false}) async {
    final response = await ApiClient.dio.get<List<int>>(
      '/documents/library/${Uri.encodeComponent(id)}/file',
      queryParameters: {'how': download ? 'download' : 'preview'},
      options: Options(responseType: ResponseType.bytes),
    );
    return Uint8List.fromList(response.data ?? const []);
  }

  @override
  Future<void> sign(String approvalId, {String? remarks}) => ApprovalsApi.approve(approvalId, remarks: remarks);

  @override
  Future<void> refuse(String approvalId, {required String remarks}) => ApprovalsApi.reject(approvalId, remarks: remarks);
}
