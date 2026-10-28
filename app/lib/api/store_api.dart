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
      'lang': AppConfig.language,
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
      Product.fromJson(Map<String, dynamic>.from(await _c.get('$_b/products/$id', query: {'lang': AppConfig.language}) as Map));

  Future<List<Category>> subcategories(int parent) async {
    final data = await _c.get('$_b/products/categories', query: {'parent': parent, 'per_page': 50, 'lang': AppConfig.language});
    return [for (final c in (data as List)) Category.fromJson(Map<String, dynamic>.from(c as Map))]
        .where((c) => c.count > 0)
        .toList();
  }

  Cart _cart(dynamic d) => Cart.fromJson(Map<String, dynamic>.from(d as Map));

  Future<Cart> cart() async => _cart(await _c.get('$_b/cart'));

  Future<Cart> addItem(int id, int quantity, {List<Map<String, String>>? variation}) async => _cart(await _c.post(
        '$_b/cart/add-item',
        body: {'id': id, 'quantity': quantity, if (variation != null && variation.isNotEmpty) 'variation': variation},
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
  }) async {
    final d = await _c.post('$_b/checkout', body: {
      'billing_address': billing,
      'shipping_address': shipping,
      'payment_method': paymentMethod,
      'customer_note': note,
      'extensions': {
        'dokan-app': {if (petId != null) 'pet_id': petId},
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
