# Avisos por Telegram — cómo activarlos

TuSalón usa **un solo bot de Telegram** para todos los salones. Cada dueño o peluquero conecta su propio Telegram desde el sistema y ahí le llegan las solicitudes de cita con botones **Aceptar** y **Rechazar**.

## 1. Crear el bot (una sola vez, lo hace Dimitry)
1. En Telegram, busca **@BotFather** y ábrelo.
2. Escribe `/newbot`.
3. Nombre del bot: `TuSalón Avisos`
4. Usuario del bot (debe terminar en "bot"): por ejemplo `TuSalonAvisosBot`
5. BotFather te da un **token** parecido a `1234567890:ABCdef...`. Guárdalo; es como una contraseña.

## 2. Ponerlo en el servidor
En el archivo `config/.env` del servidor (nunca en GitHub):

```
TELEGRAM_BOT_TOKEN=el_token_que_te_dio_BotFather
TELEGRAM_BOT_USERNAME=TuSalonAvisosBot
TELEGRAM_WEBHOOK_SECRET=una_frase_secreta_larga_sin_espacios
```

## 3. Decirle a Telegram a dónde avisar
Ejecuta en el servidor (cambia TOKEN y SECRETO por los tuyos):

```bash
curl "https://api.telegram.org/botTOKEN/setWebhook?url=https://tusalon.qfradioec.com/?r=telegram&secret_token=SECRETO"
```

Debe responder `"ok":true`.

## 4. Cada usuario conecta su Telegram
En TuSalón: **Solicitudes → Avisos por Telegram → Conectar mi Telegram → Abrir Telegram → Iniciar**.

## 5. Los clientes también pueden conectar su Telegram
En **Mi cuenta** (del portal de reservas) el cliente toca **Conectar mi Telegram**. Desde ahí le llega:
- La confirmación de su cita con el **valor a cancelar**
- Un recordatorio el día anterior con botones **Confirmo que voy** y **Cancelar cita**
- El saludo de cumpleaños y la invitación a calificar el servicio

El dueño recibe aviso cuando el cliente confirma o cancela. El reporte del mes le llega el día 1.

## Seguridad
- El sistema solo acepta mensajes de Telegram que traen la frase secreta.
- El enlace para conectar sirve una sola vez.
- Un Telegram solo puede aceptar citas de su propio salón y según quién acepta (lo programa el dueño en "Horario y reservas").
- Si Telegram no responde, la reserva se guarda igual y el aviso queda en la campana del sistema.
- Un cliente solo puede confirmar o cancelar sus propias citas desde el Telegram que conectó.
