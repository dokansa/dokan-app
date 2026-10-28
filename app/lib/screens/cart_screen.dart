import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../api/client.dart';
import '../models/models.dart';
import '../router.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'payment_screen.dart';

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
    final available = cart.paymentMethods;
    final known = config?.paymentMethods ?? const <PaymentMethod>[];
    final list = <PaymentMethod>[];
    for (final id in available) {
      list.add(known.firstWhere((m) => m.id == id, orElse: () => PaymentMethod(id, id, '')));
    }
    return list;
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
    if (saved == true && mounted) showMessage(context, 'تم حفظ العنوان');
  }

  Future<void> _place(Cart cart) async {
    final method = _method;
    if (method == null) return showMessage(context, 'اختر طريقة الدفع', error: true);
    if (!_addressReady(cart)) {
      await _editAddress(cart);
      return;
    }
    setState(() => _placing = true);
    try {
      final billing = Map<String, dynamic>.from(cart.billingAddress);
      final shipping = Map<String, dynamic>.from(cart.shippingAddress)..remove('email');
      final pet = ref.read(selectedPetProvider);
      final result = await ref.read(storeApiProvider).checkout(
            billing: billing,
            shipping: shipping,
            paymentMethod: method,
            note: _note.text.trim(),
            petId: pet?.id,
          );
      if (!mounted) return;
      var paid = true;
      if (result.needsPaymentPage) {
        paid = await context.push<bool>(
              '/pay',
              extra: PaymentArgs(url: result.redirectUrl, orderId: result.orderId),
            ) ??
            false;
      }
      await ref.read(cartProvider.notifier).refresh();
      ref.invalidate(ordersProvider);
      if (!mounted) return;
      if (paid) {
        _note.clear();
        context.push('/order/${result.orderId}?new=1');
      } else {
        showMessage(context, 'ما اكتمل الدفع. طلبك محفوظ وتقدر تكمله من طلباتي', error: true);
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
      appBar: AppBar(title: const Text('السلة')),
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
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 330),
                children: [
                  for (final item in cart.items) _CartLine(item: item, busy: _busy, onQty: (q) => _guard(() => ref.read(cartProvider.notifier).setQuantity(item.key, q))),
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
                      Text('طريقة التوصيل', style: display(16)),
                      const SizedBox(height: 8),
                      for (final r in cart.rates)
                        _OptionTile(
                          title: r.name,
                          subtitle: r.price.isZero ? 'مجاني' : r.price.label,
                          selected: r.selected,
                          onTap: () => _guard(() => ref.read(cartProvider.notifier).selectRate(r.packageId, r.rateId)),
                        ),
                    ],
                    const SizedBox(height: 16),
                    Text('طريقة الدفع', style: display(16)),
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
                      decoration: const InputDecoration(hintText: 'ملاحظة للطلب (اختياري)'),
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
                  label: user == null ? 'سجّل دخولك لإتمام الطلب' : (_placing ? '' : 'أكّد الطلب'),
                  onPressed: user == null ? () => context.push('/login') : () => _place(cart),
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
              const CircleAvatar(radius: 44, backgroundColor: C.peach, child: Icon(Icons.shopping_bag_outlined, size: 40, color: C.accentText)),
              const SizedBox(height: 16),
              Text('سلتك فاضية', style: display(20)),
              const SizedBox(height: 6),
              const Text('حيوانك ينتظر شي حلو', style: TextStyle(color: C.muted)),
              const SizedBox(height: 20),
              FilledButton(
                style: FilledButton.styleFrom(minimumSize: const Size(200, 52)),
                onPressed: () => context.go('/'),
                child: const Text('تسوّق الحين'),
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
                Text(item.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
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
                  tooltip: 'زِد الكمية',
                  visualDensity: VisualDensity.compact,
                  onPressed: busy ? null : () => onQty(item.quantity + 1),
                  icon: const Icon(Icons.add_rounded, size: 18),
                ),
                Text('${item.quantity}', style: const TextStyle(fontWeight: FontWeight.w700)),
                IconButton(
                  tooltip: item.quantity == 1 ? 'احذف' : 'أنقص الكمية',
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
  Widget build(BuildContext context) => GestureDetector(
        onTap: onTap,
        child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(R.card)),
        child: const Row(
          children: [
            Icon(Icons.phone_iphone_rounded, color: C.primary),
            SizedBox(width: 10),
            Expanded(
              child: Text('سجّل برقم جوالك عشان نكمل الطلب ونوصل لك التحديثات',
                  style: TextStyle(fontWeight: FontWeight.w600, color: C.primaryDeep)),
            ),
          ],
        ),
      ),
      );
}

class _AddressCard extends StatelessWidget {
  const _AddressCard({required this.cart, required this.ready, required this.onTap});
  final Cart cart;
  final bool ready;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final a = cart.shippingAddress;
    final line = [a['address_1'], a['city']].where((s) => s != null && s.toString().isNotEmpty).join('، ');
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
                child: Icon(Icons.location_on_outlined, color: ready ? C.primary : C.accentText),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('التوصيل إلى', style: TextStyle(fontSize: 12, color: C.muted)),
                    Text(ready ? line : 'أضف عنوان التوصيل',
                        maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
                  ],
                ),
              ),
              Text(ready ? 'تغيير' : 'إضافة', style: const TextStyle(fontWeight: FontWeight.w700, color: C.primary)),
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
                          Text(subtitle, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: C.muted)),
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
  const _Summary({required this.cart, required this.busy, required this.label, required this.onPressed});
  final Cart cart;
  final bool busy;
  final String label;
  final VoidCallback onPressed;

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
          _row('المنتجات (${cart.itemsCount})', cart.subtotal.label),
          _row('الشحن', cart.needsShipping && cart.rates.isEmpty ? 'بعد إضافة العنوان' : cart.shipping.label),
          for (final f in cart.fees) _row(f.name, f.total.label),
          if (!cart.discount.isZero) _row('الخصم', '- ${cart.discount.label}'),
          const Divider(color: C.line, height: 12),
          Row(
            children: [
              const Expanded(child: Text('الإجمالي', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
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
  bool _saving = false;

  static const _cities = ['الرياض', 'جدة', 'مكة المكرمة', 'المدينة المنورة', 'الدمام', 'الخبر', 'الظهران', 'الطائف', 'بريدة', 'تبوك', 'أبها', 'خميس مشيط', 'حائل', 'جازان', 'نجران', 'الأحساء', 'الجبيل', 'ينبع', 'القطيف', 'عنيزة'];

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
      'city': TextEditingController(text: pick('city', 'الرياض')),
      'address_1': TextEditingController(text: pick('address_1')),
      'address_2': TextEditingController(text: pick('address_2')),
    };
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _saving = true);
    final v = {for (final e in _c.entries) e.key: e.value.text.trim()};
    final shipping = {
      'first_name': v['first_name'],
      'last_name': v['last_name'],
      'address_1': v['address_1'],
      'address_2': v['address_2'],
      'city': v['city'],
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
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    String? requiredField(String? v) => v == null || v.trim().isEmpty ? 'مطلوب' : null;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
      child: Form(
        key: _form,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('عنوان التوصيل', style: display(18)),
              const SizedBox(height: 14),
              Row(
                children: [
                  Expanded(child: TextFormField(controller: _c['first_name'], validator: requiredField, decoration: const InputDecoration(labelText: 'الاسم'))),
                  const SizedBox(width: 10),
                  Expanded(child: TextFormField(controller: _c['last_name'], decoration: const InputDecoration(labelText: 'اسم العائلة'))),
                ],
              ),
              const SizedBox(height: 10),
              TextFormField(
                controller: _c['phone'],
                validator: requiredField,
                keyboardType: TextInputType.phone,
                textDirection: TextDirection.ltr,
                decoration: const InputDecoration(labelText: 'رقم الجوال'),
              ),
              const SizedBox(height: 10),
              Autocomplete<String>(
                initialValue: TextEditingValue(text: _c['city']!.text),
                optionsBuilder: (t) => _cities.where((c) => c.contains(t.text)),
                onSelected: (v) => _c['city']!.text = v,
                fieldViewBuilder: (context, ctrl, focus, onSubmit) {
                  return TextFormField(
                    controller: ctrl,
                    focusNode: focus,
                    onChanged: (v) => _c['city']!.text = v,
                    validator: requiredField,
                    decoration: const InputDecoration(labelText: 'المدينة'),
                  );
                },
              ),
              const SizedBox(height: 10),
              TextFormField(controller: _c['address_1'], validator: requiredField, decoration: const InputDecoration(labelText: 'الحي والشارع')),
              const SizedBox(height: 10),
              TextFormField(controller: _c['address_2'], decoration: const InputDecoration(labelText: 'تفاصيل إضافية (رقم المبنى، أقرب معلم)')),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: _saving
                    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                    : const Text('احفظ العنوان'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
