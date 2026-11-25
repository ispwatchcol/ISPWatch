#!/usr/bin/env node
/**
 * Recorrido de navegador: tickets simplificados (bitácora § 133).
 *
 *   Cola con pestañas, contadores y lo urgente arriba → búsqueda por teléfono →
 *   detalle formal con botón principal y «Resolver y cerrar» (recorre el camino,
 *   registra diagnóstico y medición final, cierra) → alta desde la ficha del
 *   cliente (llega elegido, avisa del ticket abierto, asunto rápido, prioridad,
 *   técnico → nace Asignado) → Configuración: flujo simple → detalle simple
 *   («Iniciar trabajo», una sola casilla, ficha con saldo) → cerrar diciendo sólo
 *   qué se hizo → celular sin desbordar → técnico: «Mis tickets» y «Proponer cierre».
 *
 * Sin dependencias: Node ≥ 22 maneja Edge o Chrome por el protocolo DevTools.
 * Se corre a mano contra un backend de PRUEBAS sembrado con estos datos (ver
 * docs/MANUAL_DESARROLLADOR.md, «Recorrido de navegador»): ISP en flujo formal;
 * admin_demo (permisos *) y tecnico_demo (ver, intervenir, mover estado,
 * diagnosticar, notas, historial; SIN ticket_close); clientes Ana Ruiz
 * (3104567890, «Vereda La Esperanza km 3»), Luis Pardo (cortado, factura vencida)
 * y Marta Gómez; tickets: #1 radicado urgente de Marta, #2 radicado de Luis,
 * #3 asignado a tecnico_demo (Ana), #4 pendiente de material, #5 servicio
 * restablecido, #6 cerrado; asuntos «Sin internet», «Internet lento»…
 *
 * CREA Y CIERRA TICKETS y cambia el flujo del ISP a «simple». Por eso se niega
 * a correr contra cualquier host que no sea 127.0.0.1 o localhost.
 *
 * Variables: BASE_URL, ADMIN_USER/PASS, TECH_USER/PASS, RADICADO (1),
 * ASIGNADO (3), ANA (id del cliente Ana), BROWSER, OUT_DIR, WAIT_MS (30000 con php -S).
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
const BROWSER = env('BROWSER', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
const OUT = env('OUT_DIR', mkdtempSync(join(tmpdir(), 'ispwatch-tickets-')))
mkdirSync(OUT, { recursive: true })

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

// ── Recorrido: tickets simplificados (bitácora § 133) ───────────────────
const RADICADO = env('RADICADO', '1')
const ASIGNADO = env('ASIGNADO', '3')
const ANA = env('ANA', '4')

async function viewport(w, h) {
  await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 600 })
}

/** Marca el contenedor del campo cuyo <label> empieza por `labelText`. */
const markByLabel = (scopeSel, labelText, mark) => page((scopeSel, labelText, mark) => {
  const scopes = [...document.querySelectorAll(scopeSel)]
  const scope = scopes[scopes.length - 1] || document
  const lab = [...scope.querySelectorAll('label, p')].find(l => l.innerText.trim().replace(/\s*\*$/, '').startsWith(labelText))
  if (!lab) throw new Error(`sin label «${labelText}»`)
  let root = lab.parentElement
  while (root && !root.querySelector('button')) root = root.parentElement
  root.setAttribute('data-e2e', mark)
  return true
}, scopeSel, labelText, mark)

async function chooseFirst(rootSel) {
  await page((sel) => { document.querySelector(`${sel} button`).click(); return true }, rootSel)
  await waitFor((l) => document.querySelector(l)?.querySelectorAll('li[data-item-id]').length > 0, [LIST], `opciones de ${rootSel}`)
  const elegido = await page((l) => { const li = [...document.querySelector(l).querySelectorAll('li[data-item-id]')].find(x => !/Sin definir|Sin asignar|Ninguno/.test(x.innerText)); const t = li.innerText.trim(); li.click(); return t }, LIST)
  await waitFor((l) => !document.querySelector(l), [LIST], 'lista cerrada')
  await sleep(450) // P-99: un cambio por tick
  return elegido
}

const filasDelTablero = () => page(() => [...document.querySelectorAll('[data-testid^="ticket-row-"]')].map(r => r.dataset.testid.replace('ticket-row-', '')))
const tabCount = (key) => page((k) => document.querySelector(`[data-testid="tab-${k}"]`)?.innerText.replace(/\s+/g, ' ').trim(), key)
const textOf = (sel) => page((sel) => document.querySelector(sel)?.innerText.replace(/\s+/g, ' ').trim() ?? null, sel)
const assert = (cond, msg) => { if (!cond) throw new Error(msg) }
const MODAL = '.z-app-modal'

try {
  await connect()
  await send('Page.enable')
  await send('Runtime.enable')
  await viewport(1366, 900)

  await step('Entrar como administrador', () => login(ADMIN))

  await step('La cola abre en «Abiertos», con contadores y lo urgente arriba', async () => {
    await go('/support')
    await waitFor(() => document.querySelectorAll('[data-testid^="ticket-row-"]').length > 0, [], 'filas del tablero')
    const abiertos = await tabCount('open'), cerrados = await tabCount('closed'), sinAsignar = await tabCount('unassigned')
    assert(abiertos.endsWith('5'), `Abiertos: ${abiertos}`)
    assert(cerrados.endsWith('1'), `Cerrados: ${cerrados}`)
    assert(sinAsignar.endsWith('2'), `Sin asignar: ${sinAsignar}`)
    const filas = await filasDelTablero()
    assert(filas.length === 5, `filas: ${filas}`)
    assert(filas[0] === RADICADO, `primero debía ir el urgente #${RADICADO}: ${filas}`)
    await shot('01-cola-abiertos')
  })

  await step('Buscar por teléfono del cliente', async () => {
    await fill("input[placeholder^='Buscar: #número']", '3104567890')
    await waitFor(() => document.querySelectorAll('[data-testid^="ticket-row-"]').length === 2, [], 'dos tickets de Ana')
    await shot('02-busqueda-telefono')
    await fill("input[placeholder^='Buscar: #número']", '')
    await waitFor(() => document.querySelectorAll('[data-testid^="ticket-row-"]').length === 5, [], 'vuelven los 5')
  })

  await step('Pestaña «Para cerrar»', async () => {
    await clickTestId('tab-to_close')
    await waitFor(() => document.querySelectorAll('[data-testid^="ticket-row-"]').length === 1, [], 'uno para cerrar')
    await clickTestId('tab-open')
    await waitFor(() => document.querySelectorAll('[data-testid^="ticket-row-"]').length === 5, [], 'de vuelta a abiertos')
  })

  await step('Clic en la fila abre el detalle (formal): paso principal + «Resolver y cerrar»', async () => {
    await page((id) => { document.querySelector(`[data-testid="ticket-row-${id}"]`).click(); return true }, RADICADO)
    await waitFor((id) => location.pathname === `/support/${id}`, [RADICADO], 'detalle')
    await waitFor(() => !!document.querySelector('[data-testid="primary-action"]'), [], 'botón principal')
    const principal = await textOf('[data-testid="primary-action"]')
    assert(principal.includes('Pasar a'), `principal: ${principal}`)
    assert(await textOf('[data-testid="resolve-and-close"]'), 'falta «Resolver y cerrar»')
    assert(await textOf('[data-testid="check-prueba_final"]'), 'falta la casilla de medición final')
        await waitFor(() => !!document.querySelector('[data-testid="ticket-customer-card"]')?.innerText.includes('Calle 3'), [], 'ficha del cliente')
    await shot('03-detalle-formal')
  })

  await step('Resolver y cerrar en una ventana (formal: camino + diagnóstico + medición)', async () => {
    await clickTestId('resolve-and-close')
    await waitFor((m) => !!document.querySelector(m)?.innerText.includes('Medición final'), [MODAL], 'ventana de resolver')
    for (const [label, mark] of [['Causa confirmada', 'cc'], ['Acción realizada', 'ac'], ['Resultado del cierre', 're']]) {
      await markByLabel(MODAL, label, mark)
      await chooseFirst(`[data-e2e="${mark}"]`)
    }
    await fill(`${MODAL} input[placeholder^='Potencia óptica']`, 'Potencia óptica')
    await fill(`${MODAL} input[placeholder='-18.5']`, '-19.2')
    await fill(`${MODAL} input[placeholder^='dBm']`, 'dBm')
    await shot('04-resolver-formal')
    await clickText('Cerrar ticket', MODAL)
    await waitFor(() => document.querySelector('[data-testid="ticket-status-badge"]')?.innerText.includes('Cerrado'), [], 'ticket cerrado', 45000)
    await shot('05-cerrado-formal')
  })

  await step('Nuevo ticket desde la ficha del cliente: llega con el cliente y avisa del abierto', async () => {
    await go(`/support/create?customer_id=${ANA}`)
    await waitFor(() => !!document.querySelector('[data-testid="ticket-customer-card"]')?.innerText.includes('Vereda La Esperanza'), [], 'cliente preelegido')
    await waitFor(() => !!document.querySelector('[data-testid="open-tickets-warning"]'), [], 'aviso de tickets abiertos')
    await waitFor(() => !!document.querySelector('[data-testid="frequent-subjects"]'), [], 'asuntos frecuentes')
    await clickText('Internet lento', '[data-testid="frequent-subjects"]')
    await clickText('Alta', '[data-testid="priority-buttons"]')
    await markByLabel('form', 'Técnico asignado', 'tec')
    await choose('[data-e2e="tec"]', 'Técnico Demo', 'Técnico')
    await shot('06-alta-rapida')
    await clickTestId('create-ticket-submit')
    await waitFor(() => /^\/support\/\d+$/.test(location.pathname), [], 'ticket creado', 30000)
    await waitFor(() => document.querySelector('[data-testid="ticket-status-badge"]')?.innerText.includes('Asignado'), [], 'nace Asignado', 30000)
    await shot('07-creado-asignado')
  })

  await step('Configuración → Sistema: pasar al flujo simple', async () => {
    await go('/settings')
    await clickText('Sistema').catch(async () => {
      await page(() => { const b = [...document.querySelectorAll('button')].find(x => x.innerText.includes('Sistema')); b.click(); return true })
    })
    await waitFor(() => !!document.querySelector('[data-testid="ticket-workflow-section"]'), [], 'sección del flujo')
    await page(() => { document.querySelector('[data-testid="ticket-workflow-section"] input[value="simple"]').click(); return true })
    await clickText('Guardar', '[data-testid="ticket-workflow-section"]')
    await waitText('flujo simple')
    await shot('08-config-simple')
  })

  await step('Detalle en el simple: «Iniciar trabajo», una sola casilla y la ficha con saldo', async () => {
    await go('/support/2')
    await waitFor(() => !!document.querySelector('[data-testid="primary-action"]'), [], 'botón principal')
    const principal = await textOf('[data-testid="primary-action"]')
    assert(principal.includes('Iniciar trabajo'), `principal: ${principal}`)
    const casillas = await page(() => [...document.querySelectorAll('[data-testid^="check-"]')].map(x => x.dataset.testid))
    assert(casillas.join() === 'check-solution', `casillas: ${casillas}`)
    assert((await textOf('[data-testid="ticket-status-badge"]')).includes('Abierto'), 'estado «Abierto»')
    await waitFor(() => !!document.querySelector('[data-testid="ticket-customer-card"]')?.innerText.match(/Cortado[\s\S]*vencida/), [], 'cortado y factura vencida')
    await shot('09-detalle-simple')
  })

  await step('Resolver y cerrar en el simple: sólo «qué se hizo»', async () => {
    await clickTestId('resolve-and-close')
    await waitFor((m) => !!document.querySelector(m)?.innerText.includes('¿Qué se hizo?'), [MODAL], 'ventana simple')
    const sinMedicion = await page((m) => !document.querySelector(m).innerText.includes('Medición final'), MODAL)
    assert(sinMedicion, 'el simple no debe pedir medición')
    await markByLabel(MODAL, '¿Qué se hizo?', 'qh')
    await chooseFirst('[data-e2e="qh"]')
    await shot('10-resolver-simple')
    await clickText('Cerrar ticket', MODAL)
    await waitFor(() => document.querySelector('[data-testid="ticket-status-badge"]')?.innerText.includes('Cerrado'), [], 'cerrado en el simple', 45000)
  })

  await step('Celular (390 px): cola y detalle sin desbordar', async () => {
    await viewport(390, 844)
    await go('/support')
    await waitFor(() => document.body.innerText.includes('WhatsApp'), [], 'tarjetas móviles')
    const ancho = await page(() => [document.documentElement.scrollWidth, window.innerWidth])
    assert(ancho[0] <= ancho[1] + 1, `la cola desborda: ${ancho}`)
    await shot('11-movil-cola')
    await go(`/support/${ASIGNADO}`)
    await waitFor(() => !!document.querySelector('[data-testid="next-step-card"]'), [], 'detalle móvil')
    const ancho2 = await page(() => [document.documentElement.scrollWidth, window.innerWidth])
    assert(ancho2[0] <= ancho2[1] + 1, `el detalle desborda: ${ancho2}`)
    await shot('12-movil-detalle')
    await viewport(1366, 900)
  })

  await step('Técnico: «Mis tickets» y, sin permiso de cerrar, «Proponer cierre»', async () => {
    await login(TECH)
    await go('/support')
    await clickTestId('tab-mine')
    await waitFor(() => document.querySelectorAll('[data-testid^="ticket-row-"]').length >= 2, [], 'mis tickets')
    await shot('13-tecnico-mis-tickets')
    await go(`/support/${ASIGNADO}`)
    await waitFor(() => !!document.querySelector('[data-testid="primary-action"]'), [], 'botón principal')
    const principal = await textOf('[data-testid="primary-action"]')
    assert(principal.includes('Proponer cierre'), `principal del técnico: ${principal}`)
    await shot('14-tecnico-proponer')
  })

  console.log(`\n✔ ${steps.length} pasos. Capturas en ${OUT}`)
} catch (e) {
  console.log('FALLÓ')
  console.error(e.message)
  try { await shot('zz-error') } catch {}
  process.exitCode = 1
} finally {
  try { ws?.close() } catch {}
  browser.kill()
}
