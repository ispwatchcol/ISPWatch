#!/usr/bin/env node
/**
 * Recorrido de navegador: alta de un material por cantidad, sólo por pantalla.
 *
 *   Ingresar al inventario → crear «Fibra drop» ahí mismo (metro, 2 decimales,
 *   precio por metro) y registrar 100 m en una bodega → Stock muestra 100 metro
 *   → en «Equipos con serial» el material no se ofrece; se crea un modelo y se
 *     ingresan dos unidades de una vez → /inventory/create redirige al ingreso
 *   → entrega de 30 m al técnico → el técnico consume 12,5 m en la orden y 7,5 m
 *     en el ticket → Stock muestra 80 metro y Movimientos enlaza orden y ticket.
 *
 * Los desplegables con buscador (SearchableSelect) se manejan con `choose()`:
 * abrir, escribir en el buscador y pulsar la opción, como el usuario.
 *
 * Sin dependencias: Node ≥ 22 (WebSocket y fetch globales) maneja Edge o Chrome
 * por el protocolo DevTools. La suite de PHPUnit no tiene navegador; esto se
 * corre a mano contra un backend de PRUEBAS (ver docs/MANUAL_DESARROLLADOR.md,
 * «Recorrido de navegador del material por cantidad»).
 *
 * CREA DATOS (bodega, producto, entrada, entrega, consumos). Por eso se niega a
 * correr contra cualquier host que no sea 127.0.0.1 o localhost.
 *
 * Variables:
 *   BASE_URL         http://127.0.0.1:5186
 *   ADMIN_USER/PASS  usuario que administra inventario (view_inventory)
 *   TECH_USER/PASS   técnico sin view_inventory, asignado a la orden y al ticket
 *   TECH_NAME        su nombre tal como sale en el desplegable (por defecto «Técnico Demo»)
 *   INSTALLATION_ID  orden de instalación pendiente del técnico
 *   TICKET_ID        ticket abierto del técnico
 *   BROWSER          ruta de msedge/chrome (por defecto la de Edge en Windows)
 *   OUT_DIR          carpeta de capturas (por defecto el temporal del sistema)
 */
import { spawn } from 'node:child_process'
import { mkdtempSync, writeFileSync, mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const env = (k, d) => process.env[k] ?? d
const BASE = env('BASE_URL', 'http://127.0.0.1:5186').replace(/\/$/, '')
const host = new URL(BASE).hostname
if (!['127.0.0.1', 'localhost'].includes(host)) {
  console.error(`Me niego a crear datos de prueba en ${host}: sólo 127.0.0.1 o localhost.`)
  process.exit(2)
}

const ADMIN = [env('ADMIN_USER', 'admin_demo'), env('ADMIN_PASS', 'Secret123!')]
const TECH = [env('TECH_USER', 'tecnico_demo'), env('TECH_PASS', 'Secret123!')]
const INSTALLATION_ID = env('INSTALLATION_ID', '2')
const TICKET_ID = env('TICKET_ID', '1')
const BROWSER = env('BROWSER', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
const OUT = env('OUT_DIR', mkdtempSync(join(tmpdir(), 'ispwatch-material-')))
mkdirSync(OUT, { recursive: true })

const suffix = Date.now().toString(36).slice(-5).toUpperCase()
const BODEGA = `Bodega E2E ${suffix}`
const MODELO = `Fibra drop ${suffix}`
const ONU = `ONU E2E ${suffix}`
const TECH_NAME = env('TECH_NAME', 'Técnico Demo')
// Espera máxima por paso. Con `php -S` (una petición a la vez) conviene subirla: WAIT_MS=30000.
const WAIT_MS = Number(env('WAIT_MS', 10000))
const PORT = 9300 + Math.floor(Math.random() * 400)

// ── CDP mínimo ───────────────────────────────────────────────────────────
const profile = mkdtempSync(join(tmpdir(), 'ispwatch-edge-'))
const browser = spawn(BROWSER, [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
  '--no-first-run', '--window-size=1280,900', 'about:blank',
], { stdio: 'ignore' })

const sleep = (ms) => new Promise(r => setTimeout(r, ms))
let ws, seq = 0
const pending = new Map()

async function connect() {
  for (let i = 0; i < 50; i++) {
    try {
      const targets = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json()
      const page = targets.find(t => t.type === 'page')
      if (page) {
        ws = new WebSocket(page.webSocketDebuggerUrl)
        await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej })
        ws.onmessage = (ev) => {
          const msg = JSON.parse(ev.data)
          if (msg.id && pending.has(msg.id)) {
            const { res, rej } = pending.get(msg.id)
            pending.delete(msg.id)
            msg.error ? rej(new Error(msg.error.message)) : res(msg.result)
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

const text = () => page(() => document.body.innerText)
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

// Acciones de formulario que Vue entiende (input/change, como el usuario).
const fill = (sel, value) => page((sel, value) => {
  const el = document.querySelector(sel)
  if (!el) throw new Error(`No existe ${sel}`)
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  el.dispatchEvent(new Event('change', { bubbles: true }))
  return true
}, sel, String(value))

/** Elige en el <select> número `n` (o el que contenga `hint`) la opción cuyo texto incluye `optText`. */
const pick = (selectMatcher, optText) => page((m, optText) => {
  const selects = [...document.querySelectorAll('select')]
  const s = typeof m === 'number' ? selects[m] : selects.find(x => [...x.options].some(o => o.text.includes(m)))
  if (!s) throw new Error(`No hay select ${m}`)
  const o = [...s.options].find(o => o.text.includes(optText))
  if (!o) throw new Error(`Sin opción «${optText}» en: ${[...s.options].map(o => o.text.trim()).join(' | ')}`)
  // Por índice y no por value: las opciones de material llevan un objeto
  // como valor (Vue), y su value en el DOM es «[object Object]» para todas.
  s.selectedIndex = o.index
  s.dispatchEvent(new Event('change', { bubbles: true }))
  return true
}, selectMatcher, optText)

/**
 * SearchableSelect: abre el de `rootSel` (su contenedor lleva el data-testid),
 * escribe `query` en el buscador y pulsa la opción cuyo texto incluye `optText`.
 * La lista va teletransportada a <body>, por eso se busca fuera del contenedor.
 */
const LIST = '.z-app-dropdown, .z-app-modal-dropdown'
async function choose(rootSel, optText, query = optText) {
  await page((sel) => {
    const b = document.querySelector(`${sel} button`)
    if (!b) throw new Error(`No hay desplegable ${sel}`)
    if (b.disabled) throw new Error(`El desplegable ${sel} está deshabilitado`)
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
  // La lista se llena cuando llega el catálogo (el servidor de pruebas atiende
  // de a una petición): se espera la opción en vez de fallar a la primera.
  await waitFor((l, t) => {
    const items = [...document.querySelector(l).querySelectorAll('li[data-item-id]')]
    const li = items.find(x => x.innerText.includes(t))
    if (!li) return `sin «${t}» en: ${items.map(x => x.innerText.replace(/\s+/g, ' ')).join(' | ') || '(vacía)'}`
    li.click()
    return true
  }, [LIST, optText], `opción «${optText}» en ${rootSel}`)
  await waitFor((l) => !document.querySelector(l), [LIST], 'lista cerrada')
}

/** Opciones que ofrece un SearchableSelect al buscar `query` (y lo deja cerrado). */
async function optionsFor(rootSel, query) {
  await page((sel) => { document.querySelector(`${sel} button`).click(); return true }, rootSel)
  await waitFor((l) => !!document.querySelector(l)?.querySelector('input'), [LIST], `lista de ${rootSel} abierta`)
  await page((l, q) => {
    const i = document.querySelector(l).querySelector('input')
    i.value = q
    i.dispatchEvent(new Event('input', { bubbles: true }))
    return true
  }, LIST, query)
  await sleep(200)
  const texts = await page((l) => [...document.querySelector(l).querySelectorAll('li[data-item-id]')].map(x => x.innerText.trim()), LIST)
  await page(() => { document.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); return true })
  return texts
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

const steps = []
async function step(name, fn) {
  process.stdout.write(`• ${name} … `)
  await fn()
  steps.push(name)
  console.log('ok')
}

// ── Recorrido ────────────────────────────────────────────────────────────
try {
  await connect()
  await send('Page.enable')
  await send('Runtime.enable')

  await step('Entrar como administrador de inventario', () => login(ADMIN))

  await step(`Crear la bodega «${BODEGA}» en Sucursales`, async () => {
    await go('/inventory/branches')
    await clickText('Nueva Sucursal')
    await fill("input[placeholder^='Nombre de la sucursal']", BODEGA)
    await fill("input[placeholder^='Dirección de la sucursal']", 'Calle E2E')
    await fill("input[placeholder^='Número o teléfono']", '1')
    await clickText('Crear')
    await waitText(BODEGA)
  })

  await step('El menú de Inventarios tiene cinco entradas y un solo «ingresar»', async () => {
    // Sólo el menú lateral: la barra del Catálogo (otro <nav>) sí enlaza Proveedores y Sucursales.
    const items = await page(() => [...document.querySelectorAll('aside a')]
      .map(a => a.getAttribute('href')).filter(h => h && h.startsWith('/inventory')))
    for (const href of ['/inventory', '/inventory/ingreso', '/inventory/transfers', '/inventory/movements', '/inventory/stocks']) {
      if (!items.includes(href)) throw new Error(`Falta ${href} en el menú: ${items.join(', ')}`)
    }
    for (const viejo of ['/inventory/create', '/inventory/providers', '/inventory/branches']) {
      if (items.includes(viejo)) throw new Error(`El menú todavía enlaza ${viejo}`)
    }
  })

  await step('Ingresar al inventario: crear «Fibra drop» ahí mismo, metro y 2 decimales', async () => {
    await go('/inventory/ingreso?tipo=material')
    await waitFor(() => !!document.querySelector('[data-testid=intake-material]'), [], 'modo material')
    await clickTestId('intake-new-material')
    await fill("input[placeholder^='Fibra drop']", MODELO)
    await fill("input[placeholder='GENÉRICO']", 'GENÉRICO')
    const state = await page(() => {
      const box = document.querySelector("input[placeholder^='Fibra drop']").closest('.rounded-xl')
      const selects = box.querySelectorAll('select')
      return { unit: selects[0].value, decimals: selects[1].value }
    })
    if (state.unit !== 'metro' || state.decimals !== '2') throw new Error(JSON.stringify(state))
    await fill("input[placeholder='0'][step='any']", 1500)
    await shot('01-ingreso-crear-material')
    await clickText('Crear y usar en el ingreso')
    await waitFor((m) => document.querySelector('[data-testid=intake-material-line] button')?.innerText.includes(m),
      [MODELO], 'material nuevo elegido en la línea')
  })

  await step('Registrar 100 m en la bodega (y el destino se elige con buscador)', async () => {
    await fill('[data-testid=intake-material-qty]', 100)
    await choose('[data-testid=intake-material-dest]', BODEGA, 'e2e')
    await shot('02-ingreso-100m')
    await clickTestId('intake-material-submit')
    await waitFor((m) => [...document.querySelectorAll('li')].some(l => l.innerText.includes(m) && l.innerText.includes('100 metro')),
      [MODELO], '«Últimos ingresos» con 100 metro')
  })

  await step('Stock muestra 100 metro, con la barra del catálogo', async () => {
    await go('/inventory/stocks')
    await waitFor(() => !!document.querySelector('[data-testid=inventory-catalog-nav]'), [], 'barra Stock · Proveedores · Sucursales')
    await waitFor((m) => [...document.querySelectorAll('[data-testid=stock-available],[data-testid=stock-available-card]')]
      .filter(e => e.offsetParent).some(e => e.closest('tr, .rounded-xl').innerText.includes(m) && new RegExp('(^|[^0-9,])100 metro').test(e.innerText)),
    [MODELO], 'existencia 100 metro')
    await shot('03-stock-100m')
  })

  await step('/inventory/create lleva al ingreso de equipos, que no ofrece el material', async () => {
    await go('/inventory/create')
    await waitFor(() => location.pathname === '/inventory/ingreso' && new URLSearchParams(location.search).get('tipo') === 'equipo',
      [], 'redirección al ingreso de equipos')
    await waitFor(() => !!document.querySelector('[data-testid=intake-devices]'), [], 'modo equipos')
    const ofrecidas = await optionsFor('[data-testid=intake-device-model]', MODELO)
    if (ofrecidas.some(t => t.includes(MODELO))) throw new Error(`El material aparece como modelo con serial: ${ofrecidas.join(' | ')}`)
  })

  await step(`Crear el modelo «${ONU}» y ingresar dos unidades de una vez`, async () => {
    await clickTestId('intake-new-model')
    await fill("input[placeholder^='Huawei']", 'HUAWEI')
    await fill("input[placeholder^='HG8145V5']", ONU)
    await clickText('Crear y usar')
    await waitFor((m) => document.querySelector('[data-testid=intake-device-model] button')?.innerText.includes(m), [ONU], 'modelo elegido')
    await choose('[data-testid=intake-device-dest]', BODEGA, 'e2e')
    // Lector de código de barras: Enter en el serial salta a la MAC, y en la MAC abre la fila siguiente.
    const typeRow = (i, serial, mac) => page((i, serial, mac) => {
      const row = document.querySelectorAll('[data-testid=intake-device-row]')[i]
      const [s, m] = row.querySelectorAll('input')
      s.value = serial; s.dispatchEvent(new Event('input', { bubbles: true }))
      m.value = mac; m.dispatchEvent(new Event('input', { bubbles: true }))
      m.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
      return true
    }, i, serial, mac)
    await typeRow(0, `SN-${suffix}-1`, 'aabbcc000001')
    await waitFor(() => document.querySelectorAll('[data-testid=intake-device-row]').length === 2, [], 'Enter abre la fila 2')
    await typeRow(1, `SN-${suffix}-2`, 'aabbcc000002')
    await shot('04-ingreso-dos-onu')
    await clickTestId('intake-device-submit')
    await waitFor((s) => [...document.querySelectorAll('li')].filter(l => l.innerText.includes(`S/N SN-${s}-`)).length === 2,
      [suffix], 'las dos ONU en «Últimos ingresos»')
  })

  await step('Entregar 30 m de la bodega al técnico (origen y destino con buscador)', async () => {
    await go('/inventory/transfers')
    await choose('[data-testid=transfer-from]', BODEGA, 'e2e')
    await waitText('Disponible: 100 metro')
    await choose('[data-testid=transfer-to]', TECH_NAME, TECH_NAME.split(' ')[0].toLowerCase())
    await page((m) => {
      const row = [...document.querySelectorAll('input[type=number]')].map(i => i.closest('div').parentElement)
        .find(r => r.innerText.includes(m))
      const i = row.querySelector('input[type=number]')
      i.value = '30'
      i.dispatchEvent(new Event('input', { bubbles: true }))
      return true
    }, MODELO)
    await shot('05-entrega-30m')
    await clickTestId('transfer-submit')
    await waitText('Disponible: 70 metro')
  })

  await step('Técnico: consumir 12,5 m en la orden', async () => {
    await login(TECH)
    await go(`/installations/${INSTALLATION_ID}`)
    await waitFor(() => !!document.querySelector('[data-testid=material-search]'), [], 'buscador de material en la orden')
    await clickTestId('material-search')
    await waitFor(() => !!document.querySelector('[data-testid=search-picker-input]'), [], 'ventana de material')
    await fill('[data-testid=search-picker-input]', MODELO)
    await page((m) => {
      const opt = [...document.querySelectorAll('[data-testid=search-picker-option] button')].find(b => b.innerText.includes(m))
      if (!opt) throw new Error('Sin opción de material en la ventana')
      if (!opt.innerText.includes('30 metro')) throw new Error(`Saldo inesperado: ${opt.innerText}`)
      opt.click()
      return true
    }, MODELO)
    await fill('[data-testid=material-qty]', 12.5)
    await page(() => {
      const b = [...document.querySelector('[data-testid=material-picked]').querySelectorAll('button')].find(b => b.innerText.trim() === 'Agregar')
      if (!b || b.disabled) throw new Error('Agregar no disponible: ' + (document.querySelector('[data-testid=material-qty-error]')?.innerText ?? ''))
      b.click()
      return true
    })
    await waitFor(() => document.querySelector('[data-testid=cable-summary]')?.innerText.includes('12,5 m'), [], 'cable calculado 12,5 m')
    await shot('06-orden-12_5m')
  })

  await step('Técnico: consumir 7,5 m en el ticket (material con buscador)', async () => {
    await go(`/support/${TICKET_ID}`)
    await waitFor(() => !!document.querySelector('[data-testid=material-search]'), [], 'desplegable de material en el ticket')
    await choose('[data-testid=material-search]', '17,5 metro', MODELO)
    await fill('[data-testid=material-qty]', 7.5)
    await page(() => {
      const row = document.querySelector('[data-testid=material-qty]').closest('div').parentElement
      const b = [...row.querySelectorAll('button')].find(b => b.innerText.trim() === 'Agregar')
      if (!b || b.disabled) throw new Error('Agregar no disponible: ' + (document.querySelector('[data-testid=material-qty-error]')?.innerText ?? ''))
      b.click()
      return true
    })
    await waitFor(() => [...document.querySelectorAll('li')].some(l => l.innerText.includes('Material usado') && l.innerText.includes('7,5 metro')), [], 'línea 7,5 metro')
    await shot('07-ticket-7_5m')
  })

  await step('Administrador: Stock 80 metro y Movimientos con orden y ticket', async () => {
    await login(ADMIN)
    await go('/inventory/stocks')
    await waitFor((m) => [...document.querySelectorAll('[data-testid=stock-available],[data-testid=stock-available-card]')]
      .filter(e => e.offsetParent).some(e => e.closest('tr, .rounded-xl').innerText.includes(m) && new RegExp('(^|[^0-9,])80 metro').test(e.innerText)),
    [MODELO], 'existencia 80 metro')
    await go('/inventory/movements')
    await waitText(`Orden #${INSTALLATION_ID}`)
    await waitText(`Ticket #${TICKET_ID}`)
    await shot('08-movimientos')
  })

  console.log(`\nOK: ${steps.length} pasos. Capturas en ${OUT}`)
} catch (e) {
  await shot('error').catch(() => {})
  console.log('FALLÓ')
  console.error(e.message)
  console.error(`Capturas en ${OUT}`)
  process.exitCode = 1
} finally {
  try { ws?.close() } catch { /* nada */ }
  browser.kill()
}
