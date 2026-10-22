import '../../locale/app_locale.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';

/// ⭐ ১৯ · "আমার উপস্থিতি" — আজ কখন এসেছি, দেরি কি না, এ মাসের হিসাব আর ছুটি কত বাকি (a6, ২০ অক্টোবর ২০২৬; চুক্তি
/// `E:\ABOS\app-widgets-ভিত্তি.md` §২)।
///
/// <p>সারাংশ: `{"today": {"status"|null, "in_time"|null, "late": bool}, "month": {"present", "absent", "leave", "late"},
/// "leave_left": decimal|null, "link"}`, অথবা কর্মীর খাতা না থাকলে `{"employee": false}`।
/// অবস্থা `AttendanceRecord.status`-এর একই চার: present · absent · leave · holiday।
///
/// <p>⛔ বেতন বা কাটা টাকার কোনো কথা নয় — কেবল দিন আর সময়।
class AttendanceWidget extends LauncherWidgetKind {
  const AttendanceWidget();

  @override
  String get key => 'attendance';

  @override
  String get title => AppLocale.l10n.launcherWidgetAttendanceTitle;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x1, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final l = AppLocale.l10n;
    final link = summary['link'] as String?;
    if (summary['employee'] == false) {
      return WidgetDoc(title: title, asOf: asOf, link: link, empty: l.launcherWidgetAttendanceNoRecord);
    }
    final today = summary['today'] is Map ? summary['today'] as Map : const {};
    final month = summary['month'] is Map ? summary['month'] as Map : const {};
    final inTime = _hhmm(today['in_time']);
    final late = today['late'] == true;
    final WidgetText figure = switch (today['status']) {
      'present' when inTime != null => WidgetText(l.launcherWidgetAttendanceInAt(inTime), tone: late ? WidgetTone.warn : WidgetTone.good),
      'present' => WidgetText(l.launcherWidgetAttendancePresent, tone: WidgetTone.good),
      'absent' => WidgetText(l.launcherWidgetAttendanceAbsent, tone: WidgetTone.bad),
      'leave' => WidgetText(l.launcherWidgetAttendanceLeave),
      'holiday' => WidgetText(l.launcherWidgetAttendanceHoliday),
      _ => WidgetText(l.launcherWidgetAttendanceNotMarked, tone: WidgetTone.muted),
    };
    final leaveLeft = _number(summary['leave_left']);
    return WidgetDoc(
      title: title,
      asOf: asOf,
      link: link,
      hero: WidgetHero(
        figure,
        caption: late && today['status'] == 'present' ? WidgetText(l.launcherWidgetAttendanceLate, tone: WidgetTone.warn) : null,
      ),
      rows: [
        if (leaveLeft != null) WidgetRow(title: WidgetText(l.launcherWidgetAttendanceLeaveLeft(leaveLeft))),
        if (month.isNotEmpty)
          WidgetRow(
            title: WidgetText(l.launcherWidgetAttendanceMonth('${_int(month['present'])}', '${_int(month['absent'])}')),
            second: WidgetText(l.launcherWidgetAttendanceMonthMore('${_int(month['leave'])}', '${_int(month['late'])}'), tone: WidgetTone.muted),
          ),
      ],
    );
  }

  /// "09:12:00" → "09:12"
  static String? _hhmm(Object? v) {
    final m = RegExp(r'^(\d{1,2}):(\d{2})').firstMatch('${v ?? ''}');
    return m == null ? null : '${m[1]!.padLeft(2, '0')}:${m[2]}';
  }

  /// "11.0" → "11", "1.5" → "1.5"; পড়া না গেলে null
  static String? _number(Object? v) {
    final d = v is num ? v.toDouble() : double.tryParse('${v ?? ''}');
    if (d == null) return null;
    return d == d.truncateToDouble() ? d.toInt().toString() : d.toString();
  }

  static int _int(Object? v) => v is num ? v.toInt() : int.tryParse('${v ?? ''}') ?? 0;
}
