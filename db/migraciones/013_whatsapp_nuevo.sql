-- Numero nuevo de WhatsApp de Tukan (6 nov 2026): el +593 99 640 8397 fue robado y suspendido; el nuevo es el +593 98 366 2181.
-- Solo cambia donde todavia esta el numero viejo (si Tukan ya puso otro, no se toca). Se puede correr muchas veces.
UPDATE ajustes SET valor = '593983662181' WHERE clave = 'whatsapp_ventas' AND valor IN ('593996408397', '+593996408397', '0996408397');
