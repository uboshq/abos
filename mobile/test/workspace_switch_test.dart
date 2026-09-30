import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/core/workspace/workspace_switcher.dart';
import 'package:abos_mobile/features/home/home_shell.dart';
import 'package:abos_mobile/features/home/workspace_picker.dart';

/// The owner, 1 October 2026: *"app e company & branch change hoyna"* — the
/// app had no way at all to change company or branch.
///
/// <p>Three layers, each claimed on its own: what `/me` now says
/// ([SessionProfile]), what a move does to the phone ([WorkspaceSwitcher]),
/// and the picker a person actually taps ([WorkspacePicker], [HomeShell]).

const _tdepot = OrgRef(publicId: 'c-td', code: 'TDEPOT', name: 'ট্রেড ডিপো');
const _fmart = OrgRef(publicId: 'c-fm', code: 'FMART', name: 'ফ্যামিলি মার্ট');
const _mms = OrgRef(publicId: 'b-mms', code: 'MMS', name: 'প্রধান ময়মনসিংহ');
const _ntk = OrgRef(publicId: 'b-ntk', code: 'NTK', name: 'নেত্রকোনা');
const _main = OrgRef(publicId: 'b-main', code: 'MAIN', name: 'প্রধান কার্যালয়');

const _inTdepot = SessionProfile(
  company: _tdepot,
  branch: _mms,
  locale: 'bn',
  companies: [_tdepot, _fmart],
  branches: [_mms, _ntk],
  viewAllBranches: false,
  phoneModules: {'sales', 'approval'},
);

const _inFmart = SessionProfile(
  company: _fmart,
  branch: _main,
  locale: 'bn',
  companies: [_tdepot, _fmart],
  branches: [_main, OrgRef(publicId: 'b-2', code: 'B2', name: 'দ্বিতীয় শাখা')],
  viewAllBranches: true,
);

/// A switcher that records what it was asked and what it did — no server,
/// no Hive, no plugin.
class _Recorder {
  final posts = <(String, String)>[];
  int cleared = 0;
  int forgotten = 0;
  int synced = 0;
  int widgets = 0;
  int pending = 0;
  Map<String, dynamic> answer = const {'companyChanged': false};
  DioException? refuse;

  WorkspaceSwitcher switcher() => WorkspaceSwitcher(
        post: (company, branch) async {
          posts.add((company, branch));
          if (refuse != null) throw refuse!;
          return answer;
        },
        clearCache: () async => cleared++,
        forgetLastFailure: () => forgotten++,
        syncAll: () async => synced++,
        refreshWidgets: () async => widgets++,
        pendingCount: () => pending,
      );
}

DioException _answered(int status, [Object? body]) {
  final options = RequestOptions(path: '/workspace');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.badResponse,
    response: Response(requestOptions: options, statusCode: status, data: body),
  );
}

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

void main() {
  group('GET /me carries where one can go', () {
    test('companies, branches, "all branches" and the phone modules are read', () {
      final profile = SessionProfile.fromJson({
        'user': {'locale': 'bn'},
        'company': {'public_id': 'c-td', 'code': 'TDEPOT', 'name': 'ট্রেড ডিপো'},
        'branch': {'public_id': 'b-mms', 'code': 'MMS', 'name': 'প্রধান ময়মনসিংহ'},
        'companies': [
          {'public_id': 'c-td', 'code': 'TDEPOT', 'name': 'ট্রেড ডিপো'},
          {'public_id': 'c-fm', 'code': 'FMART', 'name': 'ফ্যামিলি মার্ট'},
          {'code': 'NOID', 'name': 'no public id — cannot be switched into'},
        ],
        'branches': [
          {'public_id': 'b-mms', 'code': 'MMS', 'name': 'প্রধান ময়মনসিংহ'},
          {'public_id': 'b-ntk', 'code': 'NTK', 'name': 'নেত্রকোনা'},
        ],
        'viewAllBranches': true,
        'phoneModules': ['sales', 'inventory'],
      });

      expect(profile.companies.map((c) => c.code), ['TDEPOT', 'FMART']);
      expect(profile.branches.map((b) => b.code), ['MMS', 'NTK']);
      expect(profile.viewAllBranches, isTrue);
      expect(profile.phoneModules, {'sales', 'inventory'});
      expect(profile.canSwitch, isTrue);
    });

    test('a server older than the switcher: nothing to pick, nothing hidden', () {
      final profile = SessionProfile.fromJson({
        'company': {'public_id': 'c-td', 'code': 'TDEPOT', 'name': 'ট্রেড ডিপো'},
      });

      expect(profile.companies, isEmpty);
      expect(profile.branches, isEmpty);
      expect(profile.canSwitch, isFalse);
      expect(profile.phoneModules, isNull, reason: 'unknown is not "everything off"');
    });
  });

  group('WorkspaceSwitcher', () {
    test('another company: the phone copy is cleared and a full pull starts', () async {
      final r = _Recorder()..answer = const {'companyChanged': true};

      final changed = await r.switcher().switchTo(
            currentCompanyPublicId: 'c-td',
            companyPublicId: 'c-fm',
            branch: WorkspaceSwitcher.allBranches,
          );

      expect(changed, isTrue);
      expect(r.posts, [('c-fm', 'all')]);
      expect(r.cleared, 1, reason: 'the old company\'s customers must not stay on the phone');
      expect(r.forgotten, 1);
      expect(r.synced, 1);
      expect(r.widgets, 1);
    });

    test('another branch: nothing is cleared, since the server keeps the watermarks', () async {
      final r = _Recorder();

      final changed = await r.switcher().switchTo(
            currentCompanyPublicId: 'c-td',
            companyPublicId: 'c-td',
            branch: 'b-ntk',
          );

      expect(changed, isFalse);
      expect(r.posts, [('c-td', 'b-ntk')]);
      expect(r.cleared, 0, reason: 'a cleared list would be refilled only with what changed since');
      expect(r.synced, 0);
      expect(r.widgets, 1, reason: 'the figures on the launcher belong to the old branch');
    });

    test('orders still on the phone: no move that changes where work lands', () async {
      final r = _Recorder()..pending = 2;

      for (final (company, branch) in [('c-fm', 'all'), ('c-td', 'b-ntk')]) {
        await expectLater(
          r.switcher().switchTo(
                currentCompanyPublicId: 'c-td',
                companyPublicId: company,
                branch: branch,
              ),
          throwsA(isA<WorkspaceFailure>()
              .having((f) => f.message, 'message', contains('2 টি অর্ডার'))),
        );
      }
      expect(r.posts, isEmpty, reason: 'the server was never asked');

      // "সব শাখা" changes only what is seen, not where an order is filed.
      await r.switcher().switchTo(
            currentCompanyPublicId: 'c-td',
            companyPublicId: 'c-td',
            branch: WorkspaceSwitcher.allBranches,
          );
      expect(r.posts, [('c-td', 'all')]);
    });

    test('every refusal reads in Bangla, and nothing is cleared', () async {
      final cases = <DioException, String>{
        _answered(403, {'message': 'You may not enter this company.'}):
            'এই কোম্পানিতে ঢোকার অনুমতি আপনার নেই।',
        _answered(422, {'message': 'The branch field is invalid.'}):
            'এই শাখায় যাওয়া গেল না — শাখাটা আপনার নাগালে নেই, বা এই কোম্পানির নয়।',
        _answered(422, {'message': 'এই শাখায় কাজ করার অধিকার আপনার নেই।'}):
            'এই শাখায় কাজ করার অধিকার আপনার নেই।',
        _answered(404): 'সার্ভার এখনো অ্যাপ থেকে কোম্পানি বদলানো চেনে না। '
            'সার্ভার হালনাগাদ হলে পারবেন; ততক্ষণ ওয়েব থেকে বদলান।',
        _answered(500): 'বদলানো গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।',
        DioException(
          requestOptions: RequestOptions(path: '/workspace'),
          type: DioExceptionType.connectionError,
        ): 'সংযোগ নেই। ইন্টারনেট চেক করে আবার চেষ্টা করুন।',
      };

      for (final entry in cases.entries) {
        final r = _Recorder()..refuse = entry.key;
        await expectLater(
          r.switcher().switchTo(
                currentCompanyPublicId: 'c-td',
                companyPublicId: 'c-fm',
                branch: 'all',
              ),
          throwsA(isA<WorkspaceFailure>().having((f) => f.message, 'message', entry.value)),
        );
        expect(r.cleared, 0);
        expect(r.synced, 0);
      }
    });
  });

  group('WorkspacePicker', () {
    Future<_Recorder> open(
      WidgetTester tester, {
      SessionProfile profile = _inTdepot,
      Future<SessionProfile?> Function()? reload,
      void Function(bool moved)? onClosed,
      _Recorder? recorder,
    }) async {
      final r = recorder ?? _Recorder();
      await tester.pumpWidget(MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: Center(
              child: TextButton(
                onPressed: () async {
                  final moved = await showWorkspacePicker(
                    context,
                    profile: profile,
                    reload: reload ?? () async => profile,
                    switcher: r.switcher(),
                  );
                  onClosed?.call(moved);
                },
                child: const Text('open'),
              ),
            ),
          ),
        ),
      ));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      return r;
    }

    testWidgets('lists the companies, then the branches with "সব শাখা" first', (tester) async {
      await open(tester);

      expect(find.text('কোম্পানি'), findsOneWidget);
      expect(find.text('ট্রেড ডিপো'), findsOneWidget);
      expect(find.text('ফ্যামিলি মার্ট'), findsOneWidget);
      expect(find.text('শাখা — ট্রেড ডিপো'), findsOneWidget);
      expect(find.text('সব শাখা'), findsOneWidget);
      expect(find.text('প্রধান ময়মনসিংহ'), findsOneWidget);
      expect(find.text('নেত্রকোনা'), findsOneWidget);

      // The current company and branch carry the tick; "সব শাখা" does not.
      Finder tick(String key) => find.descendant(
          of: find.byKey(ValueKey(key)), matching: find.byIcon(Icons.check));
      expect(tick('company-c-td'), findsOneWidget);
      expect(tick('branch-b-mms'), findsOneWidget);
      expect(tick('branch-all'), findsNothing);
      expect(tick('branch-b-ntk'), findsNothing);
    });

    testWidgets('a branch moves there and closes', (tester) async {
      bool? moved;
      final r = await open(tester, onClosed: (m) => moved = m);

      await tester.tap(find.text('নেত্রকোনা'));
      await tester.pumpAndSettle();

      expect(r.posts, [('c-td', 'b-ntk')]);
      expect(moved, isTrue);
      expect(find.text('কোম্পানি ও শাখা'), findsNothing);
    });

    testWidgets('"সব শাখা" is sent as the web header sends it', (tester) async {
      final r = await open(tester);

      await tester.tap(find.text('সব শাখা'));
      await tester.pumpAndSettle();

      expect(r.posts, [('c-td', 'all')]);
    });

    testWidgets('another company moves there, then shows that company\'s own branches',
        (tester) async {
      final r = _Recorder()..answer = const {'companyChanged': true};
      await open(tester, recorder: r, reload: () async => _inFmart);

      await tester.tap(find.text('ফ্যামিলি মার্ট'));
      await tester.pumpAndSettle();

      expect(r.posts, [('c-fm', 'all')]);
      expect(r.cleared, 1);
      expect(find.text('শাখা — ফ্যামিলি মার্ট'), findsOneWidget);
      expect(find.text('দ্বিতীয় শাখা'), findsOneWidget);
      expect(find.text('নেত্রকোনা'), findsNothing, reason: 'the old company\'s branches are gone');
    });

    testWidgets('a refusal is said in Bangla and the picker stays open', (tester) async {
      bool? moved;
      final r = _Recorder()..refuse = _answered(403, {'message': 'forbidden'});
      await open(tester, recorder: r, onClosed: (m) => moved = m);

      await tester.tap(find.text('ফ্যামিলি মার্ট'));
      await tester.pumpAndSettle();

      expect(find.text('এই কোম্পানিতে ঢোকার অনুমতি আপনার নেই।'), findsOneWidget);
      expect(find.text('কোম্পানি ও শাখা'), findsOneWidget);
      expect(moved, isNull);

      await tester.tap(find.text('বন্ধ করুন'));
      await tester.pumpAndSettle();
      expect(moved, isFalse, reason: 'nothing moved, so the home screen need not reload');
    });
  });

  group('the home header', () {
    const user = AuthUser(
      id: 'u1',
      name: 'মালিক',
      email: 'owner@abos.test',
      roles: ['super_admin'],
      permissions: ['sales.order.view', 'sales.order.create', 'hr.attendance.self'],
    );

    Widget shell(SessionProfile profile, {List<MenuItem> items = const []}) => ProviderScope(
          overrides: [authStateProvider.overrideWith((ref) => _SignedIn(user))],
          child: MaterialApp(
            home: HomeShell(
              loadHome: (_) async => HomeMenu(items: items, profile: profile),
              fetchToday: () async => const TodayRecord({}),
              lastKnownToday: () => null,
              readCachedOrg: () async => null,
              cacheOrg: (_) async {},
              checkUpdate: () async => UpdateStatus.fine,
              now: () => DateTime(2026, 10, 1, 10),
              reloadProfile: () async => profile,
              switcher: _Recorder().switcher(),
            ),
          ),
        );

    testWidgets('tapping the company in the header opens the picker', (tester) async {
      await tester.pumpWidget(shell(_inTdepot));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('workspace-header')));
      await tester.pumpAndSettle();

      expect(find.text('কোম্পানি ও শাখা'), findsOneWidget);
      expect(find.text('ফ্যামিলি মার্ট'), findsOneWidget);
    });

    testWidgets('one company and one branch: the header is not a button', (tester) async {
      await tester.pumpWidget(shell(const SessionProfile(
        company: _tdepot,
        branch: _mms,
        locale: 'bn',
        companies: [_tdepot],
        branches: [_mms],
      )));
      await tester.pumpAndSettle();

      expect(find.text('ট্রেড ডিপো'), findsOneWidget);
      expect(find.byKey(const ValueKey('workspace-header')), findsNothing);
    });

    testWidgets('"সব শাখা" in view is what the header says', (tester) async {
      await tester.pumpWidget(shell(_inFmart));
      await tester.pumpAndSettle();

      expect(find.text('ফ্যামিলি মার্ট'), findsOneWidget);
      expect(find.text('সব শাখা'), findsOneWidget);
    });
  });
}
