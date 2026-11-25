import 'dart:convert';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';

import '../models/device_policies.dart';

/// PBKDF2-HMAC-SHA256, igual que `App\Services\DevicePinHasher` del servidor (la sal es la cadena
/// hex tal cual, como hace `hash_pbkdf2` de PHP). Los dos tests comparten un vector fijo.
String pbkdf2Sha256Hex(
  String password,
  String salt,
  int iterations, {
  int keyLength = 32,
}) {
  final Hmac hmac = Hmac(sha256, utf8.encode(password));
  final Uint8List saltBytes = Uint8List.fromList(utf8.encode(salt));
  final BytesBuilder out = BytesBuilder();
  int block = 1;

  while (out.length < keyLength) {
    final Uint8List first = Uint8List(saltBytes.length + 4)
      ..setAll(0, saltBytes)
      ..buffer.asByteData().setUint32(saltBytes.length, block);
    List<int> u = hmac.convert(first).bytes;
    final Uint8List t = Uint8List.fromList(u);
    for (int i = 1; i < iterations; i++) {
      u = hmac.convert(u).bytes;
      for (int j = 0; j < t.length; j++) {
        t[j] ^= u[j];
      }
    }
    out.add(t);
    block++;
  }

  final Uint8List key = out.toBytes().sublist(0, keyLength);
  return key.map((int b) => b.toRadixString(16).padLeft(2, '0')).join();
}

bool verifyPin(String pin, UnlockConfig config) {
  final String computed = pbkdf2Sha256Hex(pin, config.salt, config.iterations);
  final String expected = config.hash.toLowerCase();
  if (computed.length != expected.length) return false;
  int diff = 0;
  for (int i = 0; i < computed.length; i++) {
    diff |= computed.codeUnitAt(i) ^ expected.codeUnitAt(i);
  }
  return diff == 0;
}

/// Lo mismo, en otro isolate (100k iteraciones bloquearían la interfaz).
Future<bool> verifyPinInBackground(String pin, UnlockConfig config) =>
    compute(_verify, <String, Object>{'pin': pin, 'config': config.toJson()});

bool _verify(Map<String, Object> args) =>
    verifyPin(args['pin']! as String, UnlockConfig.fromJson(args['config'])!);
