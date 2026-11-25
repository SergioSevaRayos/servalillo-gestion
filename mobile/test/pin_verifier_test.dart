import 'package:flutter_test/flutter_test.dart';
import 'package:servalillo_tracker/models/device_policies.dart';
import 'package:servalillo_tracker/services/pin_verifier.dart';

// Vector compartido con server/tests/Unit/DevicePinHasherTest.php (hash_pbkdf2 de PHP).
const String vectorSalt = '00112233445566778899aabbccddeeff';
const String vectorHash =
    'd722ea95af8abb45631ab78d40ddc19fab580bc4732cd0a9572ebba118e451c4';

void main() {
  test('PBKDF2 coincide con el del servidor (vector fijo)', () {
    expect(pbkdf2Sha256Hex('1234', vectorSalt, 100000), vectorHash);
  });

  test('vector RFC 7914 de PBKDF2-HMAC-SHA256 (1 iteración)', () {
    expect(
      pbkdf2Sha256Hex('passwd', 'salt', 1, keyLength: 64),
      '55ac046e56e3089fec1691c22544b605f94185216dde0465e68b9d57c20dacbc'
      '49ca9cccf179b645991664b39d77ef317c71b845b1e30bd509112041d3a19783',
    );
  });

  test('verifyPin acepta la clave correcta y rechaza la incorrecta', () {
    const UnlockConfig config = UnlockConfig(
      hash: vectorHash,
      salt: vectorSalt,
      iterations: 100000,
    );
    expect(verifyPin('1234', config), isTrue);
    expect(verifyPin('1235', config), isFalse);
  });
}
