# Avisos por Telegram — cómo activarlos

TuSalón manda los avisos por el bot **genérico de Tukán Software**: **un solo bot para todos los programas Tukán** (decisión de Dimitry, 12 nov 2026). Cada dueño o peluquero conecta su propio Telegram desde el sistema y ahí le llegan las solicitudes de cita con botones **Aceptar** y **Rechazar**.

Como Telegram solo permite **un webhook por bot**, el webhook del bot «Tukán Software» está en el **Panel Tukán Central** (`https://panel.tukanec.com/bot_tukan.php`), que reenvía cada actualización al programa que le toca (TuSalón usa el prefijo **`ts`**). El bot se crea **una sola vez** para todos los programas: ver `docs/BOT_TELEGRAM_TUKAN.md` en el repo `tukan-central` (pasos completos).

## 1. El bot «Tukán Software» (una sola vez, lo hace Dimitry; si ya existe, saltar al paso 2)
1. En Telegram, busca **@BotFather** y escribe `/newbot`.
2. Nombre: `Tukán Software`. Usuario (debe terminar en «bot»): por ejemplo `TukanSoftwareBot`.
3. Guarda el **token** que te da BotFather (es como una contraseña) y configura el Panel Tukán Central como dice `tukan-central/docs/BOT_TELEGRAM_TUKAN.md`.

## 2. Ponerlo en el servidor de TuSalón
En `config/.env` del servidor (nunca en GitHub):

```
TELEGRAM_BOT_TOKEN=el_token_del_bot_Tukan_Software
TELEGRAM_BOT_USERNAME=TukanSoftwareBot
TELEGRAM_WEBHOOK_SECRET=una_frase_secreta_larga_sin_espacios
TELEGRAM_PREFIJO=ts
```

- `TELEGRAM_BOT_TOKEN` y `TELEGRAM_BOT_USERNAME` son los **del bot genérico** (los mismos en todos los programas).
- `TELEGRAM_WEBHOOK_SECRET` es de TuSalón: **la misma frase** se pone en el Panel Tukán Central (`BOT_TUKAN_TS_SECRETO`). Ya no se llama a `setWebhook` desde TuSalón.
- `TELEGRAM_PREFIJO=ts` le dice a TuSalón que use el bot genérico: el enlace de conexión lleva `?start=ts_código` y los botones `ts:ac:15`. **Si no se pone, TuSalón sigue con su bot propio como antes** (el que se creó con `/newbot` para TuSalón, con su propio `setWebhook` a `https://tusalon.tukanec.com/?r=telegram&secret_token=SECRETO`). Las dos formas se aceptan al leer lo que llega.

## 3. Decirle al Panel Tukán Central dónde está TuSalón
En `config/.env` del Panel Tukán Central:

```
BOT_TUKAN_TS_URL=https://tusalon.tukanec.com/?r=telegram
BOT_TUKAN_TS_SECRETO=la_misma_frase_de_TELEGRAM_WEBHOOK_SECRET
```

No hace falta tocar Telegram: el webhook del bot ya apunta al Panel.

## 4. Cada usuario conecta su Telegram
En TuSalón: **Solicitudes → Avisos por Telegram → Conectar mi Telegram → Abrir Telegram → Iniciar**.

> **Al cambiar de un bot propio al bot genérico, cada persona debe volver a conectarse una vez**: los chats guardados eran del bot anterior y el bot nuevo no puede escribirles hasta que lo inicien. (El bot propio viejo se puede apagar con `deleteWebhook`.)

## 5. Los clientes también pueden conectar su Telegram
En **Mi cuenta** (del portal de reservas) el cliente toca **Conectar mi Telegram**. Desde ahí le llega:
- La confirmación de su cita con el **valor a cancelar**
- Un recordatorio el día anterior con botones **Confirmo que voy** y **Cancelar cita**
- El saludo de cumpleaños y la invitación a calificar el servicio

El dueño recibe aviso cuando el cliente confirma o cancela. El reporte del mes le llega el día 1.

## Seguridad
- TuSalón solo acepta mensajes que traen su frase secreta (`TELEGRAM_WEBHOOK_SECRET`); con el bot genérico esa frase solo la conocen el Panel Tukán Central y TuSalón. El Panel, a su vez, solo acepta lo que trae el secreto del bot.
- El enlace para conectar sirve una sola vez.
- Un Telegram solo puede aceptar citas de su propio salón y según quién acepta (lo programa el dueño en «Horario y reservas»).
- Si Telegram no responde, la reserva se guarda igual y el aviso queda en la campana del sistema.
- Un cliente solo puede confirmar o cancelar sus propias citas desde el Telegram que conectó.

## Pruebas
`bash tests/run_logica.sh` (sección 13 y 13b: bot propio y bot genérico, con Telegram simulado).
