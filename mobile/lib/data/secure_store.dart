import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/tracking_config.dart';

/// Almacén clave-valor simple (para poder testear sin el keystore de Android).
abstract class KeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class SecureKeyValueStore implements KeyValueStore {
  SecureKeyValueStore()
    : _storage = const FlutterSecureStorage(
        aOptions: AndroidOptions(encryptedSharedPreferences: true),
      );

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

class InMemoryKeyValueStore implements KeyValueStore {
  final Map<String, String> _map = <String, String>{};

  @override
  Future<String?> read(String key) async => _map[key];

  @override
  Future<void> write(String key, String value) async => _map[key] = value;

  @override
  Future<void> delete(String key) async => _map.remove(key);
}

enum EnrolState { none, enrolled, revoked, deactivated }

/// Estado persistente del tracker: identificador, token, config, última señal.
class SecureStore {
  SecureStore(this._kv);

  final KeyValueStore _kv;

  static const String _kInstallId = 'install_identifier';
  static const String _kToken = 'token';
  static const String _kDeviceId = 'device_id';
  static const String _kConfig = 'tracking_config';
  static const String _kState = 'enrol_state';
  static const String _kLastSent = 'last_sent_at';
  static const String _kDropped = 'dropped_count';

  Future<String?> get installIdentifier => _kv.read(_kInstallId);
  Future<void> setInstallIdentifier(String v) => _kv.write(_kInstallId, v);

  Future<String?> get token => _kv.read(_kToken);
  Future<void> setToken(String v) => _kv.write(_kToken, v);
  Future<void> clearToken() => _kv.delete(_kToken);

  Future<int?> get deviceId async =>
      int.tryParse(await _kv.read(_kDeviceId) ?? '');
  Future<void> setDeviceId(int v) => _kv.write(_kDeviceId, v.toString());

  Future<TrackingConfig> get config async =>
      TrackingConfig.decode(await _kv.read(_kConfig));
  Future<void> setConfig(TrackingConfig c) => _kv.write(_kConfig, c.encode());

  Future<EnrolState> get state async {
    final String? raw = await _kv.read(_kState);
    return EnrolState.values.firstWhere(
      (EnrolState s) => s.name == raw,
      orElse: () => EnrolState.none,
    );
  }

  Future<void> setState(EnrolState s) => _kv.write(_kState, s.name);

  Future<DateTime?> get lastSentAt async {
    final String? raw = await _kv.read(_kLastSent);
    return raw == null ? null : DateTime.tryParse(raw);
  }

  Future<void> setLastSentAt(DateTime t) =>
      _kv.write(_kLastSent, t.toUtc().toIso8601String());

  Future<int> get droppedCount async =>
      int.tryParse(await _kv.read(_kDropped) ?? '0') ?? 0;
  Future<void> addDropped(int n) async =>
      _kv.write(_kDropped, ((await droppedCount) + n).toString());
}
