import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/app_api.dart';
import '../api/client.dart';
import '../api/store_api.dart';
import '../models/models.dart';

/// Overridden in main() with the ready client.
final apiClientProvider = Provider<ApiClient>((ref) => throw UnimplementedError());
final storeApiProvider = Provider((ref) => StoreApi(ref.watch(apiClientProvider)));
final appApiProvider = Provider((ref) => AppApi(ref.watch(apiClientProvider)));

final configProvider = FutureProvider<StoreConfig>((ref) => ref.watch(appApiProvider).config());

// ---------------------------------------------------------------- session

class SessionNotifier extends Notifier<AppUser?> {
  @override
  AppUser? build() {
    _restore();
    return null;
  }

  ApiClient get _client => ref.read(apiClientProvider);
  AppApi get _api => ref.read(appApiProvider);

  bool get isLoggedIn => _client.authToken != null;

  Future<void> _restore() async {
    if (_client.authToken == null) return;
    try {
      state = await _api.me();
    } catch (_) {
      // Token expired or offline; the client clears an invalid token itself.
    }
  }

  Future<void> requestOtp(String phone) => _api.requestOtp(phone);

  Future<void> verifyOtp(String phone, String code, {String? name}) async {
    final (token, user) = await _api.verifyOtp(phone, code, name: name);
    await _client.setAuthToken(token);
    state = user;
    ref.invalidate(petsProvider);
    ref.invalidate(ordersProvider);
    await ref.read(cartProvider.notifier).refresh();
  }

  Future<void> updateProfile(Map<String, dynamic> fields) async {
    state = await _api.updateMe(fields);
  }

  Future<void> logout() async {
    try {
      await _api.logout();
    } catch (_) {}
    await _client.setAuthToken(null);
    await _client.resetCart();
    state = null;
    ref.invalidate(petsProvider);
    ref.invalidate(ordersProvider);
    ref.invalidate(cartProvider);
  }

  Future<void> deleteAccount() async {
    await _api.deleteAccount();
    await logout();
  }
}

final sessionProvider = NotifierProvider<SessionNotifier, AppUser?>(SessionNotifier.new);

// ---------------------------------------------------------------- cart

class CartNotifier extends AsyncNotifier<Cart> {
  StoreApi get _api => ref.read(storeApiProvider);

  @override
  Future<Cart> build() => _api.cart();

  Future<void> refresh() async {
    state = AsyncData(await _api.cart());
  }

  Future<void> _run(Future<Cart> Function() op) async {
    final previous = state.valueOrNull;
    try {
      state = AsyncData(await op());
    } catch (e) {
      if (previous != null) state = AsyncData(previous);
      rethrow;
    }
  }

  Future<void> add(int id, int qty, {List<Map<String, String>>? variation}) =>
      _run(() => _api.addItem(id, qty, variation: variation));

  Future<void> setQuantity(String key, int qty) =>
      _run(() => qty <= 0 ? _api.removeItem(key) : _api.updateItem(key, qty));

  Future<void> remove(String key) => _run(() => _api.removeItem(key));

  Future<void> updateAddress(Map<String, dynamic> billing, Map<String, dynamic> shipping) =>
      _run(() => _api.updateCustomer(billing, shipping));

  Future<void> selectRate(int packageId, String rateId) => _run(() => _api.selectShippingRate(packageId, rateId));

  Future<void> selectPayment(String id) => _run(() => _api.selectPaymentMethod(id));
}

final cartProvider = AsyncNotifierProvider<CartNotifier, Cart>(CartNotifier.new);

final cartCountProvider = Provider<int>((ref) => ref.watch(cartProvider).valueOrNull?.itemsCount ?? 0);

// ---------------------------------------------------------------- pets

class PetsNotifier extends AsyncNotifier<List<Pet>> {
  AppApi get _api => ref.read(appApiProvider);

  @override
  Future<List<Pet>> build() async {
    ref.watch(sessionProvider);
    if (ref.read(apiClientProvider).authToken == null) return const [];
    return _api.pets();
  }

  Future<Pet> save(Pet pet, {String? id}) async {
    final saved = id == null ? await _api.createPet(pet) : await _api.updatePet(id, pet.toJson());
    final list = [...?state.valueOrNull];
    final i = list.indexWhere((p) => p.id == saved.id);
    if (i >= 0) {
      list[i] = saved;
    } else {
      list.add(saved);
    }
    state = AsyncData(list);
    return saved;
  }

  Future<void> setRemind(Pet pet, bool on) async {
    final saved = await _api.updatePet(pet.id, {'remind': on});
    state = AsyncData([for (final p in state.valueOrNull ?? const <Pet>[]) p.id == pet.id ? saved : p]);
  }

  Future<void> delete(String id) async {
    await _api.deletePet(id);
    state = AsyncData([for (final p in state.valueOrNull ?? const <Pet>[]) if (p.id != id) p]);
  }
}

final petsProvider = AsyncNotifierProvider<PetsNotifier, List<Pet>>(PetsNotifier.new);

/// The pet chosen on the home screen; null = first pet.
final selectedPetIdProvider = StateProvider<String?>((ref) => null);

final selectedPetProvider = Provider<Pet?>((ref) {
  final pets = ref.watch(petsProvider).valueOrNull ?? const [];
  if (pets.isEmpty) return null;
  final id = ref.watch(selectedPetIdProvider);
  return pets.firstWhere((p) => p.id == id, orElse: () => pets.first);
});

final petStockProvider = FutureProvider.family<List<StockItem>, String>(
  (ref, petId) => ref.watch(appApiProvider).petStock(petId),
);

// ---------------------------------------------------------------- catalogue

class ProductQuery {
  const ProductQuery({this.category, this.search, this.onSale = false, this.include, this.orderBy});
  final int? category;
  final String? search;
  final bool onSale;
  final List<int>? include;
  final String? orderBy;

  @override
  bool operator ==(Object other) =>
      other is ProductQuery &&
      other.category == category &&
      other.search == search &&
      other.onSale == onSale &&
      other.orderBy == orderBy &&
      (other.include ?? const []).join(',') == (include ?? const []).join(',');

  @override
  int get hashCode => Object.hash(category, search, onSale, orderBy, (include ?? const []).join(','));
}

final productsProvider = FutureProvider.family<List<Product>, ProductQuery>((ref, q) async {
  final (list, _) = await ref.watch(storeApiProvider).products(
        category: q.category,
        search: q.search,
        onSale: q.onSale,
        include: q.include,
        orderBy: q.orderBy,
        perPage: 12,
      );
  return list;
});

final productProvider = FutureProvider.family<Product, int>((ref, id) => ref.watch(storeApiProvider).product(id));

/// Products picked for the selected pet (falls back to products on sale).
final forPetProvider = FutureProvider<List<Product>>((ref) async {
  final pet = ref.watch(selectedPetProvider);
  final store = ref.watch(storeApiProvider);
  if (pet != null) {
    try {
      final ids = await ref.watch(appApiProvider).recommendations(petId: pet.id);
      if (ids.isNotEmpty) {
        final (list, _) = await store.products(include: ids.take(12).toList(), perPage: 12);
        if (list.isNotEmpty) return list;
      }
    } catch (_) {}
  }
  final (list, _) = await store.products(onSale: true, perPage: 12);
  return list;
});

// ---------------------------------------------------------------- orders

final ordersProvider = FutureProvider<List<OrderSummary>>((ref) async {
  ref.watch(sessionProvider);
  if (ref.read(apiClientProvider).authToken == null) return const [];
  return ref.watch(appApiProvider).orders();
});

final orderProvider = FutureProvider.family<OrderDetail, int>((ref, id) => ref.watch(appApiProvider).order(id));
