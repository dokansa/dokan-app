import '../config.dart';
import '../models/models.dart';
import 'client.dart';

/// The Dokan App API plugin on the store.
class AppApi {
  AppApi(this._c);
  final ApiClient _c;

  String get _b => AppConfig.appApi;
  Map<String, dynamic> _m(dynamic d) => Map<String, dynamic>.from(d as Map);

  Future<StoreConfig> config() async => StoreConfig.fromJson(_m(await _c.get('$_b/config')));

  /// Returns the channel the code was actually sent on ("whatsapp" or "sms").
  Future<String> requestOtp(String phone, {String? channel}) async {
    final d = _m(await _c.post('$_b/auth/otp/request', body: {
      'phone': phone,
      if (channel != null) 'channel': channel,
    }));
    return d['channel']?.toString() ?? channel ?? '';
  }

  /// Returns the token, the user and how many earlier guest orders were linked to the account.
  Future<(String, AppUser, int)> verifyOtp(String phone, String code, {String? name}) async {
    final d = _m(await _c.post('$_b/auth/otp/verify', body: {
      'phone': phone,
      'code': code,
      if (name != null && name.isNotEmpty) 'name': name,
    }));
    return (d['token'].toString(), AppUser.fromJson(_m(d['user'])), int.tryParse('${d['linked_orders'] ?? 0}') ?? 0);
  }

  Future<(String, AppUser)> login(String username, String password) async {
    final d = _m(await _c.post('$_b/auth/login', body: {'username': username, 'password': password}));
    return (d['token'].toString(), AppUser.fromJson(_m(d['user'])));
  }

  Future<void> logout() => _c.post('$_b/auth/logout');

  Future<AppUser> me() async => AppUser.fromJson(_m(await _c.get('$_b/me')));

  Future<AppUser> updateMe(Map<String, dynamic> fields) async =>
      AppUser.fromJson(_m(await _c.patch('$_b/me', body: fields)));

  Future<void> deleteAccount() => _c.delete('$_b/me');

  Future<List<Pet>> pets() async =>
      [for (final p in (await _c.get('$_b/pets') as List)) Pet.fromJson(_m(p))];

  Future<Pet> createPet(Pet p) async => Pet.fromJson(_m(await _c.post('$_b/pets', body: p.toJson())));

  Future<Pet> updatePet(String id, Map<String, dynamic> fields) async =>
      Pet.fromJson(_m(await _c.patch('$_b/pets/$id', body: fields)));

  Future<void> deletePet(String id) => _c.delete('$_b/pets/$id');

  Future<List<StockItem>> petStock(String id) async =>
      [for (final s in (await _c.get('$_b/pets/$id/stock') as List)) StockItem.fromJson(_m(s))];

  Future<void> registerDevice(String token, String platform) =>
      _c.post('$_b/devices', body: {'token': token, 'platform': platform});

  Future<void> unregisterDevice(String token) => _c.delete('$_b/devices', body: {'token': token});

  Future<List<OrderSummary>> orders({int page = 1}) async =>
      [for (final o in (await _c.get('$_b/orders', query: {'page': page}) as List)) OrderSummary.fromJson(_m(o))];

  Future<OrderDetail> order(int id) async => OrderDetail.fromJson(_m(await _c.get('$_b/orders/$id')));

  /// Returns either `added` (plugin filled the cart) or the items for the app to add itself.
  Future<ReorderResult> reorder(int id) async {
    final d = _m(await _c.post('$_b/orders/$id/reorder'));
    final items = <(int, int)>[
      for (final i in (d['items'] as List? ?? const []))
        (int.tryParse('${(i as Map)['id']}') ?? 0, int.tryParse('${i['quantity']}') ?? 1)
    ];
    return ReorderResult(int.tryParse('${d['added'] ?? 0}') ?? 0, items);
  }

  Future<String> cancelRequest(int id, String reason) async {
    final d = _m(await _c.post('$_b/orders/$id/cancel-request', body: {'reason': reason}));
    return d['status']?.toString() ?? '';
  }

  Future<SearchResult> search(String q, {String? petId, bool ai = false, bool log = false}) async =>
      SearchResult.fromJson(_m(await _c.get('$_b/search', query: {
        'q': q,
        if (petId != null) 'pet_id': petId,
        'ai': ai ? 1 : 0,
        'log': log ? 1 : 0,
      })));

  Future<ChatMessage> assistant(List<ChatMessage> messages, {String? petId}) async {
    final d = _m(await _c.post('$_b/assistant', body: {
      'messages': [for (final m in messages.length > 10 ? messages.sublist(messages.length - 10) : messages) m.toJson()],
      if (petId != null) 'pet_id': petId,
    }));
    return ChatMessage(
      fromUser: false,
      text: cleanText(d['reply']),
      productIds: [for (final i in (d['product_ids'] as List? ?? const [])) int.tryParse('$i') ?? 0].where((i) => i > 0).toList(),
      chips: [for (final c in (d['chips'] as List? ?? const [])) cleanText(c)].where((c) => c.isNotEmpty).toList(),
    );
  }

  Future<List<int>> purchased() async {
    final d = await _c.get('$_b/me/purchased');
    return [for (final i in (d as List)) int.tryParse('$i') ?? 0].where((i) => i > 0).toList();
  }

  Future<PageContent> page(int id) async {
    final d = _m(await _c.get('$_b/pages/$id'));
    return PageContent(cleanText(d['title']), d['content_html']?.toString() ?? '');
  }

  Future<List<int>> recommendations({String? petId}) async {
    final d = await _c.get('$_b/recommendations', query: {if (petId != null) 'pet_id': petId});
    return [for (final i in (d as List)) int.tryParse('$i') ?? 0].where((i) => i > 0).toList();
  }
}

class ReorderResult {
  ReorderResult(this.added, this.items);
  final int added;
  final List<(int, int)> items;
}
