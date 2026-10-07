import 'package:intl/intl.dart';

import '../sync_engine/reference_cache.dart';

/// One day's attendance, as `AttendanceSync` sends it — docs/Contract §৭.
///
/// <p>The handler was written and has accepted pushes since the sync engine
/// landed; nothing in this app had ever called it. The same shape as
/// `CustomerDue`: the server side complete, the phone side never built.
class AttendanceRecord {
  const AttendanceRecord(this.payload);

  final Map<String, dynamic> payload;

  static const String entityType = 'Attendance';
  static const String module = 'hr';

  static final DateFormat wireDate = DateFormat('yyyy-MM-dd');

  String get id => (payload['id'] ?? '').toString();

  String? get workDate => _text('workDate');

  DateTime? get date {
    final raw = workDate;
    return raw == null ? null : DateTime.tryParse(raw);
  }

  /// `present` · `absent` · `leave` · `holiday` — `Attendance::STATUSES`.
  String get status => _text('status') ?? 'present';

  String? get inTime => _text('inTime');

  String? get outTime => _text('outTime');

  bool get isLate => payload['isLate'] == true;

  /// <p>⚠️ Shown, never hidden. The server appends a note here when the day
  /// claimed and the day received are far apart — see docs/Contract §৭ rule
  /// খ. A phone's clock can be changed, and the handler's answer is a mark
  /// rather than a punishment; a screen that swallowed the mark would undo
  /// the only thing that makes the claim checkable.
  String? get remarks => _text('remarks');

  String get statusLabel => switch (status) {
        'present' => 'উপস্থিত',
        'absent' => 'অনুপস্থিত',
        'leave' => 'ছুটি',
        'holiday' => 'সরকারি ছুটি',
        _ => status,
      };

  String? _text(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }

  static List<AttendanceRecord> all() => ReferenceCache.instance
      .allOf(entityType)
      .map(AttendanceRecord.new)
      .toList();

  /// Whether this day has already come back from the server.
  static bool syncedFor(DateTime day) {
    final wanted = wireDate.format(day);
    return all().any((row) => row.workDate == wanted);
  }
}

/// One day's attendance on its way up, in the shape `AttendanceSync::apply()`
/// reads — docs/Contract §৭.
class AttendanceDraft {
  const AttendanceDraft({
    required this.day,
    this.status = 'present',
    this.inTime,
    this.remarks,
  });

  final DateTime day;
  final String status;
  final String? inTime;
  final String? remarks;

  /// <p>⛔ <b>No `employeeId`.</b> The handler accepts one only if it is the
  /// sender's own and rejects anything else outright — it will not quietly
  /// substitute the right one, because a silent correction would later raise
  /// "who marked my attendance". Omitted, it uses the signed-in person's own
  /// employee record, which is the only thing this screen ever wants.
  /// Sending it would add a way to be wrong and no way to be more right.
  Map<String, dynamic> toPayload() => {
        'workDate': AttendanceRecord.wireDate.format(day),
        'status': status,
        if (inTime != null) 'inTime': inTime,
        if (remarks != null && remarks!.trim().isNotEmpty)
          'remarks': remarks!.trim(),
      };

  /// The day a queued payload is for — so a screen can tell whether today is
  /// already waiting in the queue without decoding the payload itself.
  static String? dayOf(Map<String, dynamic> payload) =>
      payload['workDate']?.toString();
}
