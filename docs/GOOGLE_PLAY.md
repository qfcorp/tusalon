# TuSalón en Google Play — cobro de planes dentro de la app

La app de Android de TuSalón es el mismo sistema web metido en una app (la app se arma en el repositorio privado **qfcorp/tukan-apps**).
Cuando el dueño abre TuSalón **desde la app de Google Play**, el sistema:

- Muestra el pago del plan **con Google Play** (Google exige esto para cobrar dentro de la app).
- **No** muestra precios web ni WhatsApp para pagar por fuera (Google puede bajar la app si los ve).
- Verifica cada compra directamente con Google antes de activar el plan, y revisa las renovaciones cada hora.

En la web normal (navegador) todo sigue igual: precio web y pago por WhatsApp/transferencia.

## 0. Está APAGADA hasta que decidas publicar

Todo lo de Google Play está en el código y se sigue actualizando con cada cambio, pero queda **apagado**.
Cada vez que corres el instalador, al llegar a la configuración pregunta:

```
Activar la app de Google Play esta vez? escribe si o no [no]:
```

- **no** (o Enter, o no contestas en 2 minutos): queda apagada. TuSalón funciona igual que siempre.
- **si**: se enciende (aparece `/.well-known/assetlinks.json` y el pago con Google dentro de la app). Una vez encendida, el instalador la mantiene encendida si solo das Enter.

Sin que pregunte (por ejemplo el día de publicar), copia y pega en el servidor:

```bash
GOOGLE_PLAY=si bash /var/www/tusalon/deploy/instalar.sh
```

Al final debe decir `11) App de Google Play: ACTIVADA - assetlinks.json: 200 (debe ser 200)`.

Para ver cómo está: **Panel Tukán → Planes → Google Play** ("activada" o "apagada").

---

## 1. Productos (suscripciones) que hay que crear en Play Console

Ruta: Play Console → TuSalón → **Monetizar → Productos → Suscripciones → Crear suscripción**.

Usa **exactamente** estos códigos (el sistema los reconoce por el nombre):

| ID del producto (copiar tal cual) | Plan | Plan base / período | Precio web | Precio sugerido Google |
|---|---|---|---|---|
| `tusalon_basica_mensual` | Básica | 1 mes | $20 | **$23,99** |
| `tusalon_basica_semestral` | Básica | 6 meses | $100 | **$117,99** |
| `tusalon_basica_anual` | Básica | 1 año | $200 | **$235,99** |
| `tusalon_completa_mensual` | Completa | 1 mes | $45 | **$52,99** |
| `tusalon_completa_semestral` | Completa | 6 meses | $225 | **$264,99** |
| `tusalon_completa_anual` | Completa | 1 año | $450 | **$529,99** |

Precios del 28 oct 2026: Básica $20 (la base + 3 módulos a elegir) y Completa $45 (todo, con el Asistente IA).
En Google Play solo se venden los dos planes: los módulos que el salón elige en la Básica los enciende Tukán desde el panel.
Si ya habías creado las suscripciones con los precios de antes ($25/$35), cámbiales el precio en Play Console (los códigos no cambian).

- El precio sugerido cubre el **15 %** que Google cobra en las suscripciones: precio web ÷ 0,85, redondeado a ,99.
- Si cambias los precios web en el Panel Tukán, la tabla con los nuevos precios sugeridos aparece en **Panel Tukán → Planes → Google Play**.
- En cada suscripción crea **un plan base** con renovación automática y el período de la tabla. **NO actives periodo de prueba ni ofertas de días sin cobro** en Google: en TuSalón todo se pide demo (la demo la prepara Tukán y el salón llega a la app ya con su cuenta).
- Activa cada plan base (botón **Activar**).

---

## 2. Cuenta de servicio (para que el servidor pueda preguntarle a Google)

### 2.1 Crearla en Google Cloud
1. Entra a https://console.cloud.google.com/ con la misma cuenta de Play Console.
2. Arriba, crea un proyecto llamado `tukan-play`.
3. Menú → **APIs y servicios → Biblioteca** → busca **Google Play Android Developer API** → **Habilitar**.
4. Menú → **IAM y administración → Cuentas de servicio → Crear cuenta de servicio**.
   - Nombre: `tusalon-servidor` → **Crear y continuar** → **Listo** (sin roles).
5. Entra a la cuenta creada → pestaña **Claves → Agregar clave → Crear clave nueva → JSON**.
   Se descarga un archivo `.json`. **Es como una contraseña: no lo subas a GitHub ni lo mandes por WhatsApp.**

### 2.2 Darle permiso en Play Console
1. Play Console → **Usuarios y permisos → Invitar usuarios nuevos**.
2. Correo: el de la cuenta de servicio (termina en `@tukan-play.iam.gserviceaccount.com`).
3. Permisos de la app TuSalón: marca **Ver información financiera** y **Administrar pedidos y suscripciones**.
4. **Invitar usuario**.

(Google puede tardar hasta 24 horas en dejar que la cuenta nueva consulte compras.)

### 2.3 Poner el archivo en el servidor
Desde tu computadora, sube el archivo JSON al servidor (cambia el nombre del archivo si es distinto):

```bash
scp tukan-play-xxxx.json usuario@IP_DEL_SERVIDOR:/tmp/google-play.json
```

Luego, **en el servidor**, copia y pega (sin cambiar nada):

```bash
sudo mv /tmp/google-play.json /var/www/tusalon/config/google-play.json
sudo chown root:www-data /var/www/tusalon/config/google-play.json
sudo chmod 640 /var/www/tusalon/config/google-play.json
grep -q '^GOOGLE_PLAY_CUENTA=' /var/www/tusalon/config/.env || echo 'GOOGLE_PLAY_CUENTA=/var/www/tusalon/config/google-play.json' | sudo tee -a /var/www/tusalon/config/.env
grep -q '^GOOGLE_PLAY_PAQUETE=' /var/www/tusalon/config/.env || echo 'GOOGLE_PLAY_PAQUETE=com.tukansoftware.tusalon' | sudo tee -a /var/www/tusalon/config/.env
```

El cambio funciona al instante (no hay que reiniciar nada). Para revisar: **Panel Tukán → Planes → Google Play** debe decir "Cuenta de servicio: configurada".

---

## 3. Enlace entre la app y la página (assetlinks)

Para que la app se abra **sin barra de navegador**, la página publica:

```
https://tusalon.tukanec.com/.well-known/assetlinks.json
```

con las huellas de las llaves que firman la app (variable `TWA_SHA256` del `config/.env`).
El instalador ya pone la huella de **nuestra llave de subida**. Después de subir la app por primera vez,
Google firma la app con **su propia llave**, y hay que agregar también esa huella:

1. Play Console → TuSalón → **Prueba y lanzamiento → Configuración → Integridad de la app → Firma de apps**.
2. Copia el **Certificado SHA-256** de la "Clave de firma de apps".
3. En el servidor, copia y pega esto **cambiando solo** `HUELLA_DE_GOOGLE` por lo que copiaste:

```bash
sudo sed -i 's|^TWA_SHA256=\(.*\)$|TWA_SHA256=\1,HUELLA_DE_GOOGLE|' /var/www/tusalon/config/.env
curl -s https://tusalon.tukanec.com/.well-known/assetlinks.json
```

El último comando debe mostrar las **dos** huellas.

---

## 4. Actualizar el servidor
Igual que siempre (el instalador conserva Telegram y Google Play):

```bash
curl -fsSL https://raw.githubusercontent.com/qfcorp/tusalon/main/deploy/instalar.sh -o ~/instalar_tusalon.sh && bash ~/instalar_tusalon.sh
```

---

## 5. Cómo se prueba sin cobrar de verdad
- Play Console → **Configuración → Pruebas de licencia** → agrega tu correo de Gmail y el de los probadores.
- Esas cuentas pagan con "tarjeta de prueba" y Google **no cobra**. Las suscripciones de prueba se renuevan cada pocos minutos, sirve para ver que el plan se alarga solo.

## 6. Qué pasa cuando alguien paga
1. El dueño toca el plan en la app → Google cobra.
2. La app avisa al servidor (`?r=play_compra`) con el código de la compra.
3. El servidor le pregunta a Google si esa compra es real y está activa. Si sí: activa el plan, anota el pago en **Suscripciones** con método `google_play` y confirma la compra a Google (si no se confirma en 3 días, Google la devuelve).
4. Cada hora, `bin/tareas.php` revisa las compras: si Google renovó, alarga el plan; si se canceló, deja que termine en su fecha.

---

## Planes de la tienda y módulos (Anexo B)
- Google Play solo cobra **planes**: `tusalon_basica_*` = la base (agenda, clientes, servicios, equipo, cuentas, cobros y caja, hasta 3 personas) **+ 3 módulos que el salón elige** y `tusalon_completa_*` = **todos los módulos, con el Asistente IA**. Qué trae cada plan sale de `planes.modulos` y `planes.modulos_elegir` (Panel Tukán → Planes y precios); si cambias lo que trae un plan, cambia también la descripción de la suscripción en Play Console.
- Los 3 módulos de la Básica se eligen hablando con Tukán (o con «Pedir cotización» en Mi plan desde la web) y Tukán los enciende desde el panel; dentro de la app no se cobran aparte. Cada módulo más y el Asistente IA en la Básica tampoco se venden en la app: se arreglan con Tukán fuera de ella.
- Los **módulos sueltos no se venden dentro de la app**: en la app, Mi plan muestra los módulos sin precios y sin «Pedir este módulo» (Google exige cobrar con Google lo que se vende dentro de la app). Si un salón que paga por Google Play quiere un módulo extra, lo arregla con Tukán fuera de la app y Tukán lo enciende desde el panel.
- Cuando Google confirma o renueva una suscripción, el salón queda con ese plan; sus módulos extra o quitados no cambian (los maneja Tukán).
- Sin periodo de prueba en las suscripciones (ver arriba). La app abre la misma página «Solicitar demo» para quien todavía no tiene cuenta.

---

## 7. Cuenta para el revisor de Google (28 oct 2026)

Google entra a la app para revisarla. Se le da una cuenta en un **salón de ejemplo aparte** («Salón de Ejemplo Tukán», plan Completoa,
datos inventados, sin pagos de suscripción). No toca ningún salón real.

En el servidor, copia y pega (la contraseña sale **una sola vez**: cópiala en Play Console → Contenido de la app → Acceso a la app):

```bash
sudo -u www-data php /var/www/tusalon/deploy/demo_google.php crear
```

- Usuario: `revisor.google@tukanec.com` · Contraseña: `Revisor-` y 6 números (al azar; no se guarda en ningún lado).
- Si se pierde la contraseña, se corre otra vez la misma línea: borra el salón de ejemplo anterior, lo vuelve a crear con las fechas de hoy y da una contraseña nueva.
- Cuando Google apruebe (o si ya no se quiere la cuenta): `sudo -u www-data php /var/www/tusalon/deploy/demo_google.php borrar`
- Va con `sudo -u www-data` porque así puede leer `config/.env` y la carpeta de la papelera (el borrado es el mismo de la papelera, sin esperar los 30 días).
- Código: `deploy/demo_google.php` y `src/DemoGoogle.php`. Pruebas: `tests/prueba_google_play.php` y `tests/prueba_web_google_play.sh`.

## 8. Lo que revisan las pruebas para Google Play
- **Modo app** (`?app=play`, cookie `canal=play`): sin precios del software, sin WhatsApp de ventas, sin «Pedir cotización», «Contratar» ni
  «Total al mes»; en Mi plan solo el pago con Google Play con los 6 códigos. El WhatsApp de soporte de la Ayuda y los WhatsApp del negocio
  a sus clientes sí se ven (no son pagos del software).
- **assetlinks.json** con el paquete `com.tukansoftware.tusalon` y la huella de la llave de subida (y la de Google cuando se agrega); 404 con Google Play apagado.
- **Privacidad** pública (`/?r=privacidad`) con cómo borrar la cuenta («Borrar mi cuenta de TuSalón») y los servicios que procesan datos (también Anthropic para la IA).
- **Planes de $20 y $45** → precios de Google $23,99 / $117,99 / $235,99 y $52,99 / $264,99 / $529,99.

## 9. Ficha y guía para publicar
Están en el repositorio **qfcorp/tukan-apps**: `tienda/tusalon/TEXTOS_GOOGLE_PLAY.md`, `tienda/tusalon/play.json`, imágenes en `tienda/tusalon/` y la guía
`tienda/tusalon/PUBLICAR_TUSALON_CON_CLAUDE.md`. Las capturas de la ficha se sacan con `CAPTURAS=<carpeta> PGPASSWORD=prueba bash tests/capturas_tienda.sh`
(el salón de ejemplo del manual, en un TuSalón local; nunca del servidor real).
