import 'package:abos_mobile/core/orders/paper_scan_api.dart';
import 'package:flutter_test/flutter_test.dart';

/// কাগজের QR — সার্ভার `route('sales.qr', token)` ছাপে; টোকেন ৩৮ অক্ষর (PaperToken.php)।
void main() {
  const token = 'AbCdEfGhIjKlMnOpQrStUv0123456789-_abcd';

  test('the printed address gives the token', () {
    expect(token.length, 38);
    expect(PaperToken.from('https://erp.adi.com.bd/q/$token'), token);
    expect(PaperToken.from('  https://demo.adi.com.bd/sub/q/$token  '), token);
    expect(PaperToken.from(token), token);
  });

  test('anything else is not a paper', () {
    expect(PaperToken.from('https://erp.adi.com.bd/q/short'), isNull);
    expect(PaperToken.from('https://erp.adi.com.bd/q/${token}x'), isNull);
    expect(PaperToken.from('https://example.com/$token'), isNull);
    expect(PaperToken.from('hello'), isNull);
  });

  test('an old printed paper is told apart', () {
    const old = 'https://erp.adi.com.bd/sales/scan/9b1d2c3e-0000-4000-8000-000000000000';
    expect(PaperToken.from(old), isNull);
    expect(PaperToken.isOldPaper(old), isTrue);
  });

  test('the server answer reads into one paper', () {
    final paper = ScannedPaper.fromJson({
      'document_no': 'DC-0007',
      'stage': 'packed',
      'customer': {'name': 'রহিম স্টোর', 'code': 'C-01'},
      'lines': [
        {'product': 'বিস্কুট', 'qty': '12.0000', 'free_qty': '1.0000', 'lot': 'L1'},
      ],
      'transport': {'named': true, 'vehicle': 'ঢাকা-১২'},
      'gate_out': null,
      'bill_total': null,
      'actions': {'gate_out': true, 'deliver': false},
    });
    expect(paper.customer, 'রহিম স্টোর · C-01');
    expect(paper.lines.single.freeQty, 1);
    expect(paper.billTotal, isNull);
    expect(paper.canGateOut, isTrue);
    expect(paper.canDeliver, isFalse);
  });
}
