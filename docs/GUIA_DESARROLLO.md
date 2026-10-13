# Guía para programar en TuSalón

Notas para quien cambia el código (Claude o un programador). Lo de uso diario está en el `README.md` y el avance en `BLOC_DE_NOTAS.md`.

## Pruebas
- Lógica: `bash tests/run_logica.sh` (borra y crea la base de prueba; Telegram simulado en el puerto 8098). Pantallas: `PGPASSWORD=prueba bash tests/run_web.sh` (opcional `DB=…` y `PUERTO=…`). Todo debe terminar en `TODO BIEN`, sin avisos de PHP.
- Las bases de prueba deben tener "prueba" en el nombre (se borran).
- Cada cambio de base va en una migración nueva en `db/migraciones/` (que se pueda correr muchas veces) **y** al final de `db/schema.sql`, antes del `COMMIT;` (las pruebas de pantallas solo cargan `schema.sql`).

## Panel Tukán Central (conector)
- El contrato son `PROTOCOLO.md` (v1) y `PROTOCOLO_V2.md` (v2) del Panel Tukán Central; el mapeo de TuSalón está en §10.2 y §10.3. Es igual en todos los programas Tukán: no lo cambies solo aquí. Entrada: `public/tukan_central.php` (no pasa por `index.php`).
- v1 (`sso` y `estado`, GET con `?t=`): `TukanCentral::validar()`. Los rechazos (`TukanCentralRechazo`) traen el `codigo` del protocolo (en el SSO va en la cabecera `X-Tukan-Codigo`). El SSO acepta `ir` (enlace directo): solo rutas que empiezan con `/?r=admin` (`TukanCentral::irValido()`); si no vale, abre el inicio del panel y lo anota.
- v2 (todo lo demás, POST con cuerpo JSON y el token en `X-Tukan-Token`): `TukanCentralV2::procesar()` valida en el orden de §3.1 (POST, JSON, ≤ 64 KiB, firma, `v:2`, tipo, instancia, vida ≤ 60 s y ≤ 30 s en `ejecutar`, tiempo, `bh` = sha256 del cuerpo exacto, nonce, correo, cuerpo) y responde `{"ok":false,"protocolo":2,"codigo",…}` con `TukanCentralError` (422 con `campo` si un dato está mal). Límite por IP: 120 pedidos y 30 rechazados por minuto (`tukan_central_pedidos.rechazados`).
- Estado del cliente (§4.2), una sola regla para `clientes.contadores` y `estado.negocios`: `prueba`→prueba; `activo`→`por_vencer` si `activo_hasta` ≤ hoy+7 (sin fecha → activo); `por_vencer` de TuSalón (gracia) y `prueba_vencida`→`vencido`; `cancelado`→`suspendido`. Lo calcula `TukanCentralV2::clasificar()`.
- Solicitudes: se arman al momento (no hay tabla): `registro_nuevo:{salón}`, `pago_automatico:{pago}` (Google Play), `seguimiento_prueba:{salón}:{etapa}:{prueba_hasta}`, `renovacion:{salón}:{etapa}:{activo_hasta}`, `dominio_gratis:{pago}`. Lo que el Central atiende va en `tukan_central_atenciones`. Alargar la prueba borra las atenciones de su seguimiento (reinicia los avisos). `salones.origen` (`registro`, `super`, `central`) lo pone `Altas::crearSalonConDueno(..., $origen)`.
- `ejecutar`: clave `operacion_id` en `tukan_central_operaciones` (fila `en_curso` fuera de la transacción; la operación en una transacción con `SELECT … FOR UPDATE` del salón; respuesta guardada sin contraseñas; mismo id con otro cuerpo → 409; repetido → la misma respuesta con `repetida: true`). Cada operación correcta deja `Panel Tukán Central · … (op xxxxxxxx)` en `registro_admin` con el super usuario del correo del token (`TukanCentral::buscarSuper()`).
- Las reglas son las del panel de Tukán: `PanelTukan::registrarPago()` (también la usa `admin_salon`), `Planes::suscribir(..., $pagadoEn)` (fecha del pago), `Planes::anularPago()`, `PanelTukan::cambiarEstado()/extenderPrueba()/cambiarPlan()/nuevaClaveDueno()/guardarNotas()`, `Planes::guardarPlan()/guardarAjustes()`, `Altas::crearSalonConDueno()`.
- Cuidado: `PDOException` es `RuntimeException`. En el conector siempre se relanza antes de convertir los `RuntimeException` del negocio en 422 (si no, un error de base saldría como "dato inválido" con el texto técnico).
- Enlace público de un salón: `/?r=reservar&s=<slug>` (no existe `/<slug>`).
- Pruebas del conector: `tests/prueba_central.php` y `tests/prueba_web_central.sh` (v1), `tests/prueba_central_v2.php` y `tests/prueba_web_central_v2.sh` (v2). Migraciones 004 (v1) y 005 (v2); `deploy/conectar_central.sh` corre las dos.
