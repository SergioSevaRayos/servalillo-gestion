import 'package:flutter_test/flutter_test.dart';
import 'package:servalillo_tracker/services/pause_window.dart';

DateTime at(int h, int m) => DateTime(2026, 9, 8, h, m);

void main() {
  group('minutesOfHhmm', () {
    test('parsea con y sin cero a la izquierda', () {
      expect(minutesOfHhmm('05:00'), 300);
      expect(minutesOfHhmm('5:00'), 300);
      expect(minutesOfHhmm('22:30'), 22 * 60 + 30);
      expect(minutesOfHhmm('00:00'), 0);
    });
  });

  group('isWithinPause', () {
    test('ventana normal [08:00, 12:00): inicio incluido, fin excluido', () {
      expect(isWithinPause(at(8, 0), '08:00', '12:00'), isTrue);
      expect(isWithinPause(at(10, 30), '08:00', '12:00'), isTrue);
      expect(isWithinPause(at(12, 0), '08:00', '12:00'), isFalse);
      expect(isWithinPause(at(7, 59), '08:00', '12:00'), isFalse);
    });

    test('ventana que cruza medianoche 22:00-05:00', () {
      expect(isWithinPause(at(23, 0), '22:00', '05:00'), isTrue);
      expect(isWithinPause(at(2, 0), '22:00', '05:00'), isTrue);
      expect(isWithinPause(at(22, 0), '22:00', '05:00'), isTrue);
      expect(isWithinPause(at(5, 0), '22:00', '05:00'), isFalse);
      expect(isWithinPause(at(12, 0), '22:00', '05:00'), isFalse);
    });

    test('start == end: nunca hay pausa', () {
      expect(isWithinPause(at(3, 0), '00:00', '00:00'), isFalse);
      expect(isWithinPause(at(0, 0), '06:00', '06:00'), isFalse);
    });
  });
}
