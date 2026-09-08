/// Reloj inyectable para poder testear la lógica que depende de la hora.
abstract class Clock {
  const Clock();

  DateTime now();
}

class SystemClock extends Clock {
  const SystemClock();

  @override
  DateTime now() => DateTime.now();
}

class FixedClock extends Clock {
  FixedClock(this.value);

  DateTime value;

  @override
  DateTime now() => value;
}
