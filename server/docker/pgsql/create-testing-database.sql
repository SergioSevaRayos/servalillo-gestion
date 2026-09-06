-- Se ejecuta una sola vez, la primera vez que se inicializa el volumen de Postgres
-- (Docker corre todo lo que haya en /docker-entrypoint-initdb.d/ al crear el data dir).
-- Crea la base de datos `testing` que usa la suite de Pest (ver phpunit.xml: DB_DATABASE=testing).
-- Sin esto, `php artisan test` falla en una máquina nueva con "database testing does not exist".
-- La crea el rol POSTGRES_USER (= DB_USERNAME del .env), que queda como owner con todos los permisos.
CREATE DATABASE testing;
