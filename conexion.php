<?php

function getDatabaseConnection() {
    static $mysqli = null;

    if ($mysqli === null) {
        $host = 'localhost';
        $user = 'root';
        $pass = '';
        $db   = 'liveauction';

        $mysqli = @new mysqli($host, $user, $pass, $db);

        if ($mysqli->connect_errno) {
            throw new RuntimeException('No se pudo conectar a la base de datos.');
        }
    }

    return $mysqli;
}
