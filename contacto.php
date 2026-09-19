<?php
// contacto.php — recibe el formulario de la web y lo envía a hola@westmeteo.com
// Requiere que exista la cuenta de correo del dominio en Hostinger.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

$nombre  = trim($_POST['nombre']  ?? '');
$email   = trim($_POST['email']   ?? '');
$mensaje = trim($_POST['mensaje'] ?? '');

// validación básica
if ($email === '' || $mensaje === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit('Datos incompletos o correo no válido');
}

// evita inyección de cabeceras
$email  = str_replace(["\r", "\n"], '', $email);
$nombre = str_replace(["\r", "\n"], '', $nombre);

$para      = 'hola@westmeteo.com';
$asunto    = 'Contacto web @WESTMETEO' . ($nombre ? " · $nombre" : '');
$cuerpo    = "Nombre: $nombre\nCorreo: $email\n\n$mensaje\n";
$cabeceras = "From: web@westmeteo.com\r\n"
           . "Reply-To: $email\r\n"
           . "Content-Type: text/plain; charset=UTF-8\r\n";

if (mail($para, $asunto, $cuerpo, $cabeceras)) {
    http_response_code(200);
    echo 'ok';
} else {
    http_response_code(500);
    echo 'error';
}
