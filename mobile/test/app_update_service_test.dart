import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:servalillo_tracker/core/clock.dart';
import 'package:servalillo_tracker/data/secure_store.dart';
import 'package:servalillo_tracker/models/app_update_info.dart';
import 'package:servalillo_tracker/models/device_policies.dart';
import 'package:servalillo_tracker/services/app_update_service.dart';
import 'package:servalillo_tracker/services/tracker_api.dart';

class FakeInstaller implements AppInstallerPort {
  FakeInstaller({this.installed = 2, this.ok = true});

  int installed;
  bool ok;
  final List<String> installs = <String>[];

  @override
  Future<int> installedVersionCode() async => installed;

  @override
  Future<bool> install(String path) async {
    installs.add(path);
    return ok;
  }
}

const AppliedPolicies owner = AppliedPolicies(
  deviceOwner: true,
  policies: DevicePolicies.none,
);
const AppliedPolicies notOwner = AppliedPolicies(
  deviceOwner: false,
  policies: DevicePolicies.none,
);

final List<int> apkBytes = utf8.encode('APK-NUEVA');
final String apkSha = sha256.convert(apkBytes).toString();

AppUpdateInfo update({int code = 3, String? sha}) =>
    AppUpdateInfo(versionCode: code, sha256: sha ?? apkSha, size: 9);

void main() {
  late Directory tmp;
  late SecureStore store;
  late FakeInstaller installer;
  late FixedClock clock;
  late int downloads;

  setUp(() async {
    tmp = await Directory.systemTemp.createTemp('upd');
    store = SecureStore(InMemoryKeyValueStore());
    await store.setToken('tok');
    installer = FakeInstaller();
    clock = FixedClock(DateTime(2026, 11, 25, 10));
    downloads = 0;
  });

  tearDown(() async => tmp.delete(recursive: true));

  AppUpdateService service({List<int>? body, int status = 200}) =>
      AppUpdateService(
        store: store,
        installer: installer,
        cacheDir: () async => tmp,
        clock: clock,
        api: TrackerApi(
          MockClient((http.Request r) async {
            downloads++;
            expect(r.url.path, '/api/device/apk');
            expect(r.headers['Authorization'], 'Bearer tok');
            return http.Response.bytes(body ?? apkBytes, status);
          }),
          'https://s.test',
        ),
      );

  test('versión más nueva → descarga, comprueba la huella e instala', () async {
    final outcome = await service().maybeUpdate(update(), owner);

    expect(outcome, UpdateOutcome.installing);
    expect(installer.installs, hasLength(1));
    expect(File(installer.installs.single).readAsBytesSync(), apkBytes);
  });

  test('ya está al día → no descarga nada', () async {
    installer.installed = 3;
    final outcome = await service().maybeUpdate(update(code: 3), owner);

    expect(outcome, UpdateOutcome.upToDate);
    expect(downloads, 0);
  });

  test('sin APK publicada → nada', () async {
    expect(await service().maybeUpdate(null, owner), UpdateOutcome.upToDate);
    expect(downloads, 0);
  });

  test('sin Device Owner no se descarga (no podría instalarse sola)', () async {
    final outcome = await service().maybeUpdate(update(), notOwner);

    expect(outcome, UpdateOutcome.notManaged);
    expect(downloads, 0);
  });

  test('huella distinta → no instala y borra el fichero', () async {
    final outcome = await service().maybeUpdate(update(sha: 'f' * 64), owner);

    expect(outcome, UpdateOutcome.failed);
    expect(installer.installs, isEmpty);
    expect(
      File('${tmp.path}/${AppUpdateService.fileName}').existsSync(),
      isFalse,
    );
  });

  test(
    'error del servidor → failed, y no reintenta la misma versión hasta pasadas 6 h',
    () async {
      final svc = service(status: 503);

      expect(await svc.maybeUpdate(update(), owner), UpdateOutcome.failed);
      expect(await svc.maybeUpdate(update(), owner), UpdateOutcome.backingOff);
      expect(downloads, 1);

      clock.value = clock.value.add(const Duration(hours: 7));
      expect(await svc.maybeUpdate(update(), owner), UpdateOutcome.failed);
      expect(downloads, 2);
    },
  );

  test(
    'una versión aún más nueva no espera al reintento de la anterior',
    () async {
      await service(status: 503).maybeUpdate(update(code: 3), owner);

      final outcome = await service().maybeUpdate(update(code: 4), owner);
      expect(outcome, UpdateOutcome.installing);
    },
  );
}
