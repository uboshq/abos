import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ আগের জের (ওপেনিং) আর অগ্রিম — দরজার `opening` ঘর (মালিকের নমুনা v2, ২৬ নভেম্বর ২০২৬)
void main() {
  test('আগের জের টিক দেওয়া যায়, চাবি "opening" — বিলের public_id নয়', () {
    final b = VoucherBill.openingFromJson({'kind': 'opening', 'label': 'আগের জের (ওপেনিং)', 'date': '2026-10-01', 'info': 'পুরনো সিস্টেম', 'outstanding': '37700.0000'})!;

    expect(b.opening, isTrue);
    expect(b.advance, isFalse);
    expect(b.tickable, isTrue, reason: '⛔ আগের জেরে টিক নেই');
    expect(b.id, VoucherBill.openingId);
    expect(b.outstanding, 3770000);
    expect(b.info, 'পুরনো সিস্টেম');
  });

  test('অগ্রিম বিয়োগে, টিক নেই', () {
    final b = VoucherBill.openingFromJson({'kind': 'advance', 'label': 'অগ্রিম জমা', 'date': '2026-11-14', 'outstanding': '-3000.0000'})!;

    expect(b.advance, isTrue);
    expect(b.tickable, isFalse, reason: '⛔ অগ্রিমে টিক — ওটা পাওনা নয়');
    expect(b.outstanding, -300000);
    expect(b.id, isEmpty);
  });

  test('শূন্য বা ঘর না থাকলে সারিই নেই', () {
    expect(VoucherBill.openingFromJson({'kind': 'opening', 'outstanding': '0'}), isNull);
    expect(VoucherBill.openingFromJson(null), isNull);
  });
}
