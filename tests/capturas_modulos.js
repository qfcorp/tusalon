// Capturas de pantalla del Anexo B (módulos) a 390 px (celular) y 1280 px (computadora):
// Mi plan, "No está en tu plan" y los interruptores de módulos del Panel Tukán.
// La página "No está en tu plan" responde 403 a propósito (ese aviso de la consola no cuenta).
// Lo corre tests/run_web.sh con CAPTURAS=<carpeta> (usa los datos que deja tests/prueba_web_modulos.sh).
// Necesita Playwright con Chromium ya instalado (PW_CHROMIUM o /opt/pw-browsers); nunca corre "playwright install".
let playwright;
try { playwright = require('playwright'); } catch { playwright = require('/opt/npm-tools/node_modules/playwright'); }
const fs = require('fs');
const path = require('path');
const { BASE, EMAIL_BASICA, CLAVE, ADMIN, ADMIN_CLAVE, SALON_BASICA, CARPETA } = process.env;
const ruta = process.env.PW_CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  fs.mkdirSync(CARPETA, { recursive: true });
  const nav = await playwright.chromium.launch({ executablePath: fs.existsSync(ruta) ? ruta : undefined });
  const fallas = [];
  for (const [nombre, ancho, alto] of [['celular', 390, 844], ['compu', 1280, 900]]) {
    const ctx = await nav.newContext({ viewport: { width: ancho, height: alto }, deviceScaleFactor: ancho < 500 ? 2 : 1 });
    const p = await ctx.newPage();
    const errores = [];
    p.on('pageerror', e => errores.push(e.message));
    p.on('console', m => { if (m.type() === 'error' && !/fonts\.g|status of 403/.test(m.text())) errores.push(m.text()); });
    // Dueño
    await p.goto(`${BASE}/?r=login`);
    await p.fill('#email', EMAIL_BASICA);
    await p.fill('#clave', CLAVE);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    for (const [pag, archivo] of [['r=completa', 'mi_plan'], ['r=gastos', 'no_en_plan']]) {
      await p.goto(`${BASE}/?${pag}`);
      const desborda = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      if (desborda) fallas.push(`${archivo} (${nombre}) se sale a lo ancho`);
      await p.screenshot({ path: path.join(CARPETA, `${archivo}_${nombre}.png`), fullPage: true });
    }
    // Panel Tukán
    await p.goto(`${BASE}/?r=salir`);
    await p.goto(`${BASE}/?r=admin_entrar`);
    await p.fill('#email', ADMIN);
    await p.fill('#clave', ADMIN_CLAVE);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    await p.goto(`${BASE}/?r=admin_salon&id=${SALON_BASICA}`);
    const sec = await p.$('#modulos');
    if (!sec) fallas.push('No aparece la sección de módulos del panel');
    else await sec.screenshot({ path: path.join(CARPETA, `panel_modulos_${nombre}.png`) });
    if (await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) fallas.push(`panel (${nombre}) se sale a lo ancho`);
    if (errores.length) fallas.push(`Errores de consola (${nombre}): ${errores.join(' | ')}`);
    await ctx.close();
  }
  await nav.close();
  if (fallas.length) { console.log('  FALLA ' + fallas.join('\n  FALLA ')); process.exit(1); }
  console.log(`  OK    Capturas en ${CARPETA}: mi_plan, no_en_plan y panel_modulos (celular y compu), sin errores ni desbordes`);
})().catch(e => { console.error(e); process.exit(1); });
