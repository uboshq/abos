import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/orders/direct_sale_api.dart';
import 'package:abos_mobile/core/orders/paper_scan_api.dart';
import 'package:abos_mobile/core/orders/quotation_api.dart';
import 'package:abos_mobile/core/orders/sales_return_api.dart';
import 'package:abos_mobile/core/orders/tracking_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/features/deliveries/deliveries_screen.dart';
import 'package:abos_mobile/features/loading/loading_screens.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ অ্যাপে সব জায়গায় গ্রাহকের পাশে পয়েন্ট — মালিক, ৭ অক্টোবর ২০২৬: *"app e sob jaygay customer er pase obosoi
/// point dibe nahoy cina zayna buja zayna"* (সার্ভার `Customer::pointName()`, EveryPhoneDoorNamesTheShopsPointTest)।
void main() {
  test('a name gets its point once; no point, a blank point or an old server leaves the name alone', () {
    expect(withPoint('রহিম স্টোর', 'কারওয়ান বাজার'), 'রহিম স্টোর · কারওয়ান বাজার');
    expect(withPoint('রহিম স্টোর', null), 'রহিম স্টোর');
    expect(withPoint('রহিম স্টোর', '  '), 'রহিম স্টোর');
    expect(withPoint('রহিম স্টোর · কারওয়ান বাজার', 'কারওয়ান বাজার'), 'রহিম স্টোর · কারওয়ান বাজার', reason: 'দুইবার জোড়া নয়');
    expect(withPointOrNull(null, 'কারওয়ান বাজার'), isNull);

    const shop = CustomerRecord({'id': 'c1', 'nameBn': 'রহিম স্টোর', 'pointName': 'কারওয়ান বাজার'});
    expect(shop.label, 'রহিম স্টোর · কারওয়ান বাজার');
    expect(shop.name, 'রহিম স্টোর', reason: 'খোঁজা আর সাজানো নামেই থাকে');
    expect(const CustomerRecord({'id': 'c2', 'nameBn': 'করিম'}).label, 'করিম');
  });

  test('every door that names a shop shows its point beside the name', () {
    const object = {'name': 'রহিম স্টোর', 'point': 'কারওয়ান বাজার'};
    const want = 'রহিম স্টোর · কারওয়ান বাজার';
    final flat = {'customer': 'রহিম স্টোর', 'customer_point': 'কারওয়ান বাজার'};

    expect(DeliveryOrder.fromJson({'customer': object}).customer, want, reason: 'আদেশ ও DO');
    expect(Quotation.fromJson({'customer': object}).customer, want, reason: 'দরপত্র');
    expect(CounterDraftSummary.fromJson(flat).customer, want, reason: 'কাউন্টারের খসড়া');
    expect(CounterDraft.fromJson({'customer': 'c1', 'customerName': 'রহিম স্টোর', 'customerPoint': 'কারওয়ান বাজার'}).customerName, want);
    expect(ReturnSetup.fromJson({'invoices': [{'id': 'i1', ...flat}]}).invoices.single.customer, want, reason: 'ফেরতের বিল');
    expect(ReturnBill.fromJson({'customerName': 'রহিম স্টোর', 'customerPoint': 'কারওয়ান বাজার'}).customerName, want);
    expect(TrackedSale.fromJson(flat).customer, want, reason: 'ট্র্যাকিং');
    expect(MoneyInRow.fromJson(flat).customer, want, reason: 'আদায়');
    expect(DeliveryRunRow.fromJson(flat).customer, want, reason: 'আজকের ডেলিভারি');
    expect(LoadingChallan.fromJson(flat).customer, want, reason: 'লোডিং');
    expect(ScannedPaper.fromJson({'customer': {...object, 'code': 'C-1'}}).customer, '$want · C-1', reason: 'QR-এ খোলা কাগজ');

    // ⓘ পুরনো সার্ভার — পয়েন্ট নেই, নামটাই
    expect(MoneyInRow.fromJson({'customer': 'রহিম স্টোর'}).customer, 'রহিম স্টোর');
    expect(DeliveryOrder.fromJson({'customer': {'name': 'রহিম স্টোর'}}).customer, 'রহিম স্টোর');
  });
}
