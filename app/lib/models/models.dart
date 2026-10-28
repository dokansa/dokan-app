import 'package:html_unescape/html_unescape.dart';

import '../l10n/lang.dart';

final _unescape = HtmlUnescape();

String cleanText(dynamic v) {
  if (v == null) return '';
  final s = v.toString().replaceAll(RegExp(r'<[^>]*>'), ' ');
  return _unescape.convert(s).replaceAll(RegExp(r'\s+'), ' ').trim();
}

int _int(dynamic v) => v is int ? v : int.tryParse('${v ?? ''}') ?? 0;
double? _dbl(dynamic v) => v == null ? null : (v is num ? v.toDouble() : double.tryParse('$v'));

/// Store API money: integer string in minor units + minor unit count.
class Money {
  const Money(this.minor, this.unit);
  final int minor;
  final int unit;

  factory Money.store(dynamic value, dynamic unit) => Money(_int(value), _int(unit ?? 2));

  double get value => minor / _pow10(unit);
  bool get isZero => minor == 0;

  String get label {
    final v = value;
    final s = v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(2);
    return '$s ${currencyLabel(AppLang.isAr)}';
  }

  static num _pow10(int n) {
    num r = 1;
    for (var i = 0; i < n; i++) {
      r *= 10;
    }
    return r;
  }
}

class Category {
  Category({required this.id, required this.name, this.image, this.count = 0});
  final int id;
  final String name;
  final String? image;
  final int count;

  factory Category.fromJson(Map<String, dynamic> j) => Category(
        id: _int(j['id']),
        name: cleanText(j['name']),
        image: j['image'] is Map ? (j['image']['thumbnail'] ?? j['image']['src']) : j['image'] as String?,
        count: _int(j['count']),
      );
}

class ProductVariation {
  ProductVariation({required this.id, required this.label, required this.attributes});
  final int id;
  final String label;
  final List<Map<String, String>> attributes;
}

class Product {
  Product({
    required this.id,
    required this.name,
    required this.images,
    required this.price,
    required this.regularPrice,
    required this.onSale,
    required this.inStock,
    required this.shortDescription,
    required this.description,
    required this.type,
    required this.variations,
    required this.categories,
    required this.permalink,
    this.categoryIds = const [],
  });

  final List<int> categoryIds;

  final int id;
  final String name;
  final List<String> images;
  final Money price;
  final Money regularPrice;
  final bool onSale;
  final bool inStock;
  final String shortDescription;
  final String description;
  final String type;
  final List<ProductVariation> variations;
  final List<String> categories;
  final String permalink;

  String? get image => images.isEmpty ? null : images.first;
  bool get isVariable => type == 'variable' && variations.isNotEmpty;

  factory Product.fromJson(Map<String, dynamic> j) {
    final prices = (j['prices'] as Map?) ?? const {};
    final unit = prices['currency_minor_unit'];
    final imgs = <String>[];
    for (final i in (j['images'] as List? ?? const [])) {
      final src = (i as Map)['src'] ?? i['thumbnail'];
      if (src is String && src.isNotEmpty) imgs.add(src);
    }
    final vars = <ProductVariation>[];
    for (final v in (j['variations'] as List? ?? const [])) {
      final attrs = <Map<String, String>>[];
      for (final a in ((v as Map)['attributes'] as List? ?? const [])) {
        attrs.add({
          'attribute': cleanText((a as Map)['name']),
          'value': cleanText(a['value']),
        });
      }
      vars.add(ProductVariation(
        id: _int(v['id']),
        label: attrs.map((a) => a['value']).where((s) => s != null && s.isNotEmpty).join(' · '),
        attributes: attrs,
      ));
    }
    return Product(
      id: _int(j['id']),
      name: cleanText(j['name']),
      images: imgs,
      price: Money.store(prices['price'], unit),
      regularPrice: Money.store(prices['regular_price'], unit),
      onSale: j['on_sale'] == true,
      inStock: j['is_in_stock'] != false,
      shortDescription: cleanText(j['short_description']),
      description: cleanText(j['description']),
      type: j['type']?.toString() ?? 'simple',
      variations: vars,
      categories: [for (final c in (j['categories'] as List? ?? const [])) cleanText((c as Map)['name'])],
      categoryIds: [for (final c in (j['categories'] as List? ?? const [])) _int((c as Map)['id'])].where((i) => i > 0).toList(),
      permalink: j['permalink']?.toString() ?? '',
    );
  }
}

class CartItem {
  CartItem({
    required this.key,
    required this.productId,
    required this.name,
    required this.quantity,
    required this.image,
    required this.lineTotal,
    required this.variation,
  });
  final String key;
  final int productId;
  final String name;
  final int quantity;
  final String? image;
  final Money lineTotal;
  final String variation;
}

class ShippingRate {
  ShippingRate({required this.packageId, required this.rateId, required this.name, required this.price, required this.selected});
  final int packageId;
  final String rateId;
  final String name;
  final Money price;
  final bool selected;
}

class CartFee {
  CartFee(this.name, this.total);
  final String name;
  final Money total;
}

class Cart {
  Cart({
    required this.items,
    required this.itemsCount,
    required this.subtotal,
    required this.shipping,
    required this.discount,
    required this.total,
    required this.fees,
    required this.rates,
    required this.paymentMethods,
    required this.needsShipping,
    required this.shippingAddress,
    required this.billingAddress,
    required this.errors,
  });

  final List<CartItem> items;
  final int itemsCount;
  final Money subtotal;
  final Money shipping;
  final Money discount;
  final Money total;
  final List<CartFee> fees;
  final List<ShippingRate> rates;
  final List<String> paymentMethods;
  final bool needsShipping;
  final Map<String, dynamic> shippingAddress;
  final Map<String, dynamic> billingAddress;
  final List<String> errors;

  bool get isEmpty => items.isEmpty;

  static final empty = Cart(
    items: const [],
    itemsCount: 0,
    subtotal: const Money(0, 2),
    shipping: const Money(0, 2),
    discount: const Money(0, 2),
    total: const Money(0, 2),
    fees: const [],
    rates: const [],
    paymentMethods: const [],
    needsShipping: true,
    shippingAddress: const {},
    billingAddress: const {},
    errors: const [],
  );

  factory Cart.fromJson(Map<String, dynamic> j) {
    final totals = (j['totals'] as Map?) ?? const {};
    final unit = totals['currency_minor_unit'];
    final items = <CartItem>[];
    for (final i in (j['items'] as List? ?? const [])) {
      final m = i as Map;
      final imgs = m['images'] as List? ?? const [];
      final t = (m['totals'] as Map?) ?? const {};
      items.add(CartItem(
        key: m['key'].toString(),
        productId: _int(m['id']),
        name: cleanText(m['name']),
        quantity: _int(m['quantity']),
        image: imgs.isEmpty ? null : ((imgs.first as Map)['thumbnail'] ?? imgs.first['src']) as String?,
        lineTotal: Money.store(t['line_total'], t['currency_minor_unit'] ?? unit),
        variation: [
          for (final v in (m['variation'] as List? ?? const [])) cleanText((v as Map)['value'])
        ].where((s) => s.isNotEmpty).join(' · '),
      ));
    }
    final rates = <ShippingRate>[];
    for (final p in (j['shipping_rates'] as List? ?? const [])) {
      final pm = p as Map;
      for (final r in (pm['shipping_rates'] as List? ?? const [])) {
        final rm = r as Map;
        rates.add(ShippingRate(
          packageId: _int(pm['package_id']),
          rateId: rm['rate_id'].toString(),
          name: cleanText(rm['name']),
          price: Money.store(rm['price'], rm['currency_minor_unit'] ?? unit),
          selected: rm['selected'] == true,
        ));
      }
    }
    final fees = <CartFee>[];
    for (final f in (j['fees'] as List? ?? const [])) {
      final fm = f as Map;
      final ft = (fm['totals'] as Map?) ?? const {};
      fees.add(CartFee(cleanText(fm['name']), Money.store(ft['total'], ft['currency_minor_unit'] ?? unit)));
    }
    return Cart(
      items: items,
      itemsCount: _int(j['items_count']),
      subtotal: Money.store(totals['total_items'], unit),
      shipping: Money.store(totals['total_shipping'], unit),
      discount: Money.store(totals['total_discount'], unit),
      total: Money.store(totals['total_price'], unit),
      fees: fees,
      rates: rates,
      paymentMethods: [for (final p in (j['payment_methods'] as List? ?? const [])) p.toString()],
      needsShipping: j['needs_shipping'] != false,
      shippingAddress: Map<String, dynamic>.from((j['shipping_address'] as Map?) ?? const {}),
      billingAddress: Map<String, dynamic>.from((j['billing_address'] as Map?) ?? const {}),
      errors: [for (final e in (j['errors'] as List? ?? const [])) cleanText((e as Map)['message'])],
    );
  }
}

class AppUser {
  AppUser({required this.id, required this.firstName, required this.lastName, required this.email, required this.phone});
  final int id;
  final String firstName;
  final String lastName;
  final String email;
  final String phone;

  String get name => '$firstName $lastName'.trim();

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: _int(j['id']),
        firstName: j['first_name']?.toString() ?? '',
        lastName: j['last_name']?.toString() ?? '',
        email: j['email']?.toString() ?? '',
        phone: j['phone']?.toString() ?? '',
      );
}

const petKindKeys = ['cat', 'dog', 'bird', 'fish', 'small'];
const foodTypeKeys = ['dry', 'wet', 'mixed'];

class Pet {
  Pet({
    required this.id,
    required this.name,
    required this.kind,
    this.breed = '',
    this.birthDate,
    this.weightKg,
    this.foodType,
    this.photo,
    this.remind = true,
  });

  final String id;
  final String name;
  final String kind;
  final String breed;
  final String? birthDate;
  final double? weightKg;
  final String? foodType;
  final String? photo;
  final bool remind;

  String get kindLabel => petKindLabel(kind, AppLang.isAr);

  int? get ageMonths {
    final d = birthDate == null ? null : DateTime.tryParse(birthDate!);
    if (d == null) return null;
    return (DateTime.now().difference(d).inDays / 30.4).floor();
  }

  bool get isYoung => (ageMonths ?? 99) < 12;

  String? get ageLabel {
    final months = ageMonths;
    if (months == null) return null;
    final ar = AppLang.isAr;
    if (months < 12) return ar ? '$months شهر' : '$months mo';
    final y = (months / 12).floor();
    if (!ar) return y == 1 ? '1 year' : '$y years';
    return y == 1 ? 'سنة' : (y == 2 ? 'سنتين' : '$y سنوات');
  }

  factory Pet.fromJson(Map<String, dynamic> j) => Pet(
        id: j['id'].toString(),
        name: j['name']?.toString() ?? '',
        kind: j['kind']?.toString() ?? 'cat',
        breed: j['breed']?.toString() ?? '',
        birthDate: j['birth_date']?.toString(),
        weightKg: _dbl(j['weight_kg']),
        foodType: j['food_type']?.toString(),
        photo: j['photo']?.toString(),
        remind: j['remind'] != false,
      );

  Map<String, dynamic> toJson() => {
        'name': name,
        'kind': kind,
        'breed': breed,
        'birth_date': birthDate,
        'weight_kg': weightKg,
        'food_type': foodType,
        'remind': remind,
      };
}

class StockItem {
  StockItem({
    required this.productId,
    required this.name,
    this.image,
    required this.daysLeft,
    required this.percentLeft,
  });
  final int productId;
  final String name;
  final String? image;
  final int daysLeft;
  final int percentLeft;

  bool get low => daysLeft <= 5;

  factory StockItem.fromJson(Map<String, dynamic> j) => StockItem(
        productId: _int(j['product_id']),
        name: cleanText(j['name']),
        image: j['image']?.toString(),
        daysLeft: _int(j['days_left']),
        percentLeft: _int(j['percent_left']).clamp(0, 100),
      );
}

class OrderSummary {
  OrderSummary({
    required this.id,
    required this.number,
    required this.status,
    required this.statusLabel,
    required this.date,
    required this.total,
    required this.itemCount,
    this.image,
  });
  final int id;
  final String number;
  final String status;
  final String statusLabel;
  final DateTime? date;
  final String total;
  final int itemCount;
  final String? image;

  factory OrderSummary.fromJson(Map<String, dynamic> j) => OrderSummary(
        id: _int(j['id']),
        number: j['number']?.toString() ?? '${j['id']}',
        status: j['status']?.toString() ?? '',
        statusLabel: cleanText(j['status_label']),
        date: DateTime.tryParse(j['date_created']?.toString() ?? ''),
        total: j['total']?.toString() ?? '',
        itemCount: _int(j['item_count']),
        image: j['first_image']?.toString(),
      );
}

class OrderLine {
  OrderLine(this.productId, this.name, this.quantity, this.total, this.image);
  final int productId;
  final String name;
  final int quantity;
  final String total;
  final String? image;
}

class Tracking {
  Tracking({this.carrier, this.number, this.url});
  final String? carrier;
  final String? number;
  final String? url;
}

class TimelineStep {
  TimelineStep(this.stage, this.label, this.date);
  final int stage;
  final String label;
  final DateTime? date;
}

class OrderDetail {
  OrderDetail({
    required this.summary,
    required this.items,
    required this.shippingTotal,
    required this.fees,
    required this.paymentTitle,
    required this.stage,
    required this.tracking,
    required this.canCancel,
    required this.timeline,
  });

  final OrderSummary summary;
  final List<OrderLine> items;
  final String shippingTotal;
  final List<(String, String)> fees;
  final String paymentTitle;
  final int stage;
  final Tracking? tracking;
  final bool canCancel;
  final List<TimelineStep> timeline;

  factory OrderDetail.fromJson(Map<String, dynamic> j) {
    final t = j['tracking'];
    return OrderDetail(
      summary: OrderSummary.fromJson(j),
      items: [
        for (final i in (j['items'] as List? ?? const []))
          OrderLine(_int((i as Map)['product_id']), cleanText(i['name']), _int(i['quantity']), i['total']?.toString() ?? '',
              i['image']?.toString())
      ],
      shippingTotal: j['shipping_total']?.toString() ?? '0',
      fees: [
        for (final f in (j['fees'] as List? ?? const [])) (cleanText((f as Map)['name']), f['total']?.toString() ?? '')
      ],
      paymentTitle: cleanText(j['payment_method_title']),
      stage: _int(j['stage']),
      tracking: t is Map
          ? Tracking(carrier: t['carrier']?.toString(), number: t['number']?.toString(), url: t['url']?.toString())
          : null,
      canCancel: j['can_cancel'] == true,
      timeline: [
        for (final s in (j['timeline'] as List? ?? const []))
          TimelineStep(_int((s as Map)['stage']), cleanText(s['label']), DateTime.tryParse(s['date']?.toString() ?? ''))
      ],
    );
  }
}

class PaymentMethod {
  PaymentMethod(this.id, this.title, this.description);
  final String id;
  final String title;
  final String description;
}

class PromoBanner {
  PromoBanner(this.image, this.title, this.link);
  final String image;
  final String title;
  final String link;
}

class StoreConfig {
  StoreConfig({
    required this.paymentMethods,
    required this.categories,
    required this.banners,
    required this.deliveryRiyadh,
    required this.deliveryKsa,
    required this.whatsapp,
    required this.chatwootBase,
    required this.chatwootToken,
    this.otpChannels = const [],
    this.policies = const [],
    this.popularSearches = const [],
    this.petCategories = const {},
    this.assistantEnabled = false,
  });

  final List<String> otpChannels;
  final List<Policy> policies;
  final List<String> popularSearches;
  final Map<String, int> petCategories;
  final bool assistantEnabled;

  final List<PaymentMethod> paymentMethods;
  final List<Category> categories;
  final List<PromoBanner> banners;
  final String deliveryRiyadh;
  final String deliveryKsa;
  final String whatsapp;
  final String chatwootBase;
  final String chatwootToken;

  factory StoreConfig.fromJson(Map<String, dynamic> j) {
    final cw = (j['chatwoot'] as Map?) ?? const {};
    return StoreConfig(
      paymentMethods: [
        for (final p in (j['payment_methods'] as List? ?? const []))
          PaymentMethod((p as Map)['id'].toString(), cleanText(p['title']), cleanText(p['description']))
      ],
      categories: [for (final c in (j['categories'] as List? ?? const [])) Category.fromJson(Map<String, dynamic>.from(c as Map))],
      banners: [
        for (final b in (j['banners'] as List? ?? const []))
          PromoBanner((b as Map)['image']?.toString() ?? '', cleanText(b['title']), b['link']?.toString() ?? '')
      ],
      deliveryRiyadh: cleanText(j['delivery_note_riyadh']).isEmpty
          ? AppLang.t('داخل الرياض: يوصلك اليوم إذا طلبت قبل ٣ العصر', 'Riyadh: same-day delivery for orders before 3 PM')
          : cleanText(j['delivery_note_riyadh']),
      deliveryKsa: cleanText(j['delivery_note_ksa']).isEmpty
          ? AppLang.t('باقي مناطق المملكة: من ٢ إلى ٧ أيام', 'Rest of Saudi Arabia: 2–7 days')
          : cleanText(j['delivery_note_ksa']),
      otpChannels: [for (final c in (j['otp_channels'] as List? ?? const [])) c.toString()],
      policies: [
        for (final p in (j['policies'] as List? ?? const []))
          Policy(
            (p as Map)['key']?.toString() ?? '',
            cleanText(p['title']),
            p['page_id'] == null ? null : int.tryParse('${p['page_id']}'),
            p['content_html']?.toString(),
          )
      ],
      popularSearches: [for (final q in (j['popular_searches'] as List? ?? const [])) cleanText(q)],
      petCategories: {
        for (final e in ((j['pet_categories'] as Map?) ?? const {}).entries)
          if (e.value != null) e.key.toString(): int.tryParse('${e.value}') ?? 0
      },
      assistantEnabled: j['assistant_enabled'] == true,
      whatsapp: j['whatsapp']?.toString() ?? '',
      chatwootBase: cw['base_url']?.toString() ?? '',
      chatwootToken: cw['website_token']?.toString() ?? '',
    );
  }
}


class Policy {
  Policy(this.key, this.title, this.pageId, this.contentHtml);
  final String key;
  final String title;
  final int? pageId;
  final String? contentHtml;
}

class PageContent {
  PageContent(this.title, this.html);
  final String title;
  final String html;
}

/// One thing the search understood, shown as a chip ("قطط", "أكل رطب", "أقل من ١٠٠ ر.س").
class Understood {
  Understood(this.type, this.label, this.value);
  final String type;
  final String label;
  final String value;
}

class SearchResult {
  SearchResult({
    required this.query,
    required this.understood,
    required this.reply,
    required this.productIds,
    required this.total,
    required this.suggestions,
    required this.categories,
    required this.ai,
  });

  final String query;
  final List<Understood> understood;
  final String reply;
  final List<int> productIds;
  final int total;
  final List<String> suggestions;
  final List<Category> categories;
  final bool ai;

  factory SearchResult.fromJson(Map<String, dynamic> j) => SearchResult(
        query: j['query']?.toString() ?? '',
        understood: [
          for (final u in (j['understood'] as List? ?? const []))
            Understood((u as Map)['type']?.toString() ?? '', cleanText(u['label']), u['value']?.toString() ?? '')
        ],
        reply: cleanText(j['reply']),
        productIds: [for (final i in (j['product_ids'] as List? ?? const [])) _int(i)].where((i) => i > 0).toList(),
        total: _int(j['total']),
        suggestions: [for (final s in (j['suggestions'] as List? ?? const [])) cleanText(s)].where((s) => s.isNotEmpty).toList(),
        categories: [
          for (final c in (j['categories'] as List? ?? const [])) Category.fromJson(Map<String, dynamic>.from(c as Map))
        ],
        ai: j['ai'] == true,
      );
}

class ChatMessage {
  ChatMessage({required this.fromUser, required this.text, this.productIds = const [], this.chips = const []});
  final bool fromUser;
  final String text;
  final List<int> productIds;
  final List<String> chips;

  Map<String, dynamic> toJson() => {'role': fromUser ? 'user' : 'assistant', 'content': text};
}
