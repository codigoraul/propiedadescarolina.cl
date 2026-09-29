<?php
/**
 * Handler del formulario de contacto (sitio estático en cPanel/BenzaHosting).
 * Envía el mensaje por correo con mail() de PHP y responde JSON.
 */
header('Content-Type: application/json; charset=utf-8');
$DESTINO = 'contacto@propiedadescarolina.cl';
$COPIA   = 'carolinarobles_propiedades@yahoo.es';
$DOMINIO = 'propiedadescarolina.cl';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit; }
if (!empty($_POST['_gotcha'])) { echo json_encode(['ok' => true]); exit; } // honeypot anti-spam

$f = fn($k) => trim(strip_tags($_POST[$k] ?? ''));
$nombre = mb_substr($f('nombre'), 0, 120);
$email  = filter_var($f('email'), FILTER_VALIDATE_EMAIL);
$tel    = mb_substr($f('telefono'), 0, 40);
$motivo = mb_substr($f('motivo'), 0, 80);
$prop   = mb_substr($f('propiedad'), 0, 300);
$msg    = mb_substr($f('mensaje'), 0, 3000);

if (!$nombre || !$email || !$msg) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'campos']); exit; }

$asunto = $prop ? "Consulta por propiedad: $prop" : ('Contacto web' . ($motivo ? " – $motivo" : ''));
$cuerpo = "Nombre: $nombre\nEmail: $email\nTeléfono: $tel\n" . ($motivo ? "Motivo: $motivo\n" : '') . ($prop ? "Propiedad: $prop\n" : '') . "\nMensaje:\n$msg\n\n— Enviado desde $DOMINIO";
$headers = "From: Sitio web <no-reply@$DOMINIO>\r\nReply-To: $nombre <$email>\r\nCc: $COPIA\r\nContent-Type: text/plain; charset=UTF-8\r\n";

$ok = @mail($DESTINO, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $headers);
echo json_encode(['ok' => (bool) $ok]);
