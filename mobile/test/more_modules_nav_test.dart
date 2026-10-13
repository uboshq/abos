import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/navigation/recent_papers.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ বাকি মডিউল, দ্বিতীয় ভাগ — অর্থ, ডকুমেন্টস, প্রমোশন, নিজের ছুটি ও স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>দাবি: টাইল আসে কেবল ওয়েবের একই চাবিতে (যে চাবি সার্ভারের দরজাও চায়), প্রতিটা নিজের মডিউলে বসে, ফোনে মডিউল বন্ধ
/// থাকলে লুকায়, আর খোলা কাগজ "সাম্প্রতিক" থেকে নিজের তালিকার দরজা দিয়ে আবার খোলে।
const _keys = {
  'deposits': 'finance.deposit.view',
  'bank-loans': 'finance.bank_facility.view',
  'rents': 'finance.rental.view',
  'hand-loans': 'finance.hand_loan.view',
  'documents': 'documents.view',
  'offers': 'promotion.view',
  'leave': 'hr.leave.self',
  'payslips': 'hr.payslip.self',
};

const _modules = {
  'deposits': 'finance',
  'bank-loans': 'finance',
  'rents': 'finance',
  'hand-loans': 'finance',
  'documents': 'documents',
  'offers': 'promotion',
  'leave': 'hr',
  'payslips': 'hr',
};

AuthUser _user(List<String> permissions) =>
    AuthUser(id: 'u1', name: 'কর্মী', email: 'k@x', roles: const ['staff'], permissions: permissions);

void main() {
  test('each new tile comes only with the web key its server door asks for', () {
    final none = const MenuRepository().ordered(const [], _user(const []));
    for (final path in _keys.keys) {
      expect(none.where((i) => i.routeName == path), isEmpty, reason: '$path showed without its key');
    }

    for (final entry in _keys.entries) {
      final items = const MenuRepository().ordered(const [], _user([entry.value]));
      expect(items.where((i) => i.routeName == entry.key), hasLength(1), reason: '${entry.key} missing with ${entry.value}');
      final others = _keys.keys.where((p) => p != entry.key);
      expect(items.where((i) => others.contains(i.routeName)), isEmpty, reason: '${entry.value} opened another door');
    }
  });

  test('every new screen hangs in its own module, never under "অন্যান্য"', () {
    final items = const MenuRepository().ordered(const [], _user(_keys.values.toList()));
    final tree = NavTree.from(items);

    for (final entry in _modules.entries) {
      final row = tree.screen(entry.key);
      expect(row, isNotNull, reason: entry.key);
      expect(row!.module, entry.value, reason: entry.key);
    }
    expect(tree.module('promotion')?.label, 'প্রমোশন');
    for (final m in tree.modules) {
      expect(m.groups.map((g) => g.title), isNot(contains('অন্যান্য')));
    }
    expect(tree.search('অফার').map((r) => r.path), contains('offers'));
    expect(tree.search('ছুটি').map((r) => r.path), contains('leave'));
  });

  test('a module switched off on the phone hides its tiles and its screens', () {
    final items = const MenuRepository().ordered(const [], _user(_keys.values.toList()));

    final salesOnly = ModuleGate.visible(items, {'sales'});
    for (final path in _keys.keys) {
      expect(salesOnly.where((i) => i.routeName == path), isEmpty, reason: path);
      expect(ModuleGate.allows({'sales'}, path), isFalse, reason: path);
    }

    final on = ModuleGate.visible(items, {'finance', 'documents', 'promotion', 'hr'});
    for (final path in _keys.keys) {
      expect(on.where((i) => i.routeName == path), hasLength(1), reason: path);
    }
  });

  test('an opened paper reopens behind its own list', () {
    expect(RecentPapers.listPathOf(RecentPapers.path('deposit', 'x')), 'deposits');
    expect(RecentPapers.listPathOf(RecentPapers.path('bank-loan', 'x')), 'bank-loans');
    expect(RecentPapers.listPathOf(RecentPapers.path('hand-loan', 'x')), 'hand-loans');
    expect(RecentPapers.listPathOf(RecentPapers.path('document', 'x')), 'documents');
    expect(RecentPapers.listPathOf(RecentPapers.path('payslip', 'x')), 'payslips');
    expect(RecentPapers.path('rent', 'x', extra: 'let'), 'paper/rent/x?k=let');
    expect(RecentPapers.listPathOf('paper/rent/x?k=let'), 'rents');
  });
}
