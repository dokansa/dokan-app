import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../api/client.dart';
import '../l10n/lang.dart';
import '../models/models.dart';
import '../router.dart';
import '../services/location_service.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/permission_prompts.dart';
import 'payment_screen.dart';
import 'policies_screen.dart';

/// The map pin chosen in the address sheet; sent with the order so the driver finds the door.
final pickedLocationProvider = StateProvider<PickedLocation?>((ref) => null);

/// Saudi cities: the Arabic name is what the store's shipping rules use, so it's always the value sent.
const _cities = <(String, String)>[
  ('الرياض', 'Riyadh'),
  ('جدة', 'Jeddah'),
  ('مكة المكرمة', 'Makkah'),
  ('المدينة المنورة', 'Madinah'),
  ('الدمام', 'Dammam'),
  ('الخبر', 'Khobar'),
  ('الظهران', 'Dhahran'),
  ('الأحساء', 'Al Ahsa'),
  ('الجبيل', 'Jubail'),
  ('القطيف', 'Qatif'),
  ('الطائف', 'Taif'),
  ('بريدة', 'Buraidah'),
  ('عنيزة', 'Unaizah'),
  ('حائل', 'Hail'),
  ('تبوك', 'Tabuk'),
  ('أبها', 'Abha'),
  ('خميس مشيط', 'Khamis Mushait'),
  ('جازان', 'Jazan'),
  ('نجران', 'Najran'),
  ('ينبع', 'Yanbu'),
  ('الخرج', 'Al Kharj'),
  ('الباحة', 'Al Baha'),
  ('عرعر', 'Arar'),
  ('سكاكا', 'Sakaka'),
];

String _normCity(String s) => s
    .replaceAll(RegExp('[أإآ]'), 'ا')
    .replaceAll('ة', 'ه')
    .replaceAll(RegExp(r'^ال'), '')
    .replaceAll(RegExp(r'\s+'), '')
    .toLowerCase();

/// Match a free-text city (from GPS or old addresses) to our list; returns the Arabic value.
String? _matchCity(String raw) {
  final n = _normCity(raw);
  if (n.isEmpty) return null;
  for (final (ar, en) in _cities) {
    final a = _normCity(ar), e = _normCity(en);
    if (n == a || n == e || n.contains(a) || a.contains(n) || n.contains(e)) return ar;
  }
  return null;
}

/// Cart and checkout on one screen, like the approved design.
class CartScreen extends ConsumerStatefulWidget {
  const CartScreen({super.key});

  @override
  ConsumerState<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends ConsumerState<CartScreen> {
  String? _method;
  final _note = TextEditingController();
  bool _placing = false;
  bool _busy = false;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _guard(Future<void> Function() op) async {
    setState(() => _busy = true);
    try {
      await op();
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<PaymentMethod> _methods(Cart cart, StoreConfig? config) {
    final known = config?.paymentMethods ?? const <PaymentMethod>[];
    return [
      for (final id in cart.paymentMethods) known.firstWhere((m) => m.id == id, orElse: () => PaymentMethod(id, id, '')),
    ];
  }

  bool _addressReady(Cart c) {
    final a = c.shippingAddress;
    return (a['address_1'] ?? '').toString().isNotEmpty && (a['city'] ?? '').toString().isNotEmpty;
  }

  Future<void> _editAddress(Cart cart) async {
    final user = ref.read(sessionProvider);
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: C.ground,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      builder: (_) => _AddressSheet(cart: cart, user: user),
    );
    if (saved == true && mounted) showMessage(context, context.tr('تم حفظ العنوان', 'Address saved'));
  }

  Future<void> _place(Cart cart) async {
    final method = _method;
    if (method == null) return showMessage(context, context.tr('اختر طريقة الدفع', 'Choose a payment method'), error: true);
    if (!_addressReady(cart)) {
      await _editAddress(cart);
      return;
    }
    setState(() => _placing = true);
    try {
      final billing = Map<String, dynamic>.from(cart.billingAddress);
      final shipping = Map<String, dynamic>.from(cart.shippingAddress)..remove('email');
      final pet = ref.read(selectedPetProvider);
      final loc = ref.read(pickedLocationProvider);
      final result = await ref.read(storeApiProvider).checkout(
            billing: billing,
            shipping: shipping,
            paymentMethod: method,
            note: _note.text.trim(),
            petId: pet?.id,
            lat: loc?.lat,
            lng: loc?.lng,
            accuracy: loc?.accuracy,
          );
      if (!mounted) return;
      var paid = true;
      if (result.needsPaymentPage) {
        paid = await context.push<bool>('/pay', extra: PaymentArgs(url: result.redirectUrl, orderId: result.orderId)) ?? false;
      }
      await ref.read(cartProvider.notifier).refresh();
      ref.invalidate(ordersProvider);
      ref.invalidate(purchasedProductsProvider);
      if (!mounted) return;
      if (paid) {
        _note.clear();
        context.push('/order/${result.orderId}?new=1');
      } else {
        showMessage(
          context,
          context.tr('ما اكتمل الدفع. طلبك محفوظ وتقدر تكمله من طلباتي', "Payment wasn't completed. Your order is saved in My orders."),
          error: true,
        );
      }
    } on ApiError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
      await ref.read(cartProvider.notifier).refresh();
    } finally {
      if (mounted) setState(() => _placing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cartAsync = ref.watch(cartProvider);
    final user = ref.watch(sessionProvider);
    final config = ref.watch(configProvider).valueOrNull;

    return Scaffold(
      appBar: AppBar(title: Text(context.tr('السلة', 'Cart'))),
      body: cartAsync.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(cartProvider)),
        data: (cart) {
          if (cart.isEmpty) return const _EmptyCart();
          final methods = _methods(cart, config);
          if (_method == null || !methods.any((m) => m.id == _method)) {
            _method = methods.isEmpty ? null : methods.first.id;
            if (_method != null) {
              final m = _method!;
              WidgetsBinding.instance.addPostFrameCallback((_) {
                ref.read(cartProvider.notifier).selectPayment(m).catchError((_) {});
              });
            }
          }
          return Stack(
            children: [
              ListView(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 350),
                children: [
                  for (final item in cart.items)
                    _CartLine(
                      item: item,
                      busy: _busy,
                      onQty: (q) => _guard(() => ref.read(cartProvider.notifier).setQuantity(item.key, q)),
                    ),
                  for (final err in cart.errors)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(err, style: const TextStyle(color: C.accentText, fontWeight: FontWeight.w600)),
                    ),
                  const SizedBox(height: 12),
                  if (user == null)
                    _LoginPrompt(onTap: () => context.push('/login'))
                  else ...[
                    _AddressCard(cart: cart, ready: _addressReady(cart), onTap: () => _editAddress(cart)),
                    if (cart.rates.length > 1) ...[
                      const SizedBox(height: 16),
                      Text(context.tr('طريقة التوصيل', 'Delivery'), style: display(16)),
                      const SizedBox(height: 8),
                      for (final r in cart.rates)
                        _OptionTile(
                          title: r.name,
                          subtitle: r.price.isZero ? context.tr('مجاني', 'Free') : r.price.label,
                          selected: r.selected,
                          onTap: () => _guard(() => ref.read(cartProvider.notifier).selectRate(r.packageId, r.rateId)),
                        ),
                    ],
                    const SizedBox(height: 16),
                    Text(context.tr('طريقة الدفع', 'Payment'), style: display(16)),
                    const SizedBox(height: 8),
                    for (final m in methods)
                      _OptionTile(
                        title: m.title,
                        subtitle: m.description,
                        selected: _method == m.id,
                        onTap: () {
                          setState(() => _method = m.id);
                          _guard(() => ref.read(cartProvider.notifier).selectPayment(m.id));
                        },
                      ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _note,
                      maxLines: 2,
                      decoration: InputDecoration(hintText: context.tr('ملاحظة للطلب (اختياري)', 'Order note (optional)')),
                    ),
                  ],
                ],
              ),
              Positioned(
                left: 0,
                right: 0,
                bottom: 0,
                child: _Summary(
                  cart: cart,
                  busy: _busy || _placing,
                  label: user == null
                      ? context.tr('سجّل دخولك لإتمام الطلب', 'Sign in to place your order')
                      : (_placing ? '' : context.tr('أكّد الطلب', 'Place order')),
                  onPressed: user == null ? () => context.push('/login') : () => _place(cart),
                  showConsent: user != null,
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _EmptyCart extends StatelessWidget {
  const _EmptyCart();
  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const CircleAvatar(
                  radius: 44, backgroundColor: C.peach, child: Icon(Icons.shopping_bag_outlined, size: 40, color: C.accentText)),
              const SizedBox(height: 16),
              Text(context.tr('سلتك فاضية', 'Your cart is empty'), style: display(20)),
              const SizedBox(height: 6),
              Text(context.tr('حيوانك ينتظر شي حلو', 'Your pet is waiting for something nice'), style: const TextStyle(color: C.muted)),
              const SizedBox(height: 20),
              FilledButton(
                style: FilledButton.styleFrom(minimumSize: const Size(200, 52)),
                onPressed: () => context.go('/'),
                child: Text(context.tr('تسوّق الحين', 'Start shopping')),
              ),
            ],
          ),
        ),
      );
}

class _CartLine extends StatelessWidget {
  const _CartLine({required this.item, required this.onQty, required this.busy});
  final CartItem item;
  final ValueChanged<int> onQty;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(R.card)),
      child: Row(
        children: [
          SizedBox(width: 72, height: 72, child: NetImage(item.image, tint: C.tints[item.productId % C.tints.length])),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.name,
                    maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                if (item.variation.isNotEmpty) Text(item.variation, style: const TextStyle(fontSize: 12, color: C.muted)),
                const SizedBox(height: 4),
                Text(item.lineTotal.label, style: display(14, weight: FontWeight.w700)),
              ],
            ),
          ),
          Container(
            decoration: BoxDecoration(color: C.ground, borderRadius: BorderRadius.circular(12)),
            child: Column(
              children: [
                IconButton(
                  tooltip: context.tr('زِد الكمية', 'Increase quantity'),
                  visualDensity: VisualDensity.compact,
                  onPressed: busy ? null : () => onQty(item.quantity + 1),
                  icon: const Icon(Icons.add_rounded, size: 18),
                ),
                Text('${item.quantity}', style: const TextStyle(fontWeight: FontWeight.w700)),
                IconButton(
                  tooltip: item.quantity == 1 ? context.tr('احذف', 'Remove') : context.tr('أنقص الكمية', 'Decrease quantity'),
                  visualDensity: VisualDensity.compact,
                  onPressed: busy ? null : () => onQty(item.quantity - 1),
                  icon: Icon(item.quantity == 1 ? Icons.delete_outline_rounded : Icons.remove_rounded, size: 18),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _LoginPrompt extends StatelessWidget {
  const _LoginPrompt({required this.onTap});
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Material(
        color: C.mint,
        borderRadius: BorderRadius.circular(R.card),
        child: InkWell(
          borderRadius: BorderRadius.circular(R.card),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                const Icon(Icons.phone_iphone_rounded, color: C.primary),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    context.tr('سجّل برقم جوالك عشان نكمل الطلب ونوصل لك التحديثات',
                        'Sign in with your mobile number to place the order and get updates'),
                    style: const TextStyle(fontWeight: FontWeight.w600, color: C.primaryDeep),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
}

class _AddressCard extends ConsumerWidget {
  const _AddressCard({required this.cart, required this.ready, required this.onTap});
  final Cart cart;
  final bool ready;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final a = cart.shippingAddress;
    final line = [a['address_1'], a['city']].where((s) => s != null && s.toString().isNotEmpty).join('، ');
    final pinned = ref.watch(pickedLocationProvider) != null;
    return Material(
      color: C.surface,
      borderRadius: BorderRadius.circular(R.card),
      child: InkWell(
        borderRadius: BorderRadius.circular(R.card),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: ready ? C.mint : C.peach, borderRadius: BorderRadius.circular(14)),
                child: Icon(pinned ? Icons.where_to_vote_rounded : Icons.location_on_outlined,
                    color: ready ? C.primary : C.accentText),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      pinned ? context.tr('التوصيل إلى · الموقع محدد ✓', 'Deliver to · location pinned ✓') : context.tr('التوصيل إلى', 'Deliver to'),
                      style: const TextStyle(fontSize: 12, color: C.muted),
                    ),
                    Text(ready ? line : context.tr('أضف عنوان التوصيل', 'Add a delivery address'),
                        maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
                  ],
                ),
              ),
              Text(ready ? context.tr('تغيير', 'Change') : context.tr('إضافة', 'Add'),
                  style: const TextStyle(fontWeight: FontWeight.w700, color: C.primary)),
            ],
          ),
        ),
      ),
    );
  }
}

class _OptionTile extends StatelessWidget {
  const _OptionTile({required this.title, required this.subtitle, required this.selected, required this.onTap});
  final String title;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Semantics(
        selected: selected,
        child: Material(
          color: C.surface,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
            side: BorderSide(color: selected ? C.primary : Colors.transparent, width: 2),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              child: Row(
                children: [
                  Icon(selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                      color: selected ? C.primary : const Color(0xFFB9BEB9)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(title, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
                        if (subtitle.isNotEmpty)
                          Text(subtitle,
                              maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: C.muted)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({
    required this.cart,
    required this.busy,
    required this.label,
    required this.onPressed,
    required this.showConsent,
  });
  final Cart cart;
  final bool busy;
  final String label;
  final VoidCallback onPressed;
  final bool showConsent;

  Widget _row(String a, String b) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          children: [
            Expanded(child: Text(a, style: const TextStyle(fontSize: 13, color: C.muted))),
            Text(b, style: const TextStyle(fontSize: 13, color: C.muted)),
          ],
        ),
      );

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, kTabBarClearance - 10),
      decoration: const BoxDecoration(
        color: C.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        boxShadow: [BoxShadow(color: Color(0x1013201F), blurRadius: 24, offset: Offset(0, -6))],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _row(context.tr('المنتجات (${cart.itemsCount})', 'Items (${cart.itemsCount})'), cart.subtotal.label),
          _row(
            context.tr('الشحن', 'Shipping'),
            cart.needsShipping && cart.rates.isEmpty ? context.tr('بعد إضافة العنوان', 'After adding the address') : cart.shipping.label,
          ),
          for (final f in cart.fees) _row(f.name, f.total.label),
          if (!cart.discount.isZero) _row(context.tr('الخصم', 'Discount'), '- ${cart.discount.label}'),
          const Divider(color: C.line, height: 12),
          Row(
            children: [
              Expanded(child: Text(context.tr('الإجمالي', 'Total'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
              Text(cart.total.label, style: display(22, weight: FontWeight.w700)),
            ],
          ),
          const SizedBox(height: 10),
          FilledButton(
            onPressed: busy ? null : onPressed,
            child: busy && label.isEmpty
                ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                : Text(label.isEmpty ? '...' : label),
          ),
          if (showConsent) const PolicyConsentLine(),
        ],
      ),
    );
  }
}

class _AddressSheet extends ConsumerStatefulWidget {
  const _AddressSheet({required this.cart, required this.user});
  final Cart cart;
  final AppUser? user;

  @override
  ConsumerState<_AddressSheet> createState() => _AddressSheetState();
}

class _AddressSheetState extends ConsumerState<_AddressSheet> {
  final _form = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _c;
  final _otherCity = TextEditingController();
  String? _city; // Arabic city value, or '' for "other"
  bool _saving = false;
  bool _locating = false;
  PickedLocation? _loc;

  @override
  void initState() {
    super.initState();
    final s = widget.cart.shippingAddress;
    final b = widget.cart.billingAddress;
    final u = widget.user;
    String pick(String k, [String? fallback]) {
      final v = (s[k] ?? b[k] ?? '').toString();
      return v.isNotEmpty ? v : (fallback ?? '');
    }

    _c = {
      'first_name': TextEditingController(text: pick('first_name', u?.firstName)),
      'last_name': TextEditingController(text: pick('last_name', u?.lastName)),
      'phone': TextEditingController(text: (b['phone'] ?? '').toString().isNotEmpty ? b['phone'].toString() : (u?.phone ?? '')),
      'address_1': TextEditingController(text: pick('address_1')),
      'address_2': TextEditingController(text: pick('address_2')),
    };
    final savedCity = pick('city');
    if (savedCity.isEmpty) {
      _city = 'الرياض';
    } else {
      _city = _matchCity(savedCity) ?? '';
      if (_city == '') _otherCity.text = savedCity;
    }
    _loc = ref.read(pickedLocationProvider);
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    _otherCity.dispose();
    super.dispose();
  }

  Future<void> _useMyLocation() async {
    if (!await explainLocation(context)) return;
    setState(() => _locating = true);
    try {
      final loc = await LocationService.current();
      if (!mounted) return;
      setState(() {
        _loc = loc;
        final matched = _matchCity(loc.city);
        if (matched != null) {
          _city = matched;
        } else if (loc.city.isNotEmpty) {
          _city = '';
          _otherCity.text = loc.city;
        }
        if (loc.line.isNotEmpty) _c['address_1']!.text = loc.line;
      });
      showMessage(context, context.tr('حددنا موقعك ✓ تأكد من رقم البيت', 'Location pinned ✓ — please add your house number'));
    } on LocationError catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, error: true);
      if (e.openSettings) await LocationService.openSettings();
    } catch (_) {
      if (mounted) {
        showMessage(context, context.tr('ما قدرنا نحدد موقعك، اكتب العنوان يدوياً', "Couldn't get your location — please type the address"),
            error: true);
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);
    final v = {for (final e in _c.entries) e.key: e.value.text.trim()};
    final city = (_city == null || _city == '') ? _otherCity.text.trim() : _city!;
    final shipping = {
      'first_name': v['first_name'],
      'last_name': v['last_name'],
      'address_1': v['address_1'],
      'address_2': v['address_2'],
      'city': city,
      'state': '',
      'postcode': '',
      'country': 'SA',
      'phone': v['phone'],
    };
    final billing = {
      ...shipping,
      'email': (widget.cart.billingAddress['email'] ?? '').toString().isNotEmpty
          ? widget.cart.billingAddress['email']
          : (widget.user?.email ?? ''),
    };
    try {
      await ref.read(cartProvider.notifier).updateAddress(billing, shipping);
      ref.read(pickedLocationProvider.notifier).state = _loc;
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    String? requiredField(String? v) => v == null || v.trim().isEmpty ? context.tr('مطلوب', 'Required') : null;
    final ar = context.isAr;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
      child: Form(
        key: _form,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.tr('عنوان التوصيل', 'Delivery address'), style: display(18)),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(50),
                  foregroundColor: C.primary,
                  side: const BorderSide(color: C.primary, width: 1.5),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(R.button)),
                ),
                onPressed: _locating ? null : _useMyLocation,
                icon: _locating
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: C.primary))
                    : Icon(_loc != null ? Icons.where_to_vote_rounded : Icons.my_location_rounded),
                label: Text(
                  _loc != null ? context.tr('الموقع محدد ✓ — حدّث', 'Location pinned ✓ — update') : context.tr('استخدم موقعي الحالي', 'Use my current location'),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: TextFormField(
                      controller: _c['first_name'],
                      validator: requiredField,
                      decoration: InputDecoration(labelText: context.tr('الاسم', 'First name')),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: TextFormField(
                      controller: _c['last_name'],
                      decoration: InputDecoration(labelText: context.tr('اسم العائلة', 'Last name')),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              TextFormField(
                controller: _c['phone'],
                validator: requiredField,
                keyboardType: TextInputType.phone,
                textDirection: TextDirection.ltr,
                decoration: InputDecoration(labelText: context.tr('رقم الجوال', 'Mobile number')),
              ),
              const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                value: _city,
                isExpanded: true,
                decoration: InputDecoration(labelText: context.tr('المدينة', 'City')),
                validator: (v) => v == null ? context.tr('مطلوب', 'Required') : null,
                items: [
                  for (final (a, e) in _cities) DropdownMenuItem(value: a, child: Text(ar ? a : e)),
                  DropdownMenuItem(value: '', child: Text(context.tr('مدينة ثانية…', 'Other city…'))),
                ],
                onChanged: (v) => setState(() => _city = v),
              ),
              if (_city == '') ...[
                const SizedBox(height: 10),
                TextFormField(
                  controller: _otherCity,
                  validator: requiredField,
                  decoration: InputDecoration(labelText: context.tr('اسم المدينة', 'City name')),
                ),
              ],
              const SizedBox(height: 10),
              TextFormField(
                controller: _c['address_1'],
                validator: requiredField,
                decoration: InputDecoration(labelText: context.tr('الحي والشارع', 'District and street')),
              ),
              const SizedBox(height: 10),
              TextFormField(
                controller: _c['address_2'],
                decoration: InputDecoration(
                    labelText: context.tr('تفاصيل إضافية (رقم المبنى، أقرب معلم)', 'More details (building no., landmark)')),
              ),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: _saving
                    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                    : Text(context.tr('احفظ العنوان', 'Save address')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
