#!/usr/bin/env node
/**
 * Recorrido de navegador del reporte de Chaguaní (2026-11-26, bitácora § 136):
 *
 *   1. Equipos y existencias: las tarjetas cuentan por estado y respetan la
 *      búsqueda («RG-EW300» → cuántos hay, cuántos disponibles, cuántos
 *      instalados); el paginador cuenta lo filtrado y nunca deja una página
 *      vacía; buscar sin guion («rgew300») también encuentra.
 *   2. Entregas y traspasos abre en el origen que tiene existencias, muestra
 *      los materiales, avisa dónde están cuando el origen no tiene, y
 *      «Descontar…» corrige un ingreso digitado de más (queda como «Ajuste»).
 *      Si la respuesta se pierde (se corta con Fetch.failRequest DESPUÉS de que
 *      el servidor grabó), la ventana trae el saldo real antes de dejar repetir.
 *   3. Movimientos muestra el ajuste como «Corrección de inventario».
 *   4. Instalaciones tiene buscador (sin tildes, por cédula, teléfono o nombre)
 *      y lo conserva en la URL.
 *
 * Sin dependencias: Node ≥ 22 maneja Edge o Chrome por el protocolo DevTools.
 * Se corre a mano contra un backend de PRUEBAS sembrado así (ver
 * docs/MANUAL_DESARROLLADOR.md, «Recorrido de navegador del reporte de
 * Chaguaní»): admin `admin_demo` con `*`; técnico `tecnico_demo` («Técnico
 * Demo», sin view_inventory); bodega CHAGUANI; 25 «RG-EW300 PRO» (12 en
 * bodega, 3 con el técnico, 9 instalados, 1 de baja), 35 ONU (30 en bodega,
 * 5 instaladas) y 2 ONU «sin sucursal»; 500 m de «Cable UTP Cat6» y 100
 * «Conector RJ45» en CHAGUANI, de los que 20 m pasaron al técnico; y 9 órdenes
 * (6 de prospectos, entre ellos «Javier Manuel Gutiérrez García», cédula
 * 1070123456, y «Pedro Núñez», tel. 3138889900).
 *
 * MODIFICA DATOS (descuenta 450 m). Se niega a correr contra cualquier host
 * que no sea 127.0.0.1 o localhost.
 *
 * Variables: BASE_URL, ADMIN_USER/ADMIN_PASS, BROWSER, OUT_DIR, WAIT_MS
 * (con `php -S`, WAIT_MS=30000).
 */
import { spawn } from 'node:child_process'
import { mkdtempSync, writeFileSync, mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const env = (k, d) => process.env[k] ?? d
const BASE = env('BASE_URL', 'http://127.0.0.1:5186').replace(/\/$/, '')
const host = new URL(BASE).hostname
if (!['127.0.0.1', 'localhost'].includes(host)) {
  console.error(`Me niego a modificar datos en ${host}: sólo 127.0.0.1 o localhost.`)
  process.exit(2)
}

const ADMIN = [env('ADMIN_USER', 'admin_demo'), env('ADMIN_PASS', 'Secret123!')]
const BROWSER = env('BROWSER', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
const OUT = env('OUT_DIR', mkdtempSync(join(tmpdir(), 'ispwatch-conteos-')))
mkdirSync(OUT, { recursive: true })
const WAIT_MS = Number(env('WAIT_MS', 10000))
const PORT = 9300 + Math.floor(Math.random() * 400)

// ── CDP mínimo (el mismo de material-por-cantidad.mjs) ───────────────────
const profile = mkdtempSync(join(tmpdir(), 'ispwatch-edge-'))
const browser = spawn(BROWSER, [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
  '--no-first-run', '--window-size=1280,900', 'about:blank',
], { stdio: 'ignore' })

const sleep = (ms) => new Promise(r => setTimeout(r, ms))
let ws, seq = 0
const pending = new Map()
// Eventos del navegador (p. ej. Fetch.requestPaused); null = se ignoran.
let onEvent = null

async function connect() {
  for (let i = 0; i < 50; i++) {
    try {
      const targets = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json()
      const target = targets.find(t => t.type === 'page')
      if (target) {
        ws = new WebSocket(target.webSocketDebuggerUrl)
        await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej })
        ws.onmessage = (ev) => {
          const msg = JSON.parse(ev.data)
          if (msg.id && pending.has(msg.id)) {
            const { res, rej } = pending.get(msg.id)
            pending.delete(msg.id)
            msg.error ? rej(new Error(msg.error.message)) : res(msg.result)
          } else if (msg.method && onEvent) {
            onEvent(msg)
          }
        }
        return
      }
    } catch { /* todavía arrancando */ }
    await sleep(200)
  }
  throw new Error('No se pudo conectar con el navegador.')
}

const send = (method, params = {}) => new Promise((res, rej) => {
  const id = ++seq
  pending.set(id, { res, rej })
  ws.send(JSON.stringify({ id, method, params }))
})

/** Evalúa una función en la página. `fn` se serializa: no puede cerrar sobre variables. */
async function page(fn, ...args) {
  const expression = `(${fn})(...${JSON.stringify(args)})`
  const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text)
  return r.result.value
}

async function waitFor(fn, args = [], what = 'condición', timeout = WAIT_MS) {
  const end = Date.now() + timeout
  let last
  while (Date.now() < end) {
    last = await page(fn, ...args).catch(e => e.message)
    if (last === true) return
    await sleep(150)
  }
  throw new Error(`Tiempo agotado esperando: ${what} (último: ${JSON.stringify(last)})`)
}

const waitText = (t, timeout) => waitFor((t) => document.body.innerText.includes(t), [t], `texto «${t}»`, timeout)

async function go(path) {
  await send('Page.navigate', { url: BASE + path })
  await waitFor(() => document.readyState === 'complete' && !!document.querySelector('#app')?.children.length, [], `cargar ${path}`)
  await sleep(600)
}

async function shot(name) {
  const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
  writeFileSync(join(OUT, `${name}.png`), Buffer.from(data, 'base64'))
}

const fill = (sel, value) => page((sel, value) => {
  const el = document.querySelector(sel)
  if (!el) throw new Error(`No existe ${sel}`)
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  el.dispatchEvent(new Event('change', { bubbles: true }))
  return true
}, sel, String(value))

const LIST = '.z-app-dropdown, .z-app-modal-dropdown'
async function choose(rootSel, optText, query = optText) {
  await page((sel) => {
    const b = document.querySelector(`${sel} button`)
    if (!b) throw new Error(`No hay desplegable ${sel}`)
    b.click()
    return true
  }, rootSel)
  await waitFor((l) => !!document.querySelector(l)?.querySelector('input'), [LIST], `lista de ${rootSel} abierta`)
  await page((l, q) => {
    const i = document.querySelector(l).querySelector('input')
    i.value = q
    i.dispatchEvent(new Event('input', { bubbles: true }))
    return true
  }, LIST, query)
  await waitFor((l, t) => {
    const items = [...document.querySelector(l).querySelectorAll('li[data-item-id]')]
    const li = items.find(x => x.innerText.includes(t))
    if (!li) return `sin «${t}» en: ${items.map(x => x.innerText.replace(/\s+/g, ' ')).join(' | ') || '(vacía)'}`
    li.click()
    return true
  }, [LIST, optText], `opción «${optText}» en ${rootSel}`)
  await waitFor((l) => !document.querySelector(l), [LIST], 'lista cerrada')
}

const clickTestId = (id) => page((id) => {
  const b = document.querySelector(`[data-testid="${id}"]`)
  if (!b) throw new Error(`No existe [data-testid=${id}]`)
  if (b.disabled) throw new Error(`[data-testid=${id}] está deshabilitado`)
  b.click()
  return true
}, id)

const clickText = (label, scopeSel = 'body') => page((label, scopeSel) => {
  const b = [...document.querySelector(scopeSel).querySelectorAll('button, a')]
    .filter(e => e.offsetParent !== null)
    .find(e => e.innerText.trim() === label)
  if (!b) throw new Error(`No hay botón «${label}»`)
  if (b.disabled) throw new Error(`El botón «${label}» está deshabilitado`)
  b.click()
  return true
}, label, scopeSel)

async function login([user, pass]) {
  await go('/')
  await page(() => { localStorage.clear(); sessionStorage.clear(); return true })
  await go('/')
  await fill('#email_tenant', user)
  await fill('#password', pass)
  await clickText('ENTRAR AL SISTEMA')
  await waitFor(() => location.pathname === '/dashboard', [], `entrar como ${user}`)
}

// ── Ayudas de esta pantalla ──────────────────────────────────────────────
async function expectCounts(expected, what) {
  await waitFor((exp) => {
    const got = Object.fromEntries(Object.keys(exp).map(k =>
      [k, document.querySelector(`[data-testid=status-count-${k}]`)?.innerText.trim()]))
    return Object.keys(exp).every(k => got[k] === String(exp[k])) || JSON.stringify(got)
  }, [expected], what)
}

const summary = () => page(() => document.querySelector('[data-testid=pagination-summary]')?.innerText.replace(/\s+/g, ' ').trim() ?? '(sin paginador)')
const rows = () => page(() => document.querySelectorAll('table tbody tr').length)

/** Pulsa la página `n` del paginador visible (el de escritorio a 1280 px). */
const goToPageN = (n) => page((n) => {
  const nav = [...document.querySelectorAll('nav[aria-label="Paginación"]')].find(x => x.offsetParent !== null)
  if (!nav) throw new Error('No hay paginador visible')
  const b = [...nav.querySelectorAll('button')].find(x => x.innerText.trim() === String(n))
  if (!b) throw new Error(`No hay página ${n} en: ${nav.innerText.replace(/\s+/g, ' ')}`)
  b.click()
  return true
}, n)

const steps = []
async function step(name, fn) {
  process.stdout.write(`• ${name} … `)
  await fn()
  steps.push(name)
  console.log('ok')
}
const assert = (cond, msg) => { if (!cond) throw new Error(msg) }

// ── Recorrido ────────────────────────────────────────────────────────────
try {
  await connect()
  await send('Page.enable')
  await send('Runtime.enable')

  await step('Entrar como administrador de inventario', () => login(ADMIN))

  await step('Equipos y existencias: las tarjetas cuentan por estado', async () => {
    await go('/inventory')
    await expectCounts({ all: 62, stock: 44, assigned: 3, installed: 14, retired: 1 }, 'conteos del inventario completo')
    await waitText('47 disponibles (bodega + técnicos)')
    assert((await summary()).includes('de 62 equipos'), `Paginador: ${await summary()}`)
  })

  await step('Página 7 del inventario completo y luego buscar «RG-EW300»: vuelve a la 1, no queda en blanco', async () => {
    await goToPageN(7)
    await waitFor(() => document.querySelector('[data-testid=pagination-summary]')?.innerText.includes('61'), [], 'página 7')
    await fill('[data-testid=inventory-search]', 'RG-EW300')
    await expectCounts({ all: 25, stock: 12, assigned: 3, installed: 9, retired: 1 }, 'conteos de RG-EW300')
    await waitText('15 disponibles (bodega + técnicos)')
    await waitText('COINCIDEN')
    const s = await summary()
    assert(s.includes('Mostrando 1 - 10 de 25 equipos (filtrados de 62)'), `Paginador: ${s}`)
    assert(await rows() === 10, `Filas: ${await rows()}`)
    await shot('1-buscar-rg-ew300')
  })

  await step('El paginador sólo ofrece las 3 páginas de lo encontrado; la última tiene 5', async () => {
    const pages = await page(() => [...[...document.querySelectorAll('nav[aria-label="Paginación"]')]
      .find(x => x.offsetParent !== null).querySelectorAll('button')].map(b => b.innerText.trim()).filter(t => /^\d+$/.test(t)))
    assert(JSON.stringify(pages) === '["1","2","3"]', `Páginas ofrecidas: ${pages}`)
    await goToPageN(3)
    await waitFor(() => document.querySelector('[data-testid=pagination-summary]')?.innerText.includes('21 - 25'), [], 'página 3')
    assert(await rows() === 5, `Filas en la página 3: ${await rows()}`)
  })

  await step('Tarjeta «En bodega»: filtra, vuelve a la página 1 y las demás cifras siguen', async () => {
    await clickTestId('status-card-stock')
    await waitFor(() => document.querySelector('[data-testid=pagination-summary]')?.innerText.includes('de 12 equipos'), [], 'filtro en bodega')
    await expectCounts({ all: 25, stock: 12, installed: 9 }, 'conteos intactos')
    const estados = await page(() => [...document.querySelectorAll('table tbody tr')].map(r => r.innerText.includes('En bodega')))
    assert(estados.length === 10 && estados.every(Boolean), 'Todas las filas deben estar en bodega')
    await clickTestId('status-card-stock')   // pulsarla otra vez la apaga
    await waitFor(() => document.querySelector('[data-testid=pagination-summary]')?.innerText.includes('de 25 equipos'), [], 'sin filtro de estado')
  })

  await step('Sin guion también encuentra («rgew300»), y lo que no existe ofrece limpiar', async () => {
    await fill('[data-testid=inventory-search]', 'rgew300')
    await expectCounts({ all: 25 }, 'búsqueda compacta')
    await fill('[data-testid=inventory-search]', 'zzz-no-existe')
    await waitFor(() => !!document.querySelector('[data-testid=inventory-empty]')?.innerText.includes('Ningún equipo coincide'), [], 'vacío con filtros')
    await clickText('Limpiar filtros')
    await expectCounts({ all: 62 }, 'filtros limpios')
  })

  await step('Entregas y traspasos abre en CHAGUANI (donde hay existencias) y lista los materiales', async () => {
    await go('/inventory/transfers')
    await waitFor(() => document.querySelector('[data-testid=transfer-from] button')?.innerText.includes('CHAGUANI'), [], 'origen CHAGUANI')
    await waitFor(() => document.querySelectorAll('[data-testid=transfer-material]').length === 2, [], 'dos materiales')
    await waitText('Disponible: 480 metro')
    await shot('2-entregas-materiales')
  })

  await step('Descontar 450 m de cable: valida el tope y queda como corrección', async () => {
    await page(() => {
      const row = [...document.querySelectorAll('[data-testid=transfer-material]')].find(r => r.innerText.includes('Cable UTP'))
      row.querySelector('[data-testid=material-writeoff]').click()
      return true
    })
    await waitFor(() => !!document.querySelector('[data-testid=writeoff-modal]'), [], 'ventana de descontar')
    await fill('[data-testid=writeoff-quantity]', '600')
    await waitText('Disponible en CHAGUANI: 480 metro.')
    assert(await page(() => document.querySelector('[data-testid=writeoff-confirm]').disabled), 'Con 600 no se puede confirmar')
    await fill('[data-testid=writeoff-quantity]', '450')
    await waitText('Quedarán 30 metro en CHAGUANI.')
    assert(await page(() => document.querySelector('[data-testid=writeoff-confirm]').disabled), 'Sin motivo no se puede confirmar')
    await clickTestId('writeoff-reason-ingreso_de_mas')
    await fill('[data-testid=writeoff-notes]', 'se digitó 500 en vez de 50')
    await shot('3-descontar')
    await clickTestId('writeoff-confirm')
    await waitFor(() => !document.querySelector('[data-testid=writeoff-modal]'), [], 'ventana cerrada')
    await waitText('Disponible: 30 metro')
    await waitText('Corrección registrada')
  })

  await step('Respuesta perdida: el servidor descuenta pero la red se corta; el modal trae el saldo real', async () => {
    // La petición LLEGA al servidor (se graba) y se corta la RESPUESTA: el caso
    // en que repetir a ciegas descontaría dos veces.
    let cortadas = 0
    const btn = () => page(() => { const b = document.querySelector('[data-testid=writeoff-confirm]'); return b ? `${b.innerText.trim()} disabled=${b.disabled}` : '(sin botón)' })
    onEvent = (msg) => {
      if (msg.method === 'Fetch.requestPaused') {
        cortadas++
        send('Fetch.failRequest', { requestId: msg.params.requestId, errorReason: 'ConnectionReset' })
          .catch((e) => console.error(`\n  Fetch.failRequest falló: ${e.message}`))
      }
    }
    await send('Fetch.enable', { patterns: [{ urlPattern: '*write-offs*', requestStage: 'Response' }] })
    try {
      await page(() => {
        const row = [...document.querySelectorAll('[data-testid=transfer-material]')].find(r => r.innerText.includes('Cable UTP'))
        row.querySelector('[data-testid=material-writeoff]').click()
        return true
      })
      await waitFor(() => !!document.querySelector('[data-testid=writeoff-modal]'), [], 'ventana de descontar')
      // Se abre justo después del descuento anterior, mientras el saldo todavía
      // se recarga: no puede heredar su «Descontando…» (pasó: quedaba bloqueado).
      const alAbrir = await btn()
      assert(!alAbrir.startsWith('Descontando'), `La ventana nueva nació ocupada: ${alAbrir}`)
      await waitText('disponible 30')
      await clickTestId('writeoff-reason-danado')
      await fill('[data-testid=writeoff-quantity]', '5')
      await waitFor(() => document.querySelector('[data-testid=writeoff-confirm]')?.disabled === false, [], 'botón habilitado')
      await clickTestId('writeoff-confirm')
      await waitFor(() => !!document.querySelector('[data-testid=writeoff-error]')?.innerText.includes('No hubo respuesta'), [], 'aviso sin respuesta')
      // El servidor sí lo grabó (30 → 25) y la ventana ya lo dice: nadie repite a ciegas.
      await waitFor(() => document.querySelector('[data-testid=writeoff-modal]')?.innerText.includes('disponible 25'), [], 'disponible actualizado a 25')
      assert(cortadas === 1, `Respuestas cortadas: ${cortadas}`)
      await shot('3b-respuesta-perdida')
    } finally {
      await send('Fetch.disable')
      onEvent = null
    }
    await clickText('Cancelar', '[data-testid=writeoff-modal]')
    await waitText('Disponible: 25 metro')
  })

  await step('Origen «Bodega (sin sucursal)»: dice dónde están los materiales y lleva allá', async () => {
    await choose('[data-testid=transfer-from]', 'Bodega (sin sucursal)', 'sin sucursal')
    await waitFor(() => !!document.querySelector('[data-testid=materials-elsewhere]'), [], 'aviso de materiales en otro origen')
    const aviso = await page(() => document.querySelector('[data-testid=materials-elsewhere]').innerText.replace(/\s+/g, ' '))
    assert(aviso.includes('CHAGUANI · 2 materiales') && aviso.includes('Técnico Demo · 1 material'), `Aviso: ${aviso}`)
    await shot('4-sin-sucursal-aviso')
    await clickTestId('materials-at-branch:1')
    await waitFor(() => document.querySelectorAll('[data-testid=transfer-material]').length === 2, [], 'de vuelta en CHAGUANI')
  })

  await step('Movimientos: el ajuste se lee «CHAGUANI → Corrección de inventario»', async () => {
    await go('/inventory/movements')
    await page(() => {
      const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => o.value === 'ajuste'))
      s.value = 'ajuste'
      s.dispatchEvent(new Event('change', { bubbles: true }))
      return true
    })
    await waitText('Corrección de inventario')
    await waitText('Corrección: se ingresó de más · se digitó 500 en vez de 50')
    await shot('5-movimientos-ajuste')
  })

  await step('Instalaciones: buscador sin tildes, por cédula y por teléfono', async () => {
    await go('/installations')
    await waitFor(() => !!document.querySelector('[data-testid=installations-count]')?.innerText.includes('9 órdenes'), [], '9 órdenes')

    await fill('[data-testid=installations-search]', 'gutierrez')
    await waitFor(() => document.querySelector('[data-testid=installations-count]')?.innerText.includes('1 de 9'), [], 'por apellido sin tilde')
    assert((await page(() => document.querySelector('table tbody').innerText)).includes('Gutiérrez'), 'Debe salir Gutiérrez')

    await fill('[data-testid=installations-search]', '1070123456')
    await waitFor(() => document.querySelector('[data-testid=installations-count]')?.innerText.includes('1 de 9'), [], 'por cédula')

    await fill('[data-testid=installations-search]', '313 888 9900')
    await waitFor(() => document.querySelector('[data-testid=installations-count]')?.innerText.includes('1 de 9'), [], 'por teléfono con espacios')
    assert((await page(() => document.querySelector('table tbody').innerText)).includes('Núñez'), 'Debe salir Pedro Núñez')

    await fill('[data-testid=installations-search]', 'nada que ver')
    await waitFor(() => !!document.querySelector('[data-testid=installations-no-match]'), [], 'sin coincidencias')
    await clickText('Limpiar búsqueda')
    await waitFor(() => document.querySelector('[data-testid=installations-count]')?.innerText.includes('9 órdenes'), [], 'búsqueda limpia')
  })

  await step('La búsqueda de Instalaciones vive en la URL (?q=) y sobrevive a recargar', async () => {
    await fill('[data-testid=installations-search]', 'castro')
    await waitFor(() => location.search.includes('q=castro'), [], 'q en la URL')
    await go('/installations?q=castro')
    await waitFor(() => document.querySelector('[data-testid=installations-search]')?.value === 'castro', [], 'buscador precargado')
    await waitFor(() => document.querySelector('[data-testid=installations-count]')?.innerText.includes('1 de 9'), [], 'filtrado al cargar')
    await shot('6-instalaciones-buscar')
  })

  await step('Celular (390 px): tarjetas y lista de equipos sin desbordar', async () => {
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true })
    await go('/inventory')
    await expectCounts({ all: 62 }, 'conteos en celular')
    const overflow = await page(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    assert(overflow <= 1, `Desborde horizontal de ${overflow}px`)
    await shot('7-celular')
    await send('Emulation.clearDeviceMetricsOverride')
  })

  console.log(`\n${steps.length} pasos OK. Capturas en ${OUT}`)
} catch (e) {
  console.error(`\nFALLÓ: ${e.message}`)
  await shot('error').catch(() => {})
  console.error(`Captura del fallo en ${OUT}`)
  process.exitCode = 1
} finally {
  try { ws?.close() } catch { /* nada */ }
  browser.kill()
}
