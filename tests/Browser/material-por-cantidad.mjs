#!/usr/bin/env node
/**
 * Recorrido de navegador: alta de un material por cantidad, sólo por pantalla.
 *
 *   Stock → Agregar material («Fibra drop», metro, 2 decimales, precio por metro)
 *   → Registrar entrada de 100 m en una bodega → Stock muestra 100 metro
 *   → «Agregar equipo con serial» ya no ofrece el material
 *   → entrega de 30 m al técnico → el técnico consume 12,5 m en la orden y 7,5 m
 *     en el ticket → Stock muestra 80 metro y Movimientos enlaza orden y ticket.
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

async function waitFor(fn, args = [], what = 'condición', timeout = 10000) {
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

/** Fila de material de orden/ticket: elige el material, escribe la cantidad y pulsa Agregar. */
async function consume(selectHint, qty) {
  await pick(selectHint, MODELO)
  await fill('[data-testid=material-qty]', qty)
  await page(() => {
    const row = document.querySelector('[data-testid=material-qty]').closest('div').parentElement
    const b = [...row.querySelectorAll('button')].find(b => b.innerText.trim() === 'Agregar')
    if (!b || b.disabled) throw new Error('Agregar no disponible: ' + (document.querySelector('[data-testid=material-qty-error]')?.innerText ?? ''))
    b.click()
    return true
  })
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

  await step('Abrir «Agregar material» desde el menú: viene en «Por cantidad»', async () => {
    await go('/inventory/stocks?nuevo=material')
    await waitFor(() => !!document.querySelector('[data-testid=stock-decimals]'), [], 'modal abierto en «Por cantidad»')
    const state = await page(() => ({
      porCantidad: document.querySelector('label input[type=radio]:checked')?.closest('label').innerText.includes('Por cantidad'),
      unit: document.querySelector("input[placeholder^='unidad, metro']").value,
      decimals: document.querySelector('[data-testid=stock-decimals]').value,
      priceLabel: document.querySelector('[data-testid=stock-price]').closest('div').querySelector('label').innerText,
      help: document.querySelector('[data-testid=price-help]').innerText,
    }))
    if (!state.porCantidad || state.unit !== 'metro' || state.decimals !== '2') throw new Error(JSON.stringify(state))
    if (!state.priceLabel.includes('Precio por metro')) throw new Error(`Etiqueta de precio: ${state.priceLabel}`)
    if (!state.help.includes('no un total')) throw new Error(`Ayuda del precio: ${state.help}`)
  })

  await step(`Crear «${MODELO}» sin serial, metro, 2 decimales, 1.500 por metro`, async () => {
    await fill("input[placeholder^='Ej: TP-Link']", 'GENÉRICO')
    await fill("input[placeholder^='Ej: hAP']", MODELO)
    await fill('[data-testid=stock-price]', 1500)
    await shot('01-alta-material')
    await clickText('Crear')
    await waitText(MODELO)
    await waitFor((m) => [...document.querySelectorAll('[data-testid=stock-available],[data-testid=stock-available-card]')]
      .filter(e => e.offsetParent).some(e => e.closest('tr, .rounded-xl').innerText.includes(m) && new RegExp('(^|[^0-9,])0 metro').test(e.innerText)),
    [MODELO], 'existencia 0 metro')
    await shot('02-stock-sin-existencia')
  })

  await step('«Registrar entrada» abre Entrada de material con el producto elegido', async () => {
    await page((m) => {
      // La fila MÁS PEQUEÑA que nombra el modelo: los contenedores de la lista
      // también lo contienen, y su primer enlace es el de otro producto.
      const card = [...document.querySelectorAll('tr, .rounded-xl')]
        .filter(e => e.offsetParent && e.innerText.includes(m) && e.querySelector('a'))
        .sort((a, b) => a.innerText.length - b.innerText.length)[0]
      const link = [...card.querySelectorAll('a')].find(a => a.innerText.trim() === 'Registrar entrada')
      link.click()
      return true
    }, MODELO)
    await waitFor(() => location.pathname === '/inventory/transfers', [], 'ir a Entregas y traspasos')
    await waitFor((m) => {
      const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => o.text.includes('— Material —')))
      return !!s && s.options[s.selectedIndex]?.text.includes(m)
    }, [MODELO], 'material preseleccionado')
  })

  await step('Registrar 100 m de existencia en la bodega', async () => {
    await fill('input[placeholder=Cantidad]', 100)
    await pick('— Entra a —', BODEGA)
    await shot('03-entrada-100m')
    await clickText('Registrar entrada')
    await sleep(1200)
  })

  await step('Stock muestra 100 metro', async () => {
    await go('/inventory/stocks')
    await waitFor((m) => [...document.querySelectorAll('[data-testid=stock-available],[data-testid=stock-available-card]')]
      .filter(e => e.offsetParent).some(e => e.closest('tr, .rounded-xl').innerText.includes(m) && new RegExp('(^|[^0-9,])100 metro').test(e.innerText)),
    [MODELO], 'existencia 100 metro')
    await shot('04-stock-100m')
  })

  await step('«Agregar equipo con serial» no ofrece el material y explica dónde va', async () => {
    await go('/inventory/create')
    const r = await page((m) => ({
      ofrecido: [...document.querySelector('select').options].some(o => o.text.includes(m)),
      hint: !!document.querySelector('[data-testid=material-hint]'),
    }), MODELO)
    if (r.ofrecido || !r.hint) throw new Error(JSON.stringify(r))
    await shot('05-equipo-serial-sin-material')
  })

  await step('Entregar 30 m de la bodega al técnico', async () => {
    await go('/inventory/transfers')
    await pick(0, BODEGA)
    await sleep(800)
    await pick(1, 'Técnico')
    await page((m) => {
      const row = [...document.querySelectorAll('input[type=number]')].map(i => i.closest('div').parentElement)
        .find(r => r.innerText.includes(m))
      const i = row.querySelector('input[type=number]')
      i.value = '30'
      i.dispatchEvent(new Event('input', { bubbles: true }))
      return true
    }, MODELO)
    await clickText('Registrar entrega')
    await waitText('Disponible: 70 metro')
  })

  await step('Técnico: consumir 12,5 m en la orden', async () => {
    await login(TECH)
    await go(`/installations/${INSTALLATION_ID}`)
    await waitFor((m) => [...document.querySelectorAll('select option')].some(o => o.text.includes(m) && o.text.includes('30 metro en Mis equipos')), [MODELO], 'material del técnico en la orden')
    await consume('+ Agregar material por cantidad', 12.5)
    await waitFor(() => document.querySelector('[data-testid=cable-summary]')?.innerText.includes('12,5 m'), [], 'cable calculado 12,5 m')
    await shot('06-orden-12_5m')
  })

  await step('Técnico: consumir 7,5 m en el ticket', async () => {
    await go(`/support/${TICKET_ID}`)
    await waitFor((m) => [...document.querySelectorAll('select option')].some(o => o.text.includes(m) && o.text.includes('17,5 metro')), [MODELO], 'saldo 17,5 en el ticket')
    await consume('+ Agregar material…', 7.5)
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
