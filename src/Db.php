<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;

/**
 * Conexión a PostgreSQL. Los datos de acceso vienen de variables de entorno
 * (nunca escritos en el código):
 *   TUSALON_DB_HOST, TUSALON_DB_PORT, TUSALON_DB_NAME, TUSALON_DB_USER, TUSALON_DB_PASS
 */
final class Db
{
    public static function conectar(): PDO
    {
        $host = getenv('TUSALON_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TUSALON_DB_PORT') ?: '5432';
        $name = getenv('TUSALON_DB_NAME') ?: 'tusalon';
        $user = getenv('TUSALON_DB_USER') ?: 'tusalon';
        $pass = getenv('TUSALON_DB_PASS') ?: '';

        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET TIME ZONE 'America/Guayaquil'");
        return $pdo;
    }
}
