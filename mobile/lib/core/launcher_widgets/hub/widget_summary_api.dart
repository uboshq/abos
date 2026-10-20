import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../api_client/api_client.dart';

/// সার্ভারের এক উত্তর — কোন উইজেট এই মানুষ দেখতে পারেন আর প্রতিটার সারাংশ।
class WidgetSummary {
  const WidgetSummary({required this.asOf, required this.widgets, this.unchanged = false});

  /// সার্ভারের হিসাবের সময়
  final DateTime asOf;

  /// চাবি → সারাংশ। ⛔ চাবি না থাকা = এই মানুষ এই উইজেট দেখতে পারেন না
  final Map<String, Map<String, dynamic>> widgets;

  /// ৩০৪ — আগের উত্তরই এখনো ঠিক
  final bool unchanged;

  /// কোনো উইজেট নয় — দরজা এই মানুষের জন্য বন্ধ (৪০৩) বা পুরনো সার্ভারে নেই (৪০৪)
  static WidgetSummary none(DateTime at) => WidgetSummary(asOf: at, widgets: const {});

  static WidgetSummary? parse(Object? body) {
    if (body is! Map) return null;
    final asOf = DateTime.tryParse('${body['asOf']}');
    final raw = body['widgets'];
    if (asOf == null || raw is! Map) return null;
    return WidgetSummary(asOf: asOf, widgets: {
      for (final e in raw.entries)
        if (e.value is Map) '${e.key}': Map<String, dynamic>.from(e.value as Map),
    });
  }
}

/// তারের এক উত্তর — পরীক্ষায় এটাই বদলানো হয়
class WidgetSummaryReply {
  const WidgetSummaryReply(this.status, {this.etag, this.body});

  final int status;
  final String? etag;
  final Object? body;
}

/// ⭐ `GET /widgets/summary` — ETag সহ (পরিকল্পনা ৪, অংশ ৯)।
///
/// <p>শেষ ETag আর শেষ উত্তর মনে থাকে (কেবল মেমরিতে — পেছনের isolate প্রতিবার নতুন, তাই সেখানে সবসময় পুরো উত্তর);
/// পরের বার `If-None-Match`, আর ৩০৪ এলে আগের উত্তরই ফেরে ([WidgetSummary.unchanged])। ⓘ এটা ছোড়ে — ছোড়া
/// ধরে [WidgetHub], কারণ "নেট নেই" আর "অনুমতি নেই"-এর ফল আলাদা।
class WidgetSummaryApi {
  const WidgetSummaryApi._();

  /// পরীক্ষার পথ — আসলটা [ApiClient.dio]
  @visibleForTesting
  static Future<WidgetSummaryReply> Function(String? etag) transport = _get;

  static String? _etag;
  static WidgetSummary? _last;

  static Future<WidgetSummary> fetch() async {
    final last = _last;
    final reply = await transport(last == null ? null : _etag);
    if (reply.status == 304 && last != null) {
      return WidgetSummary(asOf: last.asOf, widgets: last.widgets, unchanged: true);
    }
    final summary = WidgetSummary.parse(reply.body);
    if (summary == null) {
      throw StateError('widgets/summary: unreadable answer (${reply.status})');
    }
    _etag = reply.etag;
    _last = summary;
    return summary;
  }

  /// ⛔ সাইন-আউট বা কোম্পানি বদলে — আগের মানুষের উত্তর পরের জনের ৩০৪-এ ফিরবে না
  static void forget() {
    _etag = null;
    _last = null;
  }

  static Future<WidgetSummaryReply> _get(String? etag) async {
    final response = await ApiClient.dio.get<Object?>(
      '/widgets/summary',
      options: Options(
        headers: {if (etag != null) 'If-None-Match': etag},
        validateStatus: (s) => s == 200 || s == 304,
      ),
    );
    return WidgetSummaryReply(
      response.statusCode ?? 0,
      etag: response.headers.value('etag'),
      body: response.data,
    );
  }
}
