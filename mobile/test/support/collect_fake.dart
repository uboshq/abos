import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

/// ⭐ আদায়ের নকল সার্ভার — a5-এর চূড়ান্ত চুক্তির আকারে (খোলা বিল, প্রিভিউ, সংরক্ষণ, স্লিপ); JSON থেকে আসল পার্সার দিয়ে পড়া,
/// তাই চুক্তির পড়াও পরীক্ষায় আসে। নমুনা অঙ্ক চূড়ান্ত নকশা ১০০-এর পাতা ১-এর হুবহু।
const shop = CustomerRecord({
  'id': 'cus-1',
  'nameBn': 'করিম স্টোর',
  'pointName': 'ফুলপুর পয়েন্ট',
  'branchName': 'ময়মনসিংহ',
});

const cash = CollectionAccount(id: 'acc-cash', code: '1101', name: 'প্রধান নগদ', kind: 'cash');
const ownCash = CollectionAccount(id: 'acc-own', code: '1101-07', name: 'আল-আমিনের নগদ', kind: 'cash', mine: true);
const bkash = CollectionAccount(id: 'acc-bkash', code: '1105', name: 'bKash মার্চেন্ট', kind: 'mfs');
const dbbl = CollectionAccount(id: 'acc-dbbl', code: '1102', name: 'ডাচ-বাংলা ব্যাংক চলতি', kind: 'bank');

Map<String, dynamic> openBillsJson({bool monthOpen = true, bool slipRequired = false}) => {
      'customer': {
        'id': 'cus-1',
        'label': 'করিম স্টোর · ফুলপুর পয়েন্ট · ময়মনসিংহ',
        'credit_limit': '100000.0000',
        'oldest_overdue_days': 49,
      },
      // ⓘ নকশার পাতা ৬-এর অঙ্ক: বকেয়া ৳51,200 = বিল 30,000 + 15,200 + 8,000 − ফেরত 2,000
      'due': '51200.0000',
      'credit': '2000.0000',
      'month_open': monthOpen,
      'attachment_required': slipRequired,
      'items': [
        {'id': 'inv-94', 'kind': 'bill', 'no': 'INV-0094', 'date': '2026-10-02', 'due_date': '2026-10-17', 'amount': '30000.0000', 'open': '30000.0000', 'overdue_days': 8},
        {'id': 'inv-101', 'kind': 'bill', 'no': 'INV-0101', 'date': '2026-10-09', 'due_date': '2026-10-24', 'amount': '25200.0000', 'open': '15200.0000', 'overdue_days': 0},
        {'id': 'cn-7', 'kind': 'credit_note', 'no': 'CN-0007', 'date': '2026-10-12', 'amount': '-2000.0000', 'open': '-2000.0000'},
        {'id': 'inv-112', 'kind': 'bill', 'no': 'INV-0112', 'date': '2026-10-18', 'due_date': '2026-11-02', 'amount': '8000.0000', 'open': '8000.0000'},
      ],
    };

Map<String, dynamic> previewJson({List<String> stops = const [], bool needsSignature = false}) => {
      'lines': [
        {'account': '1102 ডাচ-বাংলা ব্যাংক', 'branch': 'ময়মনসিংহ', 'dr': '45000.0000', 'cr': '0.0000'},
        {'account': '1130 পাওনা · করিম স্টোর', 'branch': 'ময়মনসিংহ', 'dr': '0.0000', 'cr': '45000.0000'},
      ],
      'total_dr': '45000.0000',
      'total_cr': '45000.0000',
      'applied': '45000.0000',
      'unapplied': '0.0000',
      'summary': {'balanced': true, 'amount_words': 'পঁয়তাল্লিশ হাজার টাকা মাত্র'},
      'needs_signature': needsSignature,
      'stops': stops,
    };

class FakeCollect implements CollectionEntryApi {
  FakeCollect({
    this.shops = const [shop],
    this.accountList = const [cash, ownCash, bkash, dbbl],
    Map<String, dynamic>? bills,
    this.oldServer = false,
    this.noPreview = false,
    Map<String, dynamic>? preview,
    Map<String, dynamic>? saved,
    this.refusal,
    this.offlineTimes = 0,
    this.slowTimes = 0,
    this.billsFail = false,
    this.transferModes = const [
      {'id': 'tm-npsb', 'name': 'NPSB'},
      {'id': 'tm-beftn', 'name': 'BEFTN'},
      {'id': 'tm-rtgs', 'name': 'RTGS'},
    ],
  })  : billsJson = bills ?? openBillsJson(),
        previewData = preview ?? previewJson(),
        savedDetails = saved ??
            const {
              'no': 'RCV-2610-0012',
              'state': 'posted',
              'amount_words': 'পঁয়তাল্লিশ হাজার টাকা মাত্র',
              'receipt_pdf': '/documents/Collection/col-1/receipt.pdf',
              'applied': [
                {'kind': 'bill', 'no': 'INV-0094', 'amount': '30000.0000'},
              ],
              'unapplied': '0.0000',
            };

  final List<CustomerRecord> shops;
  final List<CollectionAccount> accountList;
  final Map<String, dynamic> billsJson;

  /// খোলা বিল আনতে সার্ভার ভুল দিল (৫০০) — পর্দা সার্ভারকে বিল বসাতে দেয়
  final bool billsFail;

  /// সেটআপের `transfer_modes` — পুরনো সার্ভারে পাঠানো হয় না
  final List<Map<String, dynamic>> transferModes;

  /// ⛔ পুরনো সার্ভার — খোলা বিল, প্রিভিউ আর স্লিপের দরজা নেই, সংরক্ষণে কেবল আইডি
  final bool oldServer;
  final bool noPreview;
  Map<String, dynamic> previewData;
  final Map<String, dynamic> savedDetails;
  String? refusal;
  int offlineTimes;

  /// পাঠানো শেষ, উত্তর আসেনি (সময় পেরিয়েছে) — সার্ভারে বসে গেছে, ফোন জানে না
  int slowTimes;

  final List<CollectionDraft> sent = [];
  final List<String> keys = [];
  final List<CollectionDraft> previewed = [];
  final List<(String, String)> slips = [];

  /// রসিদ — একই চাবিতে একটাই (সার্ভারের changeId-পাহারা)
  final Map<String, String> receipts = {};

  @override
  Future<CollectionSetup> setup() async => CollectionSetup.fromJson({
        'accounts': [for (final a in accountList) a.toJson()],
        if (!oldServer) 'transfer_modes': transferModes,
      });

  @override
  List<CustomerRecord> customers() => shops;

  @override
  Future<OpenBills?> openBills(String customerId) async {
    if (billsFail) throw StateError('500');
    return oldServer ? null : OpenBills.fromJson(billsJson);
  }

  @override
  Future<CollectionPreview?> preview(CollectionDraft draft) async {
    if (oldServer || noPreview) return null;
    previewed.add(draft);
    return CollectionPreview.fromJson(previewData);
  }

  @override
  Future<PushOutcome> send(CollectionDraft draft, String changeId) async {
    keys.add(changeId);
    if (offlineTimes > 0) {
      offlineTimes--;
      throw const NoNetworkForThis();
    }
    if (refusal != null) return PushOutcome(refusal: refusal);
    final id = receipts.putIfAbsent(changeId, () {
      sent.add(draft);
      return 'col-${receipts.length + 1}';
    });
    if (slowTimes > 0) {
      slowTimes--;
      throw const NoNetworkForThis(mayHaveLanded: true);
    }
    return PushOutcome(landedId: id, details: oldServer ? {'changeId': changeId, 'status': 'APPLIED', 'entityId': id} : savedDetails);
  }

  @override
  Future<void> attachSlip(String collectionId, String path) async {
    if (oldServer) throw StateError('no attachment door on an old server');
    slips.add((collectionId, path));
  }
}

class FakeBooks implements BooksApi {
  @override
  Future<MoneyInDetail> moneyInDetail(String id) async => const MoneyInDetail(
        row: MoneyInRow(
            id: 'col-1',
            no: 'COL-0042',
            date: '2026-10-07',
            customer: 'করিম স্টোর · ফুলপুর পয়েন্ট',
            account: 'প্রধান নগদ',
            method: 'cash',
            amount: 45000),
        status: 'confirmed',
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
