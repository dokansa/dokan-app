import '../config.dart';
import '../models/models.dart';
import 'client.dart';

/// WooCommerce Store API — the same cart and checkout the website uses.
class StoreApi {
  StoreApi(this._c);
  final ApiClient _c;

  String get _b => AppConfig.storeApi;

  Future<(List<Product>, int)> products({
    int? category,
    String? search,
    int page = 1,
    int perPage = 20,
    String? orderBy,
    bool onSale = false,
    List<int>? include,
  }) async {
    final q = <String, dynamic>{
      'page': page,
      'per_page': perPage,
      'stock_status': 'instock',
      if (category != null) 'category': category,
      if (search != null && search.isNotEmpty) 'search': search,
      if (orderBy != null) 'orderby': orderBy,
      if (onSale) 'on_sale': true,
      if (include != null && include.isNotEmpty) 'include': include.join(','),
    };
    final (data, pages) = await _c.getPaged('$_b/products', query: q);
    final list = [for (final p in (data as List)) Product.fromJson(Map<String, dynamic>.from(p as Map))];
    return (list, pages);
  }

  Future<Product> product(int id) async =>
      Product.fromJson(Map<String, dynamic>.from(await _c.get('$_b/products/$id') as Map));

  Future<List<Category>> subcategories(int parent) async {
    final data = await _c.get('$_b/products/categories', query: {'parent': parent, 'per_page': 50});
    return [for (final c in (data as List)) Category.fromJson(Map<String, dynamic>.from(c as Map))]
        .where((c) => c.count > 0)
        .toList();
  }

  /// Every product category (all levels), for building the category tree like the website menu.
  Future<List<Category>> allCategories() async {
    final out = <Category>[];
    for (var page = 1; page <= 5; page++) {
      final data = await _c.get('$_b/products/categories', query: {'per_page': 100, 'page': page});
      final list = [for (final c in (data as List)) Category.fromJson(Map<String, dynamic>.from(c as Map))];
      out.addAll(list);
      if (list.length < 100) break;
    }
    return out;
  }

  Cart _cart(dynamic d) => Cart.fromJson(Map<String, dynamic>.from(d as Map));

  Future<Cart> cart() async => _cart(await _c.get('$_b/cart'));

  Future<Cart> addItem(int id, int quantity, {List<Map<String, String>>? variation, String designText = ''}) async =>
      _cart(await _c.post(
        '$_b/cart/add-item',
        body: {'id': id, 'quantity': quantity, if (variation != null && variation.isNotEmpty) 'variation': variation},
        // Engraving text: the store puts it in the website's own design field.
        headers: designText.trim().isEmpty ? null : {'X-Dokan-Design-Text': Uri.encodeComponent(designText.trim())},
      ));

  Future<Cart> updateItem(String key, int quantity) async =>
      _cart(await _c.post('$_b/cart/update-item', body: {'key': key, 'quantity': quantity}));

  Future<Cart> removeItem(String key) async => _cart(await _c.post('$_b/cart/remove-item', body: {'key': key}));

  Future<Cart> updateCustomer(Map<String, dynamic> billing, Map<String, dynamic> shipping) async => _cart(
      await _c.post('$_b/cart/update-customer', body: {'billing_address': billing, 'shipping_address': shipping}));

  Future<Cart> selectShippingRate(int packageId, String rateId) async => _cart(
      await _c.post('$_b/cart/select-shipping-rate', body: {'package_id': packageId, 'rate_id': rateId}));

  /// Tells the store which payment method is chosen so method-based fees (COD) recalculate.
  Future<Cart> selectPaymentMethod(String id) async => _cart(await _c.post('$_b/cart/extensions', body: {
        'namespace': 'dokan-app',
        'data': {'payment_method': id},
      }));

  Future<CheckoutResult> checkout({
    required Map<String, dynamic> billing,
    required Map<String, dynamic> shipping,
    required String paymentMethod,
    String note = '',
    String? petId,
    double? lat,
    double? lng,
    double? accuracy,
  }) async {
    final d = await _c.post('$_b/checkout', body: {
      'billing_address': billing,
      'shipping_address': shipping,
      'payment_method': paymentMethod,
      'customer_note': note,
      'extensions': {
        'dokan-app': {
          if (petId != null) 'pet_id': petId,
          if (lat != null && lng != null) 'lat': lat,
          if (lat != null && lng != null) 'lng': lng,
          if (accuracy != null) 'accuracy': accuracy,
        },
      },
    }) as Map;
    final pr = (d['payment_result'] as Map?) ?? const {};
    return CheckoutResult(
      orderId: int.tryParse('${d['order_id']}') ?? 0,
      status: d['status']?.toString() ?? '',
      paymentStatus: pr['payment_status']?.toString() ?? '',
      redirectUrl: pr['redirect_url']?.toString() ?? '',
    );
  }
}

class CheckoutResult {
  CheckoutResult({required this.orderId, required this.status, required this.paymentStatus, required this.redirectUrl});
  final int orderId;
  final String status;
  final String paymentStatus;
  final String redirectUrl;

  /// True when the customer still has to pay on the gateway's page (MyFatoorah, Tabby, Tamara).
  bool get needsPaymentPage =>
      redirectUrl.isNotEmpty && !redirectUrl.contains('/order-received/') && paymentStatus != 'failure';
}
