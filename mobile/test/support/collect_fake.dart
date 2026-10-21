import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

/// ⓘ উত্তরগুলো a6-এর আসল সার্ভারের হুবহু আকারে (d5cc9657f: CollectionApiController::setup/answer/attachment,
/// CollectionOpenItems::for, CollectionService::preview; tests/Feature/Api/CollectionAppContractTest আর CollectionSyncAnswerTest)।
///
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
        'branch': 'ময়মনসিংহ',
      },
      // ⓘ নকশার পাতা ৬-এর অঙ্ক: বকেয়া ৳51,200 = বিল 30,000 + 15,200 + 8,000 − ফেরত 2,000
      'due': '51200.0000',
      'credit': '2000.0000',
      'month_open': monthOpen,
      'attachment_required': slipRequired,
      'items': [
        // ⓘ সার্ভারের সারি: দেরি না হলে `overdue_days: null`; নোট আর ক্রেডিটে `due_date: null`; পর্দার জন্য বাড়তি `url`
        {'id': 'inv-94', 'kind': 'bill', 'no': 'INV-0094', 'date': '2026-10-02', 'due_date': '2026-10-17', 'amount': '30000.0000', 'open': '30000.0000', 'overdue_days': 8, 'url': 'https://erp.test/sales/invoices/94'},
        {'id': 'inv-101', 'kind': 'bill', 'no': 'INV-0101', 'date': '2026-10-09', 'due_date': '2026-10-24', 'amount': '25200.0000', 'open': '15200.0000', 'overdue_days': null, 'url': 'https://erp.test/sales/invoices/101'},
        {'id': 'cn-7', 'kind': 'credit_note', 'no': 'CN-0007', 'date': '2026-10-12', 'due_date': null, 'amount': '-2000.0000', 'open': '-2000.0000', 'overdue_days': null, 'url': 'https://erp.test/accounts/notes/7'},
        {'id': 'inv-112', 'kind': 'bill', 'no': 'INV-0112', 'date': '2026-10-18', 'due_date': '2026-11-02', 'amount': '8000.0000', 'open': '8000.0000', 'overdue_days': null, 'url': 'https://erp.test/sales/invoices/112'},
      ],
    };

Map<String, dynamic> previewJson({List<String> stops = const [], bool needsSignature = false}) => {
      'lines': [
        {'account': '1102 ডাচ-বাংলা ব্যাংক', 'branch': 'ময়মনসিংহ', 'dr': '45000.0000', 'cr': '0.0000'},
        {'account': '1130 পাওনা · করিম স্টোর', 'branch': 'ময়মনসিংহ', 'dr': '0.0000', 'cr': '45000.0000'},
      ],
      'total_dr': '45000.0000',
      'total_cr': '45000.0000',
      // ⓘ সার্ভারের `applied` প্রিভিউতে তালিকা (কোন কাগজে কত), সারাংশে মোট
      'applied': [
        {'kind': 'bill', 'no': 'INV-0094', 'amount': '30000.0000'},
        {'kind': 'bill', 'no': 'INV-0101', 'amount': '15200.0000'},
        {'kind': 'bill', 'no': 'INV-0112', 'amount': '1800.0000'},
        {'kind': 'credit_note', 'no': 'CN-0007', 'amount': '-2000.0000'},
      ],
      'unapplied': '0.0000',
      'summary': {
        'received': '45000.0000',
        'vds': '0.0000',
        'ait': '0.0000',
        'difference': '0.0000',
        'applied': '45000.0000',
        'unapplied': '0.0000',
        'amount_words': 'পঁয়তাল্লিশ হাজার টাকা মাত্র',
        'balanced': true,
      },
      'needs_signature': needsSignature,
      'reference_free': true,
      'stops': stops,
    };


/// ⭐ খাতায় যা যাবে — a6-এর সার্ভারের হুবহু নিয়মে (d5cc9657f, CollectionService::ledgerLines; পোস্ট আর প্রিভিউ একই জায়গা থেকে):
///
///     Dr  টাকার খাত            পাওয়া টাকা − ব্যাংক চার্জ
///     Dr  ৫২১০ ব্যাংক চার্জ / ৫২১১ মোবাইল ব্যাংকিং চার্জ   চার্জ
///     Dr  ১১৩৮ উৎসে কাটা ভ্যাট পাওনা   VDS
///     Dr  ১১৩৫ অগ্রিম আয়কর           AIT
///     Dr  ৪১২০ আদায়ে বিক্রয় ছাড়  বা  ৫৩০৫ রাউন্ডিং পার্থক্য   ছাড়/রাউন্ডিং
///     Cr  ১১১০ প্রাপ্য হিসাব · গ্রাহক   সবগুলোর যোগ (অপ্রয়োগকৃত অংশও এখানে)
///
/// ⓘ [unbalanced]: পরীক্ষার জন্য ভুল সার্ভার — Cr এক পয়সা কম, আর `balanced: false`
({List<Map<String, String>> lines, int dr, int cr}) serverJournal(CollectionDraft d, List<CollectionAccount> accounts,
    {bool unbalanced = false}) {
  final amount = paisaOf(d.amount);
  final charge = paisaOf(d.bankCharge);
  final vds = paisaOf(d.vds);
  final ait = paisaOf(d.ait);
  final difference = paisaOf(d.difference?.amount);
  final gross = amount + vds + ait + difference;
  final money = accounts.where((a) => a.id == d.accountId).firstOrNull;
  String text(int paisa) => paisaText(paisa);
  final lines = <Map<String, String>>[
    {'account': '${money?.code ?? ''} ${money?.name ?? ''}'.trim(), 'branch': 'ময়মনসিংহ', 'dr': text(amount - charge), 'cr': '0.00'},
    if (charge > 0)
      {'account': money?.kind == 'mfs' ? '5211 মোবাইল ব্যাংকিং চার্জ' : '5210 ব্যাংক চার্জ', 'branch': 'ময়মনসিংহ', 'dr': text(charge), 'cr': '0.00'},
    if (vds > 0) {'account': '1138 উৎসে কাটা ভ্যাট পাওনা', 'branch': 'ময়মনসিংহ', 'dr': text(vds), 'cr': '0.00'},
    if (ait > 0) {'account': '1135 অগ্রিম আয়কর', 'branch': 'ময়মনসিংহ', 'dr': text(ait), 'cr': '0.00'},
    if (difference > 0)
      {
        'account': d.difference!.kind == 'discount' ? '4120 আদায়ে বিক্রয় ছাড়' : '5305 রাউন্ডিং পার্থক্য',
        'branch': 'ময়মনসিংহ',
        'dr': text(difference),
        'cr': '0.00',
      },
    {'account': '1110 প্রাপ্য হিসাব · করিম স্টোর', 'branch': 'ময়মনসিংহ', 'dr': '0.00', 'cr': text(unbalanced ? gross - 1 : gross)},
  ];
  final dr = lines.fold(0, (s, l) => s + paisaOf(l['dr']));
  final cr = lines.fold(0, (s, l) => s + paisaOf(l['cr']));
  return (lines: lines, dr: dr, cr: cr);
}

/// সার্ভারের সইয়ের নিয়ম (Settlement::differenceNeedsOwner): ছাড় যেকোনো অঙ্কে, রাউন্ডিং ৳0.50-এর বেশি হলে
bool serverNeedsOwner(CollectionDraft d, {int above = 50}) {
  final amount = paisaOf(d.difference?.amount);
  if (amount <= 0) return false;
  return d.difference!.kind == 'discount' || amount > above;
}

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
    this.slipState = 'posted',
    this.unbalancedPreview = false,
    this.outOfReach = false,
    this.notAllowed = false,
    this.roundingLimit = '10.0000',
    this.roundingSignAbove = '0.5000',
    this.transferModes = const [
      {'id': 'tm-beftn', 'code': 'BEFTN', 'name': 'BEFTN'},
      {'id': 'tm-npsb', 'code': 'NPSB', 'name': 'NPSB'},
      {'id': 'tm-rtgs', 'code': 'RTGS', 'name': 'RTGS'},
    ],
  })  : billsJson = bills ?? openBillsJson(),
        previewData = preview ?? previewJson(),
        savedDetails = saved ??
            const {
              'no': 'RCV-2610-0012',
              'state': 'posted',
              'amount_words': 'পঁয়তাল্লিশ হাজার টাকা মাত্র',
              // ⓘ সার্ভারের রসিদ — ফোনের কাগজের দরজা (a6 8006bd78e: route('api.documents.pdf'))
              'receipt_pdf': 'https://erp.test/api/v1/documents/Collection/col-1/pdf',
              'applied': [
                {'kind': 'bill', 'no': 'INV-0094', 'amount': '30000.0000'},
                {'kind': 'bill', 'no': 'INV-0101', 'amount': '15200.0000'},
                {'kind': 'bill', 'no': 'INV-0112', 'amount': '1800.0000'},
                {'kind': 'credit_note', 'no': 'CN-0007', 'amount': '-2000.0000'},
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

  /// স্লিপের উত্তরের `state` — "পোস্ট" চাওয়া খসড়া স্লিপ পেলে সার্ভার পোস্ট করে
  final String slipState;

  /// ভুল সার্ভার — প্রিভিউর Dr ≠ Cr আর `balanced: false`
  final bool unbalancedPreview;

  /// গ্রাহক নাগালের বাইরে — সার্ভারের ইচ্ছাকৃত ৪০৪ (খোলা বিল আর প্রিভিউ দুটোতেই)
  final bool outOfReach;

  /// দেয়ালের SR — আদায়ের প্রতিটা দরজায় ৪০৩ (a6 8006bd78e)
  final bool notAllowed;

  /// সেটআপের রাউন্ডিংয়ের দুই সীমা, সার্ভারের ৪ দশমিকের লেখায়; null মানে সার্ভার পাঠায় না (পুরনো)
  final String? roundingLimit;
  final String? roundingSignAbove;

  /// শেষ প্রিভিউর উত্তর — দাবিগুলো খাতার যোগ মেলায়
  CollectionPreview? lastPreview;

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
  Future<CollectionSetup> setup() async => notAllowed ? throw const CollectionNotAllowed() : CollectionSetup.fromJson({
        'today': '2026-10-21',
        if (!oldServer && roundingLimit != null) 'rounding_limit': roundingLimit,
        if (!oldServer && roundingSignAbove != null) 'rounding_sign_above': roundingSignAbove,
        // ⓘ সার্ভার প্রতিটা খাতে `mine` পাঠায়, সত্য বা মিথ্যা (CollectionAppContractTest-এর দাবি); পুরনো সার্ভারে নেই
        'accounts': [
          for (final a in accountList) {'id': a.id, 'code': a.code, 'name': a.name, 'kind': a.kind, if (!oldServer) 'mine': a.mine},
        ],
        if (!oldServer) 'transfer_modes': transferModes,
      });

  @override
  List<CustomerRecord> customers() => shops;

  @override
  Future<OpenBills?> openBills(String customerId) async {
    if (billsFail) throw StateError('500');
    if (notAllowed) throw const CollectionNotAllowed();
    if (outOfReach) throw const CustomerOutOfReach();
    return oldServer ? null : OpenBills.fromJson(billsJson);
  }

  @override
  Future<CollectionPreview?> preview(CollectionDraft draft) async {
    if (outOfReach) throw const CustomerOutOfReach();
    if (oldServer || noPreview) return null;
    previewed.add(draft);
    // ⓘ খাতার লাইন পাঠানো আদায় থেকে, সার্ভারের নিয়মে; থামার কারণ, কথায় টাকা আর জোর-করা সই previewData থেকে
    final j = serverJournal(draft, accountList, unbalanced: unbalancedPreview);
    final summary = Map<String, dynamic>.from(previewData['summary'] as Map? ?? const {});
    final json = <String, dynamic>{
      ...previewData,
      'lines': j.lines,
      'total_dr': paisaText(j.dr),
      'total_cr': paisaText(j.cr),
      'needs_signature': previewData['needs_signature'] == true ||
          serverNeedsOwner(draft, above: roundingSignAbove == null ? 50 : paisaOf(roundingSignAbove)),
      'summary': {...summary, 'balanced': !unbalancedPreview && j.dr == j.cr},
    };
    return lastPreview = CollectionPreview.fromJson(json);
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
    // ⓘ সিঙ্কের উত্তর-সারি: চেনা তিন ঘরের সাথে আদায়ের উত্তর এক সারিতে (SyncService::withAnswer)
    final row = {'changeId': changeId, 'status': 'APPLIED', 'entityId': id};
    return PushOutcome(landedId: id, details: oldServer ? row : {...savedDetails, ...row});
  }

  @override
  Future<String?> attachSlip(String collectionId, String path) async {
    if (oldServer) throw StateError('no attachment door on an old server');
    slips.add((collectionId, path));
    // ⓘ সার্ভারের উত্তর ২০১: {id, name, state}
    return slipState;
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
