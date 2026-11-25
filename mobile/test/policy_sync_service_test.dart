import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:servalillo_tracker/core/clock.dart';
import 'package:servalillo_tracker/data/secure_store.dart';
import 'package:servalillo_tracker/models/device_policies.dart';
import 'package:servalillo_tracker/services/policy_sync_service.dart';
import 'package:servalillo_tracker/services/tracker_api.dart';

/// Simula el plugin nativo: recuerda lo aplicado y si sigue siendo Device Owner.
class FakePort implements DevicePolicyPort {
  FakePort({this.owner = true});

  bool owner;
  DevicePolicies current = DevicePolicies.none;
  int releases = 0;

  AppliedPolicies get _state => AppliedPolicies(
    deviceOwner: owner,
    policies: owner ? current : DevicePolicies.none,
  );

  @override
  Future<AppliedPolicies> state() async => _state;

  @override
  Future<AppliedPolicies> apply(DevicePolicies p) async {
    if (owner) current = p;
    return _state;
  }

  @override
  Future<AppliedPolicies> release() async {
    releases++;
    current = DevicePolicies.none;
    owner = false;
    return _state;
  }
}

Map<String, dynamic> deviceJson({
  Map<String, dynamic>? policies,
  Map<String, dynamic>? unlock,
}) => <String, dynamic>{
  'device_id': 1,
  'label': 'Tracker',
  'is_active': true,
  'driver': null,
  'tracking': <String, dynamic>{},
  'policies': policies ?? <String, dynamic>{},
  'unlock': unlock,
  'server_time': '2026-11-25T10:00:00+01:00',
};

void main() {
  late SecureStore store;
  late FakePort port;
  late FixedClock clock;
  final List<Map<String, dynamic>> reports = <Map<String, dynamic>>[];

  setUp(() {
    store = SecureStore(InMemoryKeyValueStore());
    port = FakePort();
    clock = FixedClock(DateTime(2026, 11, 25, 10));
    reports.clear();
  });

  PolicySyncService service(http.Response Function(http.Request) device) =>
      PolicySyncService(
        store: store,
        port: port,
        clock: clock,
        appVersion: '1.1.0',
        api: TrackerApi(
          MockClient((http.Request r) async {
            if (r.url.path == '/api/device/state') {
              reports.add(jsonDecode(r.body) as Map<String, dynamic>);
              return http.Response('{"ok":true}', 200);
            }
            return device(r);
          }),
          'https://s.test',
        ),
      );

  test('aplica lo que pide el servidor, guarda la clave y reporta', () async {
    await store.setToken('tok');
    final result = await service(
      (_) => http.Response(
        jsonEncode(
          deviceJson(
            policies: <String, dynamic>{
              'block_screen_capture': true,
              'block_uninstall': true,
            },
            unlock: <String, dynamic>{
              'hash': 'abc',
              'salt': 'def',
              'iterations': 100000,
            },
          ),
        ),
        200,
      ),
    ).sync();

    expect(port.current.blockScreenCapture, isTrue);
    expect(port.current.blockUninstall, isTrue);
    expect(port.current.blockFactoryReset, isFalse);
    expect(result.status, isNotNull);
    expect((await store.unlock)?.hash, 'abc');
    expect(reports.single['device_owner'], isTrue);
    expect((reports.single['applied'] as Map)['block_screen_capture'], isTrue);
    expect(reports.single['app_version'], '1.1.0');
  });

  test('sin red reaplica la última política guardada', () async {
    await store.setToken('tok');
    await store.setPolicies(const DevicePolicies(blockScreenCapture: true));

    final result = await service((_) => http.Response('', 503)).sync();

    expect(result.status, isNull);
    expect(port.current.blockScreenCapture, isTrue);
  });

  test('si la web quita la clave, se borra también en el móvil', () async {
    await store.setToken('tok');
    await store.setUnlock(
      const UnlockConfig(hash: 'a', salt: 'b', iterations: 1),
    );

    await service((_) => http.Response(jsonEncode(deviceJson()), 200)).sync();

    expect(await store.unlock, isNull);
  });

  test('release del servidor → libera el terminal y lo reporta', () async {
    await store.setToken('tok');
    port.current = const DevicePolicies(blockScreenCapture: true);

    await service(
      (_) => http.Response(
        jsonEncode(deviceJson(policies: <String, dynamic>{'release': true})),
        200,
      ),
    ).sync();

    expect(port.releases, 1);
    expect(port.owner, isFalse);
    expect(reports.single['device_owner'], isFalse);
  });

  test(
    'la suspensión temporal quita las restricciones y caduca sola',
    () async {
      await store.setPolicies(const DevicePolicies(blockScreenCapture: true));
      final svc = service((_) => http.Response('', 503));

      await svc.suspend(const Duration(minutes: 30));
      expect(port.current.any, isFalse);

      clock.value = clock.value.add(const Duration(minutes: 31));
      await svc.applyStored();
      expect(port.current.blockScreenCapture, isTrue);
      expect(await store.suspendedUntil, isNull);
    },
  );

  test('sin Device Owner no aplica nada y lo reporta así', () async {
    await store.setToken('tok');
    port.owner = false;

    final result = await service(
      (_) => http.Response(
        jsonEncode(
          deviceJson(policies: <String, dynamic>{'block_screen_capture': true}),
        ),
        200,
      ),
    ).sync();

    expect(result.applied.deviceOwner, isFalse);
    expect(reports.single['device_owner'], isFalse);
  });

  test(
    'modo perdido del servidor → se aplica con su mensaje y se reporta',
    () async {
      await store.setToken('tok');

      await service(
        (_) => http.Response(
          jsonEncode(
            deviceJson(
              policies: <String, dynamic>{
                'lost_mode': true,
                'lost_message': 'Llame a la empresa',
              },
            ),
          ),
          200,
        ),
      ).sync();

      expect(port.current.lostMode, isTrue);
      expect(port.current.lostMessage, 'Llame a la empresa');
      expect((reports.single['applied'] as Map)['lost_mode'], isTrue);
    },
  );

  test('la suspensión temporal no quita el modo perdido', () async {
    await store.setPolicies(
      const DevicePolicies(
        blockScreenCapture: true,
        lostMode: true,
        lostMessage: 'Perdido',
      ),
    );
    final svc = service((_) => http.Response('', 503));

    await svc.suspend(const Duration(minutes: 30));

    expect(port.current.blockScreenCapture, isFalse);
    expect(port.current.lostMode, isTrue);
  });

  test('sin token no llama a la API pero aplica lo guardado', () async {
    await store.setPolicies(const DevicePolicies(blockSafeBoot: true));
    int calls = 0;

    await service((_) {
      calls++;
      return http.Response('', 200);
    }).sync();

    expect(calls, 0);
    expect(reports, isEmpty);
    expect(port.current.blockSafeBoot, isTrue);
  });
}
