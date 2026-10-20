import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/kinds/attendance.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/notices.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/quick_actions.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/sync_state.dart';
import 'package:abos_mobile/core/launcher_widgets/kinds/target.dart';
import 'package:abos_mobile/core/launcher_widgets/kit/widget_kind.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';

/// ⭐ a6-এর পাঁচ উইজেট একসাথে: সার্ভারের চাবি, খাঁটি build, আর কোনটা ফোনের নিজের সারাংশ পড়ে (a6, ২০ অক্টোবর ২০২৬)
void main() {
  final asOf = DateTime.utc(2026, 10, 20, 10, 0);
  final status = SyncStatus(clock: () => asOf, pending: () => 2)..watchNetwork(changes: const Stream.empty());
  final kinds = <LauncherWidgetKind, Map<String, dynamic>>{
    const NoticesWidget(): {
      'unread': 1,
      'rows': [
        {'title': 'চেক ফেরত', 'second': 'CHQ-1', 'at': '2026-10-20T09:00:00Z', 'unread': true, 'link': '/notifications/1'},
      ],
      'more': 0,
    },
    const QuickActionsWidget(): {
      'actions': [
        {'key': 'order', 'link': '/sales/orders/create'},
      ],
    },
    const TargetWidget(): {'month': '2026-10', 'target': '100', 'achieved': '50', 'days_left': 3, 'own': true, 'link': '/sales'},
    SyncStateWidget(status: status): status.widgetSummary()!,
    const AttendanceWidget(): {
      'today': {'status': 'present', 'in_time': '09:12', 'late': false},
      'month': {'present': 1, 'absent': 0, 'leave': 0, 'late': 0},
      'leave_left': '11',
      'link': '/hr/attendance',
    },
  };

  test('each key is the server\'s key, word for word', () {
    expect([for (final k in kinds.keys) k.key], ['notices', 'quick_actions', 'target', 'sync_state', 'attendance']);
    for (final k in kinds.keys) {
      expect(k.title, isNotEmpty);
      expect(k.sizes, isNotEmpty);
    }
  });

  test('build is pure: the same summary and asOf give the same document', () {
    for (final MapEntry(key: kind, value: summary) in kinds.entries) {
      final a = kind.build(summary, asOf: asOf).encode();
      final b = kind.build(Map<String, dynamic>.of(summary), asOf: asOf).encode();
      expect(a, b, reason: kind.key);
      expect(kind.build(summary, asOf: asOf.add(const Duration(hours: 1))).encode(), isNot(a), reason: kind.key);
    }
  });

  test('only sync_state answers from the phone; the other four read the server', () {
    for (final kind in kinds.keys) {
      if (kind.key == 'sync_state') {
        expect(kind.localSummary(), isNotNull);
        // ⛔ নতুন isolate-এর (পেছনের WorkManager) অবস্থা কিছু দেখেনি — উত্তর নেই, উইজেট যেমন ছিল তেমন থাকে
        expect(const SyncStateWidget().localSummary(), isNull);
      } else {
        expect(kind.localSummary(), isNull, reason: kind.key);
      }
    }
  });
}
