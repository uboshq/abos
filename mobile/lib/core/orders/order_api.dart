import 'dart:convert';

import '../api_client/api_client.dart';
import '../records/money.dart';
import '../sync_engine/reference_cache.dart';

/// Where the shop stands — `GET /sales/standing/{customer}?total=` (0.4.3).
///
/// <p>Asked at the moment পাঠান is pressed, never earlier and never cached:
/// the figure that matters is the one at the moment of the promise. It
/// never blocks the order — the credit wall is at the DO, the challan and
/// the bill (the owner's rule), so the phone only says what will happen.
class CustomerStanding {
  const CustomerStanding({
    required this.due,
    required this.advance,
    required this.pendingClaims,
    required this.pendingClaimCount,
    required this.limit,
    required this.orderTotal,
    required this.toPay,
    required this.overLimit,
  });

  final double due;
  final double advance;
  final double pendingClaims;
  final int pendingClaimCount;
  final double limit;
  final double orderTotal;
  final double toPay;
  final bool overLimit;

  factory CustomerStanding.fromJson(Map<String, dynamic> json) => CustomerStanding(
        due: Money.valueOrZero(json['due']),
        advance: Money.valueOrZero(json['advance']),
        pendingClaims: Money.valueOrZero(json['pending_claims']),
        pendingClaimCount: (json['pending_claim_count'] as num?)?.toInt() ?? 0,
        limit: Money.valueOrZero(json['limit']),
        orderTotal: Money.valueOrZero(json['order_total']),
        toPay: Money.valueOrZero(json['to_pay']),
        overLimit: json['over_limit'] == true,
      );
}

/// One line of `POST /sales/offers`' answer.
class OfferLine {
  const OfferLine({required this.productId, required this.freeQty, this.offer});

  /// The product's **public_id** — the screen's own key, never the server's
  /// counting id.
  final String productId;
  final double freeQty;

  /// The sentence a shopkeeper is told, e.g. "১২টা কিনলে ১টা ফ্রি".
  final String? offer;
}

/// One line sent to `POST /sales/offers`.
class OfferAsk {
  const OfferAsk({required this.productId, required this.qty, this.rate});

  final String productId;
  final int qty;
  final String? rate;
}

/// The two server questions the order screen asks. An interface so a test
/// answers them without a server.
abstract class OrderApi {
  Future<CustomerStanding> standing(String customerId, double orderTotal);

  Future<List<OfferLine>> offers(String customerId, List<OfferAsk> lines);
}

class ServerOrderApi implements OrderApi {
  const ServerOrderApi();

  @override
  Future<CustomerStanding> standing(String customerId, double orderTotal) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/sales/standing/$customerId',
      queryParameters: {'total': orderTotal.toStringAsFixed(2)},
    );
    return CustomerStanding.fromJson(response.data ?? const {});
  }

  @override
  Future<List<OfferLine>> offers(String customerId, List<OfferAsk> lines) async {
    if (lines.isEmpty) return const [];
    final response = await ApiClient.dio.post<Map<String, dynamic>>(
      '/sales/offers',
      data: {
        'customer': customerId,
        'lines': [
          for (final line in lines)
            {
              'product': line.productId,
              'qty': '${line.qty}',
              if (line.rate != null) 'rate': line.rate,
            },
        ],
      },
    );
    return readOffers(response.data ?? const {}, lines);
  }

  /// The answer, keyed back to the public_ids that were asked.
  ///
  /// <p>⚠️ The server answers each line with its counting `product_id`,
  /// which this phone never sees. So a line is matched by its `product`
  /// public_id when the server echoes one, and otherwise by position — the
  /// answer comes back in the order it was asked.
  static List<OfferLine> readOffers(Map<String, dynamic> body, List<OfferAsk> asked) {
    final rows = (body['lines'] as List?) ?? const [];
    final out = <OfferLine>[];
    for (var i = 0; i < rows.length; i++) {
      final row = rows[i];
      if (row is! Map) continue;
      final echoed = row['product']?.toString();
      final productId = (echoed != null && echoed.isNotEmpty)
          ? echoed
          : (i < asked.length ? asked[i].productId : null);
      if (productId == null) continue;
      final text = row['offer']?.toString().trim();
      out.add(OfferLine(
        productId: productId,
        freeQty: Money.valueOrZero(row['free_qty']),
        offer: (text == null || text.isEmpty) ? null : text,
      ));
    }
    return out;
  }
}

/// What this phone itself has ordered for each shop — for "আগের মতো" and
/// "এই দোকান আগে নিয়েছে".
///
/// <p>The orders that come back from the server carry no lines, so the only
/// honest source is what was written here. Kept in [ReferenceCache], so it is
/// cleared with everything else on sign-out and on a company change — the
/// same tenant boundary.
class OrderMemory {
  const OrderMemory();

  static const String entityType = 'OrderMemory';

  /// productId → the quantity last ordered for [customerId] from this phone.
  Map<String, int> lastFor(String customerId) {
    final row = ReferenceCache.instance.get(entityType, customerId);
    final lines = row?['lines'];
    if (lines is! Map) return const {};
    return {
      for (final entry in lines.entries)
        if (entry.value is num) entry.key.toString(): (entry.value as num).toInt(),
    };
  }

  Future<void> remember(String customerId, Map<String, int> lines) async {
    final merged = {...lastFor(customerId), ...lines};
    await ReferenceCache.instance.put(
      entityType: entityType,
      entityId: customerId,
      payload: jsonDecode(jsonEncode({'lines': merged})) as Map<String, dynamic>,
      updatedAt: DateTime.now(),
    );
  }
}
