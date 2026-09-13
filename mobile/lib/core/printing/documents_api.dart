import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../api_client/api_client.dart';

/// The server's finished documents, fetched as bytes.
///
/// <p>Shape agreed with the server side and written into docs/Contract before
/// either half was built. ⚠️ **The endpoints do not exist yet** — this file is
/// the app's half of an agreement, not a guess at one, and the difference is
/// the whole lesson of 12 September: six screens had been reading key names
/// nobody had ever agreed, and stayed green for a month while drawing
/// nothing.
///
/// <p>Nothing here renders a document. `app/Core/Engines/Print` does, with the
/// same [PrintEngine] the web uses — one layout, one set of totals, one
/// place where the VAT rule lives.
class DocumentsApi {
  const DocumentsApi._();

  /// One document as PDF. [paper] is `a4`, `80mm` or `58mm`, and must be one
  /// the template actually supports — see [papersFor], which is why that call
  /// exists rather than a hardcoded list on the phone.
  static Future<Uint8List> pdf({
    required String type,
    required String id,
    String paper = 'a4',
  }) async {
    final response = await ApiClient.dio.get<List<int>>(
      '/documents/$type/$id/pdf',
      queryParameters: {'paper': paper},
      options: Options(responseType: ResponseType.bytes),
    );
    return Uint8List.fromList(response.data ?? const []);
  }

  /// Which papers this document can be printed on, from the server's own
  /// `papersFor()`.
  ///
  /// <p><b>Asked rather than assumed.</b> Not every template has a thermal
  /// version — a ledger on 58mm paper is not a document anybody wants — and a
  /// hardcoded list on the phone would offer a button that produces nonsense.
  /// The same reasoning as `GET /sync/capabilities`: the server knows what it
  /// can make, so it says.
  static Future<List<String>> papersFor({
    required String type,
    required String id,
  }) async {
    final response = await ApiClient.dio
        .get<List<dynamic>>('/documents/$type/$id/papers');
    return (response.data ?? const []).map((e) => e.toString()).toList();
  }

  /// A report as a spreadsheet. `csv`, `xlsx` or `json` — the three
  /// `ListExport` already makes.
  ///
  /// <p>⚠️ No `docx`. The owner's decision of 13 September: a document to send
  /// is a PDF, numbers to edit are a spreadsheet, and a third format is a
  /// third place for the same figures to disagree.
  static Future<Uint8List> export({
    required String slug,
    String format = 'xlsx',
    Map<String, dynamic> filters = const {},
  }) async {
    final response = await ApiClient.dio.get<List<int>>(
      '/reports/$slug/export',
      queryParameters: {'format': format, ...filters},
      options: Options(responseType: ResponseType.bytes),
    );
    return Uint8List.fromList(response.data ?? const []);
  }
}

/// The three papers the server's `PaperSize` knows, named for a person.
///
/// <p>Kept as plain strings on the wire rather than an enum the two sides
/// would each have to keep in step — the server validates them, and an
/// unfamiliar one is shown as it came rather than dropped.
String paperLabel(String paper) => switch (paper) {
      'a4' => 'A4 কাগজ',
      '80mm' => 'রসিদ ৮০mm',
      '58mm' => 'রসিদ ৫৮mm',
      _ => paper,
    };

/// Whether this paper is a receipt roll rather than a sheet — which decides
/// whether a Bluetooth receipt printer is worth offering for it.
bool isThermalPaper(String paper) => paper == '80mm' || paper == '58mm';

int thermalWidthMm(String paper) => paper == '80mm' ? 80 : 58;
