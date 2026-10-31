import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/app_api.dart';
import '../api/client.dart';
import '../api/store_api.dart';
import '../l10n/lang.dart';
import '../models/models.dart';
import '../services/local_store.dart';

/// Overridden in main() with the ready client.
final apiClientProvider = Provider<ApiClient>((ref) => throw UnimplementedError());
// Both APIs watch the language, so everything fetched through them reloads when it changes.
final storeApiProvider = Provider((ref) {
  ref.watch(langProvider);
  return StoreApi(ref.watch(apiClientProvider));
});
final appApiProvider = Provider((ref) {
  ref.watch(langProvider);
  return AppApi(ref.watch(apiClientProvider));
});

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

  /// Returns the channel used ("whatsapp" / "sms").
  Future<String> requestOtp(String phone, {String? channel}) => _api.requestOtp(phone, channel: channel);

  /// Returns how many earlier orders (placed with this phone) were linked to the account.
  Future<int> verifyOtp(String phone, String code, {String? name}) async {
    final (token, user, linked) = await _api.verifyOtp(phone, code, name: name);
    await _client.setAuthToken(token);
    state = user;
    ref.invalidate(petsProvider);
    ref.invalidate(ordersProvider);
    ref.invalidate(purchasedProductsProvider);
    await ref.read(cartProvider.notifier).refresh();
    return linked;
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
  Future<Cart> build() {
    ref.watch(langProvider);
    return _api.cart();
  }

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

  Future<void> add(int id, int qty, {List<Map<String, String>>? variation, String designText = ''}) =>
      _run(() => _api.addItem(id, qty, variation: variation, designText: designText));

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


// ---------------------------------------------------------------- home sections & search

/// Products for a list of ids, in the same order. Key is "12,7,40".
final productsByIdsProvider = FutureProvider.family<List<Product>, String>((ref, key) async {
  final ids = [for (final s in key.split(',')) int.tryParse(s) ?? 0].where((i) => i > 0).toList();
  if (ids.isEmpty) return const [];
  final (list, _) = await ref.watch(storeApiProvider).products(include: ids, perPage: ids.length.clamp(1, 60));
  final byId = {for (final p in list) p.id: p};
  return [for (final id in ids) if (byId[id] != null) byId[id]!];
});

String idsKey(Iterable<int> ids) => ids.join(',');

int? _petCategory(Ref ref) {
  final pet = ref.watch(selectedPetProvider);
  if (pet == null) return null;
  final cats = ref.watch(configProvider).valueOrNull?.petCategories ?? const {};
  final id = cats[pet.kind];
  return id == null || id == 0 ? null : id;
}

/// Best sellers — for the selected pet's kind when we know its category.
final bestSellersProvider = FutureProvider<List<Product>>((ref) async {
  final (list, _) = await ref.watch(storeApiProvider).products(
        category: _petCategory(ref),
        orderBy: 'popularity',
        perPage: 12,
      );
  return list;
});

final newArrivalsProvider = FutureProvider<List<Product>>((ref) async {
  final (list, _) = await ref.watch(storeApiProvider).products(
        category: _petCategory(ref),
        orderBy: 'date',
        perPage: 12,
      );
  return list;
});

final offersProvider = FutureProvider<List<Product>>((ref) async {
  final (list, _) = await ref.watch(storeApiProvider).products(onSale: true, orderBy: 'popularity', perPage: 12);
  return list;
});

final recentlyViewedProductsProvider = FutureProvider<List<Product>>((ref) async {
  final ids = ref.watch(recentlyViewedProvider);
  if (ids.isEmpty) return const [];
  return ref.watch(productsByIdsProvider(idsKey(ids.take(12))).future);
});

/// What the customer bought before ("اشتريته قبل").
final purchasedProductsProvider = FutureProvider<List<Product>>((ref) async {
  ref.watch(sessionProvider);
  if (ref.read(apiClientProvider).authToken == null) return const [];
  try {
    final ids = await ref.watch(appApiProvider).purchased();
    if (ids.isEmpty) return const [];
    return ref.watch(productsByIdsProvider(idsKey(ids.take(12))).future);
  } catch (_) {
    return const [];
  }
});

class SearchArgs {
  const SearchArgs(this.q, {this.ai = false, this.log = false});
  final String q;
  final bool ai;
  final bool log;

  @override
  bool operator ==(Object other) => other is SearchArgs && other.q == q && other.ai == ai && other.log == log;

  @override
  int get hashCode => Object.hash(q, ai, log);
}

final searchProvider = FutureProvider.autoDispose.family<SearchResult, SearchArgs>((ref, a) {
  final pet = ref.watch(selectedPetProvider);
  return ref.watch(appApiProvider).search(a.q, petId: pet?.id, ai: a.ai, log: a.log);
});

final pageProvider = FutureProvider.family<PageContent, int>((ref, id) => ref.watch(appApiProvider).page(id));

final subcategoriesProvider = FutureProvider.family<List<Category>, int>(
  (ref, parent) => ref.watch(storeApiProvider).subcategories(parent),
);

/// Category tree: parent id -> children (only categories that have products somewhere below).
final categoryTreeProvider = FutureProvider<Map<int, List<Category>>>((ref) async {
  final all = await ref.watch(storeApiProvider).allCategories();
  final byParent = <int, List<Category>>{};
  for (final c in all) {
    (byParent[c.parent] ??= []).add(c);
  }
  // Keep a category if it, or anything under it, has products.
  final memo = <int, bool>{};
  bool has(Category c) => memo[c.id] ??= c.count > 0 || (byParent[c.id] ?? const []).any(has);
  return {
    for (final e in byParent.entries) e.key: e.value.where(has).toList(),
  };
});
