import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/records/sales_order_record.dart';
import 'package:abos_mobile/core/records/stock_record.dart';

/// The two halves of the sync contract, checked against each other.
///
/// <p>docs/Contract — মোবাইল সিঙ্ক প্রোটোকল opens by saying that if either
/// side changes the other breaks silently, that no compiler watches this
/// boundary, and that the document is therefore the only guard. It was not
/// enough of one. Every screen in this app was reading key names the server
/// has never sent:
///
/// | screen read | server sends | what the rep saw |
/// |---|---|---|
/// | `name` | `nameBn` / `nameEn` | every shop and product "নাম নেই" |
/// | `address`, `mobile` | `addressBn`, `phone` | no address, ever |
/// | `salesPrice` | `salePrice` | **no selling price at all** |
/// | `quantity`, `productName` | `available`, (the Product) | every stock row 0 |
/// | `orderNumber` | `documentNo` | every confirmed order "নম্বর নেই" |
/// | `items[].quantity` | `lines[].qty` | **every order refused** |
///
/// <p>None of it was visible to `flutter analyze`, because every one of those
/// reads is a valid `Map` lookup that yields null, and every screen handled
/// null by drawing nothing. The app compiled, the tests passed, and it did
/// not work.
///
/// <p>So the payload literals below are copied from the PHP handlers, by
/// hand, with the file each came from named. They are not fixtures made up to
/// suit this app; that is the whole point. When a handler's `payload:` array
/// changes, this file is what goes red.
void main() {
  group('Customer — app/Modules/Customer/Sync/CustomerSync.php', () {
    // Copied from that file's payload: array.
    const payload = <String, dynamic>{
      'id': '01a0c3f0-0000-7000-8000-000000000001',
      'code': 'C-0001',
      'nameEn': 'Rahim Store',
      'nameBn': 'রহিম স্টোর',
      'ownerName': 'Abdur Rahim',
      'phone': '01711000000',
      'addressEn': 'Mirpur 10',
      'addressBn': 'মিরপুর ১০',
      'customerType': 'retail',
      'creditLimit': '50000.0000',
      'creditDays': 15,
      'isActive': true,
    };

    test('reads the name, phone and address the server actually sends', () {
      const customer = CustomerRecord(payload);

      expect(customer.id, '01a0c3f0-0000-7000-8000-000000000001');
      expect(customer.name, 'রহিম স্টোর',
          reason: 'Bengali first — every label around it is Bengali');
      expect(customer.phone, '01711000000');
      expect(customer.address, 'মিরপুর ১০');
      expect(customer.ownerName, 'Abdur Rahim');
    });

    test('falls back to the English name, then the code, never to a blank',
        () {
      expect(const CustomerRecord({'nameEn': 'Rahim Store'}).name,
          'Rahim Store');
      expect(const CustomerRecord({'code': 'C-0001'}).name, 'C-0001');
      expect(const CustomerRecord({}).name, 'নাম নেই');
    });

    test('search matches either language, the code, the owner and the phone',
        () {
      const customer = CustomerRecord(payload);

      expect(customer.matches('রহিম'), isTrue);
      expect(customer.matches('rahim'), isTrue,
          reason: 'a rep who knows the shop by its English name must find it');
      expect(customer.matches('C-0001'), isTrue);
      expect(customer.matches('01711'), isTrue);
      expect(customer.matches(''), isTrue);
      expect(customer.matches('কিছু-একটা'), isFalse);
    });
  });

  group('CustomerDue — app/Modules/Customer/Sync/CustomerDueSync.php', () {
    test('reads the outstanding balance and the limit', () {
      // Copied from that file's payload: array.
      const due = CustomerDueRecord({
        'customerId': '01a0c3f0-0000-7000-8000-000000000001',
        'outstanding': '8125.5000',
        'creditLimit': '50000.0000',
        'creditDays': 15,
        'isActive': true,
      });

      expect(due.customerId, '01a0c3f0-0000-7000-8000-000000000001');
      expect(due.outstanding, 8125.5);
      expect(due.creditLimit, 50000);
      expect(due.creditDays, 15);
      expect(due.outstandingLabel, contains('বকেয়া'));
    });

    test('a negative balance is an advance, not a debt', () {
      const due = CustomerDueRecord({'outstanding': '-2000.0000'});

      expect(due.outstanding, -2000);
      expect(due.outstandingLabel, startsWith('অগ্রিম'));
    });

    test('a zero credit limit is not read as a limit at all', () {
      // CustomerDueSync says it plainly: zero means cash/advance, and whether
      // that blocks a sale is a company switch the phone is not sent. So the
      // screen must have a way to draw no limit rather than "সীমা ৳0".
      const due = CustomerDueRecord({
        'outstanding': '0.0000',
        'creditLimit': '0.0000',
      });

      expect(due.hasCreditLimit, isFalse);
      expect(due.outstandingLabel, 'বকেয়া নেই');
    });
  });

  group('Product — app/Modules/Inventory/Sync/ProductSync.php', () {
    // Copied from that file's payload: array — as it arrives for a role
    // WITHOUT inventory.cost.view, which is to say with no purchasePrice key
    // at all (docs/Contract §৩ rule ঙ).
    const payload = <String, dynamic>{
      'id': '01a0c3f0-0000-7000-8000-000000000009',
      'code': 'P-0001',
      'nameEn': 'Lifebuoy Soap 100g',
      'nameBn': 'লাইফবয় সাবান ১০০গ্রাম',
      'barcode': '8901030',
      'unitCode': 'PCS',
      'unitNameBn': 'পিস',
      'salePrice': '42.5000',
      'isActive': true,
    };

    test('reads the selling price the catalogue screen never showed', () {
      const product = ProductRecord(payload);

      expect(product.salePrice, 42.5);
      expect(product.salePriceRaw, '42.5000',
          reason: 'the rate sent back on an order must be the characters the '
              'server sent, not a re-rendered double — the price tolerance '
              'rule measures against exactly that figure');
      expect(product.name, 'লাইফবয় সাবান ১০০গ্রাম');
      expect(product.unit, 'পিস');
    });

    test('an absent purchase price is absent, not null and not zero', () {
      const withoutCost = ProductRecord(payload);
      expect(withoutCost.hasPurchasePrice, isFalse);

      const withCost = ProductRecord({...payload, 'purchasePrice': '31.0000'});
      expect(withCost.hasPurchasePrice, isTrue);
      expect(withCost.purchasePrice, 31);
    });

    test('search matches either language, the code and the barcode', () {
      const product = ProductRecord(payload);

      expect(product.matches('লাইফবয়'), isTrue);
      expect(product.matches('lifebuoy'), isTrue);
      expect(product.matches('P-0001'), isTrue);
      expect(product.matches('8901030'), isTrue);
    });
  });

  group('StockOnHand — app/Modules/Inventory/Sync/StockOnHandSync.php', () {
    test('reads all five figures, not just the sellable one', () {
      // Copied from that file's payload: array.
      const stock = StockRecord({
        'productId': '01a0c3f0-0000-7000-8000-000000000009',
        'floor': '100.0000',
        'reserved': '55.0000',
        'hold': '5.0000',
        'available': '40.0000',
        'freeAvailable': '0.0000',
      });

      expect(stock.floor, 100);
      expect(stock.reserved, 55);
      expect(stock.hold, 5);
      expect(stock.available, 40);
      expect(stock.hasCommitments, isTrue,
          reason: 'a rep who sees 100 on the shelf but 40 sellable has to be '
              'told where the other 60 went, or they tell the shop "স্টক নাই"');
    });

    test('a shelf with nothing committed needs no breakdown', () {
      const stock = StockRecord({
        'floor': '40.0000',
        'reserved': '0.0000',
        'hold': '0.0000',
        'available': '40.0000',
      });

      expect(stock.hasCommitments, isFalse);
    });
  });

  group('SalesOrder pull — app/Modules/Sales/Sync/SalesOrderSync.php', () {
    test('reads the document number the order screen never showed', () {
      // Copied from that file's pull() payload: array.
      const order = SalesOrderRecord({
        'id': '01a0c3f0-0000-7000-8000-00000000000f',
        'documentNo': 'SO-2609-0042',
        'customerId': '01a0c3f0-0000-7000-8000-000000000001',
        'trxDate': '2026-09-05',
        'deliverOn': null,
        'status': 'confirmed',
        'total': '1275.0000',
        'narration': 'সকালের রাউন্ড',
      });

      expect(order.documentNo, 'SO-2609-0042',
          reason: 'the number the rep could not give offline is the whole '
              'reason this section of the screen exists');
      expect(order.trxDate, DateTime(2026, 9, 5));
      expect(order.total, 1275);
      expect(order.statusLabel, 'নিশ্চিত');
    });

    test('DocumentStatus values are named in the language the app speaks', () {
      expect(const SalesOrderRecord({'status': 'draft'}).statusLabel, 'খসড়া');
      expect(const SalesOrderRecord({'status': 'cancelled'}).statusLabel,
          'বাতিল');
      expect(const SalesOrderRecord({'status': 'closed'}).statusLabel, 'বন্ধ');
      // An unknown status is shown as it came — information, not an error.
      expect(const SalesOrderRecord({'status': 'somethingNew'}).statusLabel,
          'somethingNew');
    });
  });

  group('SalesOrder push — SalesOrderSync::apply()', () {
    const product = ProductRecord({
      'id': '01a0c3f0-0000-7000-8000-000000000009',
      'nameBn': 'লাইফবয় সাবান ১০০গ্রাম',
      'salePrice': '42.5000',
    });

    SalesOrderDraft draft() => SalesOrderDraft(
          customerId: '01a0c3f0-0000-7000-8000-000000000001',
          lines: [SalesOrderDraftLine(product: product, quantity: 3)],
          narration: 'সকালের রাউন্ড',
          trxDate: DateTime(2026, 9, 5),
        );

    test('the queued payload is under the key the server reads', () {
      // This is the bug this whole file was written for. apply() does:
      //
      //     foreach ((array) ($payload['lines'] ?? []) as $line) { … }
      //     if ($lines === []) {
      //         throw new SyncRejection(__('sales::sync.order_has_no_lines'));
      //     }
      //
      // The screen was queueing `items`. Every order this app had ever
      // written would have come back REJECTED, with a reason naming nothing
      // the rep did and giving them nothing to change.
      final payload = draft().toPayload();

      expect(payload['lines'], isA<List>());
      expect(payload['lines'], isNotEmpty,
          reason: 'an empty lines array is exactly what the server refuses');
      expect(payload.containsKey('items'), isFalse);
    });

    test('a line carries productId, qty and rate under those names', () {
      final line = (draft().toPayload()['lines'] as List).single as Map;

      expect(line['productId'], '01a0c3f0-0000-7000-8000-000000000009');
      expect(line['qty'], 3, reason: 'apply() reads qty, never quantity');
      expect(line['rate'], '42.5000',
          reason: 'the catalogue price passed back verbatim — apply() hands '
              'it to the price tolerance rule, and absent it defaults to 0');
    });

    test('the note travels as narration, and the date as the day taken', () {
      final payload = draft().toPayload();

      expect(payload['narration'], 'সকালের রাউন্ড',
          reason: 'apply() reads narration; a `note` key was silently dropped');
      expect(payload['trxDate'], '2026-09-05',
          reason: 'an order written on Friday with no signal and drained on '
              'Monday must not be dated Monday — apply() falls back to now()');
      expect(payload['customerId'], '01a0c3f0-0000-7000-8000-000000000001');
    });

    test('an empty note is left out rather than sent blank', () {
      final payload = SalesOrderDraft(
        customerId: 'c1',
        lines: [SalesOrderDraftLine(product: product)],
        narration: '   ',
      ).toPayload();

      expect(payload.containsKey('narration'), isFalse);
      expect(payload.containsKey('trxDate'), isFalse);
    });

    test('a queued order reads back for a retry', () {
      final read = SalesOrderDraft.fromPayload(draft().toPayload());

      expect(read, isNotNull);
      expect(read!.customerId, '01a0c3f0-0000-7000-8000-000000000001');
      expect(read.lines.single.$1, '01a0c3f0-0000-7000-8000-000000000009');
      expect(read.lines.single.$2, 3);
    });

    test('an order queued by the old build still reads back', () {
      // Those rows are on the handsets that ran the September emulator round,
      // and they are precisely the rows that were rejected — a rep reopening
      // one must see their order, not an empty form.
      final read = SalesOrderDraft.fromPayload(const {
        'customerId': 'c1',
        'items': [
          {'productId': 'p1', 'quantity': 2},
        ],
        'note': 'পুরনো আকার',
      });

      expect(read, isNotNull);
      expect(read!.lines.single, ('p1', 2));
    });
  });


  // ──────────────────────────────────────────────────────────────────────
  // Everything above is a COPY of the server's payloads. A copy cannot go
  // stale loudly: rename a key in PHP tomorrow and every test above stays
  // green, because not one of them has ever opened the PHP.
  //
  // <p>Three sessions working the server side made the same point about this
  // file within an hour of each other, and all three had been bitten by it
  // that same day: a guard that was green while seeing nothing. Their
  // question is the right one — *if the thing this test reads came back
  // empty, would it still pass?* — so the group below actually opens the
  // handler files and looks for the keys this app depends on. It is the
  // difference between a test and a certificate.
  group('the handlers themselves — a link, not a copy', () {
    // Relative to the package root, which is `flutter test`'s own working
    // directory. The server and this app live in one repository precisely so
    // that this path exists.
    const handlerRoot = '../app/Modules';

    // The keys each screen would silently draw nothing for. Not every key in
    // the payload — only the ones whose disappearance costs a rep something.
    const pulled = <String, List<String>>{
      '$handlerRoot/Customer/Sync/CustomerSync.php': [
        'nameBn', 'nameEn', 'phone', 'addressBn', 'code',
      ],
      '$handlerRoot/Customer/Sync/CustomerDueSync.php': [
        'customerId', 'outstanding', 'creditLimit', 'creditDays',
      ],
      '$handlerRoot/Inventory/Sync/ProductSync.php': [
        'nameBn', 'nameEn', 'salePrice', 'purchasePrice', 'unitNameBn', 'code',
      ],
      '$handlerRoot/Inventory/Sync/StockOnHandSync.php': [
        'productId', 'floor', 'reserved', 'hold', 'available',
      ],
      '$handlerRoot/Sales/Sync/SalesOrderSync.php': [
        'documentNo', 'customerId', 'trxDate', 'status', 'total',
      ],
    };

    test('the handler files are all where this test expects them', () {
      // The boring assertion the server-side sessions asked for by name. A
      // loop over an empty map asserts nothing and passes; a renamed or moved
      // handler would empty it silently. So the count is stated out loud.
      expect(pulled, hasLength(5),
          reason: 'five sync handlers feed this app — see GET '
              '/sync/capabilities in docs/Contract §২');

      for (final path in pulled.keys) {
        expect(File(path).existsSync(), isTrue,
            reason: '$path is gone or moved. This test deliberately fails '
                'rather than skipping: a skip here is exactly the silence '
                'this whole file exists to break');
      }
    });

    for (final entry in pulled.entries) {
      final name = entry.key.split('/').last;

      test('$name still sends the keys this app reads', () {
        final source = File(entry.key).readAsStringSync();
        expect(source, isNotEmpty, reason: '${entry.key} read back empty');

        for (final key in entry.value) {
          expect(source, contains("'$key' =>"),
              reason: "$name no longer sends '$key'. Whatever reads it in "
                  'lib/core/records/ now draws nothing, and no screen will '
                  'say so — that is the failure this test exists to catch');
        }
      });
    }

    test('SalesOrderSync::apply still reads the keys this app pushes', () {
      // The push half, which is where the real bug was: the app wrote
      // `items`/`quantity`/`note`, apply() reads these.
      final source =
          File('$handlerRoot/Sales/Sync/SalesOrderSync.php').readAsStringSync();

      for (final read in const [
        r"$payload['customerId']",
        r"$payload['lines']",
        r"$line['productId']",
        r"$line['qty']",
        r"$line['rate']",
        r"$payload['trxDate']",
        r"$payload['narration']",
      ]) {
        expect(source, contains(read),
            reason: 'apply() no longer reads $read — SalesOrderDraft.toPayload '
                'is now writing a key the server ignores, and an order that '
                'loses its lines comes back REJECTED with order_has_no_lines');
      }
    });
  });


  // The sync handlers were not the only unguarded boundary — `/me` and the
  // conflict list are read key-by-key in core/ the same way, and nothing
  // watched those either. They turned out to be correct (audited by hand on
  // 13 September against the controllers below, no mismatch found), which is
  // worth keeping true rather than re-discovering.
  group('the controllers — the other half of the wire', () {
    const controllers = <String, List<String>>{
      '../app/Http/Controllers/Api/MeController.php': [
        // core/menu/me_api.dart + session_profile.dart
        'user', 'company', 'branch', 'permissions', 'menu',
        'public_id', 'name', 'email', 'locale', 'roles',
        // core/menu/menu_module.dart
        'code', 'label', 'section', 'order', 'groups', 'route', 'planned',
      ],
      '../app/Http/Controllers/Api/SyncController.php': [
        // core/sync_engine/sync_history_api.dart
        'lastSyncedAt', 'module', 'entityType', 'entityId', 'reason',
        'status', 'detectedAt', 'outcomes',
      ],
    };

    test('both controllers are where this test expects them', () {
      expect(controllers, hasLength(2));
      for (final path in controllers.keys) {
        expect(File(path).existsSync(), isTrue, reason: '$path is gone');
      }
    });

    for (final entry in controllers.entries) {
      final name = entry.key.split('/').last;

      test('$name still answers with the keys core/ reads', () {
        final source = File(entry.key).readAsStringSync();
        expect(source, isNotEmpty);

        for (final key in entry.value) {
          expect(source, contains("'$key' =>"),
              reason: "$name no longer sends '$key' — whatever reads it in "
                  'lib/core/ now gets null, and null is what the screens '
                  'draw nothing for');
        }
      });
    }

    test('the id on the wire is the public one', () {
      final source =
          File('../app/Http/Controllers/Api/MeController.php').readAsStringSync();

      // docs/Contract §৩ rule ক: the sequential id never travels, because a
      // sequential id can be counted — "how many customers came before me".
      expect(source, contains("'public_id' =>"));
      expect(source, isNot(contains("'id' => \$user->id")));
    });
  });


  // A route name is the flimsiest string on this boundary: it is invented on
  // the server, travels as data, and is matched here by equality. Rename one
  // in module.php and the tile simply stops appearing — no error, no log, and
  // on a salesman's phone no way to tell a missing screen from a missing
  // permission. RouteRegistry's whole table is four such strings.
  group('route names — the menu\'s half of the wire', () {
    const routes = <String, String>{
      'customer.index': '../app/Modules/Customer/module.php',
      'sales.order.index': '../app/Modules/Sales/module.php',
      'inventory.product.index': '../app/Modules/Inventory/module.php',
      'inventory.stock.index': '../app/Modules/Inventory/module.php',
    };

    test('every route this app opens is still declared by its module', () {
      // Four, matching RouteRegistry's table exactly. Stated so that a table
      // emptied by a bad edit cannot pass this group by looping over nothing.
      expect(routes, hasLength(4));

      routes.forEach((route, modulePath) {
        final file = File(modulePath);
        expect(file.existsSync(), isTrue, reason: '$modulePath is gone');

        expect(file.readAsStringSync(), contains("'route' => '$route'"),
            reason: "$modulePath no longer declares '$route'. "
                'RouteRegistry maps that name to a screen this app has built; '
                'with the name gone the row never matches, the tile never '
                'draws, and nobody is told why');
      });
    });
  });
}
