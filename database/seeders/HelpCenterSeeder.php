<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HelpCategory;
use App\Models\HelpArticle;

class HelpCenterSeeder extends Seeder
{
    public function run(): void
    {
        HelpArticle::query()->delete();
        HelpCategory::query()->delete();

        foreach ($this->getCategories() as $catData) {
            $articles = $catData['articles'];
            unset($catData['articles']);
            $category = HelpCategory::create($catData);
            foreach ($articles as $article) {
                HelpArticle::create(array_merge($article, ['category_id' => $category->id]));
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // SVG HELPERS
    // ─────────────────────────────────────────────────────────────

    private function legend(array $items): string
    {
        $html = '<div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 16px;margin:4px auto 24px;max-width:660px;font-family:system-ui,sans-serif;font-size:12px;color:#cbd5e1;display:flex;flex-wrap:wrap;gap:12px">';
        foreach ($items as $i => $label) {
            $n = $i + 1;
            $html .= '<span style="display:flex;align-items:center;gap:5px"><span style="background:#6366f1;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;font-size:10px;font-weight:700">'.$n.'</span>'.htmlspecialchars($label).'</span>';
        }
        return $html . '</div>';
    }

    private function callout(int $x, int $y, int $n): string
    {
        return '<circle cx="'.$x.'" cy="'.$y.'" r="11" fill="#6366f1"/>'
             . '<text x="'.$x.'" y="'.($y+4).'" fill="#fff" font-size="11" font-family="system-ui,sans-serif" font-weight="bold" text-anchor="middle">'.$n.'</text>';
    }

    // ─── DASHBOARD SVG ───────────────────────────────────────────
    private function dashboardSvg(): string
    {
        $s  = '<svg width="660" height="320" viewBox="0 0 660 320" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:16px auto;max-width:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.6)">';
        $s .= '<defs><clipPath id="dc"><rect width="660" height="320" rx="12"/></clipPath></defs>';
        $s .= '<g clip-path="url(#dc)">';
        // Backgrounds
        $s .= '<rect width="660" height="320" fill="#111827"/>';
        $s .= '<rect width="148" height="320" fill="#1f2937"/>';
        // Header
        $s .= '<rect width="660" height="40" fill="#1f2937"/>';
        $s .= '<line x1="148" y1="40" x2="660" y2="40" stroke="#374151" stroke-width="1"/>';
        $s .= '<line x1="148" y1="0" x2="148" y2="320" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="16" y="26" fill="#6366f1" font-size="13" font-family="system-ui,sans-serif" font-weight="bold">ISPWatch</text>';
        $s .= '<text x="530" y="26" fill="#9ca3af" font-size="10" font-family="system-ui,sans-serif">Axel Cano  ▾   ☀</text>';
        // Sidebar menu items
        $menuItems = ['Dashboard','Clientes','Facturación','Routers','Soporte','Inventario','Usuarios','Config.','Manual'];
        foreach ($menuItems as $i => $item) {
            $y = 54 + $i * 28;
            $active = $i === 0;
            if ($active) $s .= '<rect x="8" y="'.($y-14).'" width="132" height="24" fill="#312e81" rx="5"/>';
            $color = $active ? '#a5b4fc' : '#9ca3af';
            $s .= '<text x="20" y="'.$y.'" fill="'.$color.'" font-size="10" font-family="system-ui,sans-serif">'.$item.'</text>';
        }
        // Stat cards row 1
        $cards = [
            ['Clientes Activos','142','#4ade80'],
            ['Suspendidos','12','#f87171'],
            ['Fact. Pendientes','$1,840','#fbbf24'],
        ];
        foreach ($cards as $i => [$label, $val, $color]) {
            $x = 160 + $i * 166;
            $s .= '<rect x="'.$x.'" y="52" width="158" height="72" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
            $s .= '<text x="'.($x+10).'" y="72" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">'.$label.'</text>';
            $s .= '<text x="'.($x+10).'" y="100" fill="'.$color.'" font-size="22" font-family="system-ui,sans-serif" font-weight="bold">'.$val.'</text>';
        }
        // Stat cards row 2
        $cards2 = [
            ['Ingresos del Mes','$3,250','#818cf8'],
            ['Routers Online','5 / 5','#4ade80'],
            ['Tickets Abiertos','7','#f59e0b'],
        ];
        foreach ($cards2 as $i => [$label, $val, $color]) {
            $x = 160 + $i * 166;
            $s .= '<rect x="'.$x.'" y="136" width="158" height="72" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
            $s .= '<text x="'.($x+10).'" y="156" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">'.$label.'</text>';
            $s .= '<text x="'.($x+10).'" y="184" fill="'.$color.'" font-size="22" font-family="system-ui,sans-serif" font-weight="bold">'.$val.'</text>';
        }
        // Recent table
        $s .= '<rect x="160" y="222" width="490" height="86" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="172" y="240" fill="#f3f4f6" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">Facturas Recientes</text>';
        $s .= '<line x1="160" y1="248" x2="650" y2="248" stroke="#374151" stroke-width="1"/>';
        foreach (['Cliente','Monto','Vence','Estado'] as $i => $h) {
            $x = [172,330,430,530][$i];
            $s .= '<text x="'.$x.'" y="263" fill="#6b7280" font-size="9" font-family="system-ui,sans-serif">'.$h.'</text>';
        }
        $rows = [['Juan Pérez','$25.00','31/05','Pendiente','#fef3c7','#92400e'],['María López','$30.00','28/05','Pagada','#d1fae5','#065f46']];
        foreach ($rows as $ri => [$name,$amt,$date,$st,$bg,$tc]) {
            $ry = 278 + $ri * 20;
            $s .= '<text x="172" y="'.$ry.'" fill="#d1d5db" font-size="9" font-family="system-ui,sans-serif">'.$name.'</text>';
            $s .= '<text x="330" y="'.$ry.'" fill="#d1d5db" font-size="9" font-family="system-ui,sans-serif">'.$amt.'</text>';
            $s .= '<text x="430" y="'.$ry.'" fill="#d1d5db" font-size="9" font-family="system-ui,sans-serif">'.$date.'</text>';
            $s .= '<rect x="525" y="'.($ry-12).'" width="60" height="15" fill="'.$bg.'" rx="4"/>';
            $s .= '<text x="555" y="'.($ry-1).'" fill="'.$tc.'" font-size="8" font-family="system-ui,sans-serif" text-anchor="middle">'.$st.'</text>';
        }
        // Callouts
        $s .= $this->callout(239, 52, 1);
        $s .= $this->callout(74, 150, 2);
        $s .= $this->callout(405, 222, 3);
        $s .= '</g></svg>';
        return $s . $this->legend([
            'Tarjetas de resumen: clientes, morosos, ingresos y tickets',
            'Menú lateral — accede a cualquier módulo',
            'Tabla de actividad reciente',
        ]);
    }

    // ─── CUSTOMER FORM SVG ────────────────────────────────────────
    private function customerFormSvg(): string
    {
        $s  = '<svg width="660" height="350" viewBox="0 0 660 350" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:16px auto;max-width:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.6)">';
        $s .= '<defs><clipPath id="cf"><rect width="660" height="350" rx="12"/></clipPath></defs>';
        $s .= '<g clip-path="url(#cf)">';
        $s .= '<rect width="660" height="350" fill="#111827"/>';
        // Header
        $s .= '<rect width="660" height="44" fill="#1f2937"/>';
        $s .= '<text x="20" y="28" fill="#f3f4f6" font-size="14" font-family="system-ui,sans-serif" font-weight="bold">Nuevo Cliente</text>';
        $s .= '<rect x="590" y="10" width="60" height="24" fill="#6366f1" rx="6"/>';
        $s .= '<text x="620" y="26" fill="#fff" font-size="10" font-family="system-ui,sans-serif" text-anchor="middle">Guardar</text>';
        // Section 1: Personal data (left column)
        $s .= '<rect x="10" y="54" width="310" height="200" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="22" y="73" fill="#a5b4fc" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">DATOS PERSONALES</text>';
        $fields1 = ['Nombre completo','Cédula / DNI','Correo electrónico','Teléfono / WhatsApp','Dirección'];
        foreach ($fields1 as $i => $f) {
            $fy = 90 + $i * 32;
            $s .= '<text x="22" y="'.$fy.'" fill="#9ca3af" font-size="8" font-family="system-ui,sans-serif">'.$f.'</text>';
            $s .= '<rect x="22" y="'.($fy+4).'" width="280" height="18" fill="#374151" rx="4"/>';
        }
        // Section 2: Service (right column top)
        $s .= '<rect x="330" y="54" width="320" height="130" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="342" y="73" fill="#a5b4fc" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">SERVICIO Y RED</text>';
        $fields2 = ['Plan de servicio','Router asignado','IP asignada','Sectorial'];
        foreach ($fields2 as $i => $f) {
            $fy = 90 + $i * 26;
            $s .= '<text x="342" y="'.$fy.'" fill="#9ca3af" font-size="8" font-family="system-ui,sans-serif">'.$f.'</text>';
            $s .= '<rect x="342" y="'.($fy+3).'" width="294" height="16" fill="#374151" rx="4"/>';
        }
        // Section 3: Map (right column bottom)
        $s .= '<rect x="330" y="194" width="320" height="108" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="342" y="212" fill="#a5b4fc" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">UBICACIÓN GPS</text>';
        // map placeholder
        $s .= '<rect x="342" y="218" width="296" height="74" fill="#0f172a" rx="6"/>';
        $s .= '<text x="490" y="259" fill="#374151" font-size="20" font-family="system-ui,sans-serif" text-anchor="middle">🗺</text>';
        $s .= '<text x="490" y="280" fill="#4b5563" font-size="9" font-family="system-ui,sans-serif" text-anchor="middle">Haz clic para marcar la ubicación</text>';
        // Status section
        $s .= '<rect x="10" y="264" width="310" height="50" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="22" y="283" fill="#a5b4fc" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">ESTADO DEL CLIENTE</text>';
        $s .= '<rect x="22" y="290" width="100" height="16" fill="#374151" rx="4"/>';
        $s .= '<text x="72" y="301" fill="#9ca3af" font-size="8" font-family="system-ui,sans-serif" text-anchor="middle">Activo ▾</text>';
        // Callouts
        $s .= $this->callout(166, 54, 1);
        $s .= $this->callout(490, 54, 2);
        $s .= $this->callout(490, 194, 3);
        $s .= $this->callout(620, 10, 4);
        $s .= '</g></svg>';
        return $s . $this->legend([
            'Datos personales del titular',
            'Configuración del servicio y red',
            'Mapa para marcar coordenadas GPS',
            'Botón guardar — crea el cliente y aprovisiona en MikroTik',
        ]);
    }

    // ─── PAYMENT FORM SVG ─────────────────────────────────────────
    private function paymentFormSvg(): string
    {
        $s  = '<svg width="660" height="280" viewBox="0 0 660 280" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:16px auto;max-width:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.6)">';
        $s .= '<defs><clipPath id="pf"><rect width="660" height="280" rx="12"/></clipPath></defs>';
        $s .= '<g clip-path="url(#pf)">';
        $s .= '<rect width="660" height="280" fill="#111827"/>';
        $s .= '<rect width="660" height="44" fill="#1f2937"/>';
        $s .= '<text x="20" y="28" fill="#f3f4f6" font-size="14" font-family="system-ui,sans-serif" font-weight="bold">Registrar Pago</text>';
        // Client selector
        $s .= '<rect x="16" y="58" width="628" height="42" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="28" y="74" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Cliente</text>';
        $s .= '<rect x="28" y="78" width="600" height="14" fill="#374151" rx="4"/>';
        $s .= '<text x="38" y="89" fill="#6b7280" font-size="8" font-family="system-ui,sans-serif">Buscar cliente por nombre o cédula...</text>';
        // Amount + Method
        $s .= '<rect x="16" y="110" width="305" height="72" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="28" y="128" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Monto recibido</text>';
        $s .= '<rect x="28" y="133" width="280" height="28" fill="#374151" rx="6"/>';
        $s .= '<text x="44" y="152" fill="#f3f4f6" font-size="15" font-family="system-ui,sans-serif" font-weight="bold">$  _____</text>';
        $s .= '<rect x="339" y="110" width="305" height="72" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="351" y="128" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Método de pago</text>';
        $s .= '<rect x="351" y="133" width="280" height="28" fill="#374151" rx="6"/>';
        $s .= '<text x="368" y="152" fill="#9ca3af" font-size="10" font-family="system-ui,sans-serif">Efectivo  ▾</text>';
        // Invoices preview
        $s .= '<rect x="16" y="192" width="528" height="60" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="28" y="210" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Facturas pendientes del cliente</text>';
        $s .= '<rect x="28" y="216" width="260" height="14" fill="#374151" rx="4"/>';
        $s .= '<text x="38" y="227" fill="#6b7280" font-size="8" font-family="system-ui,sans-serif">INV-0045  Mayo 2026  $25.00  Vence 31/05</text>';
        $s .= '<rect x="28" y="234" width="260" height="12" fill="#374151" rx="4" opacity="0.6"/>';
        // Register button
        $s .= '<rect x="556" y="196" width="100" height="52" fill="#6366f1" rx="8"/>';
        $s .= '<text x="606" y="226" fill="#fff" font-size="11" font-family="system-ui,sans-serif" font-weight="bold" text-anchor="middle">Registrar</text>';
        $s .= '<text x="606" y="240" fill="#c7d2fe" font-size="9" font-family="system-ui,sans-serif" text-anchor="middle">Pago</text>';
        // Callouts
        $s .= $this->callout(330, 58, 1);
        $s .= $this->callout(168, 110, 2);
        $s .= $this->callout(491, 110, 3);
        $s .= $this->callout(272, 192, 4);
        $s .= $this->callout(606, 192, 5);
        $s .= '</g></svg>';
        return $s . $this->legend([
            'Buscar cliente por nombre o cédula',
            'Monto recibido',
            'Método de pago (efectivo, transferencia, etc.)',
            'Facturas pendientes que se cubrirán automáticamente',
            'Botón registrar — aplica el pago y reactiva si estaba suspendido',
        ]);
    }

    // ─── ROUTER FORM SVG ──────────────────────────────────────────
    private function routerFormSvg(): string
    {
        $s  = '<svg width="660" height="310" viewBox="0 0 660 310" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:16px auto;max-width:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.6)">';
        $s .= '<defs><clipPath id="rf"><rect width="660" height="310" rx="12"/></clipPath></defs>';
        $s .= '<g clip-path="url(#rf)">';
        $s .= '<rect width="660" height="310" fill="#111827"/>';
        $s .= '<rect width="660" height="44" fill="#1f2937"/>';
        $s .= '<text x="20" y="28" fill="#f3f4f6" font-size="14" font-family="system-ui,sans-serif" font-weight="bold">Agregar Router MikroTik</text>';
        // Connection section (left)
        $s .= '<rect x="10" y="54" width="318" height="200" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="22" y="73" fill="#a5b4fc" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">CONEXIÓN</text>';
        $connFields = ['Nombre del router','IP / Hostname','Puerto API (8728)','Puerto SSH (22)','Usuario API','Contraseña API'];
        foreach ($connFields as $i => $f) {
            $fy = 88 + $i * 28;
            $s .= '<text x="22" y="'.$fy.'" fill="#9ca3af" font-size="8" font-family="system-ui,sans-serif">'.$f.'</text>';
            $s .= '<rect x="22" y="'.($fy+3).'" width="290" height="16" fill="#374151" rx="4"/>';
        }
        // Billing section (right)
        $s .= '<rect x="336" y="54" width="314" height="130" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="348" y="73" fill="#a5b4fc" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">FACTURACIÓN</text>';
        $billingFields = ['Día de facturación (1-31)','Días de gracia','WAN Interface'];
        foreach ($billingFields as $i => $f) {
            $fy = 90 + $i * 34;
            $s .= '<text x="348" y="'.$fy.'" fill="#9ca3af" font-size="8" font-family="system-ui,sans-serif">'.$f.'</text>';
            $s .= '<rect x="348" y="'.($fy+3).'" width="288" height="18" fill="#374151" rx="4"/>';
        }
        // Status section (right bottom)
        $s .= '<rect x="336" y="194" width="314" height="60" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="348" y="213" fill="#a5b4fc" font-size="10" font-family="system-ui,sans-serif" font-weight="bold">ESTADO DE CONEXIÓN</text>';
        $s .= '<rect x="348" y="220" width="140" height="24" fill="#374151" rx="6"/>';
        $s .= '<text x="418" y="236" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif" text-anchor="middle">● Verificar conexión</text>';
        $s .= '<rect x="500" y="220" width="136" height="24" fill="#6366f1" rx="6"/>';
        $s .= '<text x="568" y="236" fill="#fff" font-size="9" font-family="system-ui,sans-serif" text-anchor="middle">Guardar Router</text>';
        // Save
        $s .= '<rect x="10" y="264" width="640" height="36" fill="#0f172a" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="330" y="287" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif" text-anchor="middle">Tras guardar, usa "Verificar conexión" para confirmar que ISPWatch puede comunicarse con el router.</text>';
        // Callouts
        $s .= $this->callout(164, 54, 1);
        $s .= $this->callout(493, 54, 2);
        $s .= $this->callout(493, 194, 3);
        $s .= $this->callout(568, 220, 4);
        $s .= '</g></svg>';
        return $s . $this->legend([
            'Datos de conexión: IP, puertos API y SSH, credenciales',
            'Configuración de facturación: día de cobro y días de gracia',
            'Botón para verificar la conexión en tiempo real',
            'Guardar el router en el sistema',
        ]);
    }

    // ─── SUSPENSION FLOW SVG ──────────────────────────────────────
    private function suspensionFlowSvg(): string
    {
        $s  = '<svg width="660" height="190" viewBox="0 0 660 190" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:16px auto;max-width:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.6)">';
        $s .= '<defs><clipPath id="sf"><rect width="660" height="190" rx="12"/></clipPath></defs>';
        $s .= '<g clip-path="url(#sf)">';
        $s .= '<rect width="660" height="190" fill="#111827"/>';
        // Title
        $s .= '<text x="330" y="24" fill="#9ca3af" font-size="11" font-family="system-ui,sans-serif" text-anchor="middle" font-weight="bold">FLUJO DE SUSPENSIÓN Y REACTIVACIÓN</text>';
        // Top row: suspension flow
        $nodes = [
            [20, 'Scheduler\ndiario', '#374151', '#9ca3af'],
            [180, 'Factura\nvencida', '#7f1d1d', '#fca5a5'],
            [340, 'Corte enviado\na MikroTik', '#7c3aed', '#c4b5fd'],
            [500, 'IP\nBloqueada', '#991b1b', '#f87171'],
        ];
        foreach ($nodes as [$x, $label, $bg, $tc]) {
            $s .= '<rect x="'.($x).'" y="38" width="130" height="44" fill="'.$bg.'" rx="8"/>';
            $lines = explode('\n', $label);
            foreach ($lines as $li => $line) {
                $s .= '<text x="'.($x+65).'" y="'.(56+$li*14).'" fill="'.$tc.'" font-size="10" font-family="system-ui,sans-serif" text-anchor="middle" font-weight="bold">'.$line.'</text>';
            }
        }
        // Arrows top row
        foreach ([150,310,470] as $ax) {
            $s .= '<line x1="'.$ax.'" y1="60" x2="'.($ax+28).'" y2="60" stroke="#4b5563" stroke-width="2" marker-end="url(#arr)"/>';
        }
        // Defs arrow marker
        $s .= '<defs><marker id="arr" markerWidth="8" markerHeight="8" refX="6" refY="3" orient="auto"><path d="M0,0 L0,6 L8,3 Z" fill="#4b5563"/></marker></defs>';
        // Bottom row: reactivation flow (reversed)
        $nodes2 = [
            [500, 'Cliente\npaga', '#14532d', '#86efac'],
            [340, 'Pago\nregistrado', '#7c3aed', '#c4b5fd'],
            [180, 'Reactivación\na MikroTik', '#1e3a5f', '#93c5fd'],
            [20, 'IP\nDesbloqueada', '#14532d', '#4ade80'],
        ];
        foreach ($nodes2 as [$x, $label, $bg, $tc]) {
            $s .= '<rect x="'.($x).'" y="118" width="130" height="44" fill="'.$bg.'" rx="8"/>';
            $lines = explode('\n', $label);
            foreach ($lines as $li => $line) {
                $s .= '<text x="'.($x+65).'" y="'.(136+$li*14).'" fill="'.$tc.'" font-size="10" font-family="system-ui,sans-serif" text-anchor="middle" font-weight="bold">'.$line.'</text>';
            }
        }
        // Arrows bottom row (right to left)
        foreach ([470,310,150] as $ax) {
            $s .= '<line x1="'.($ax+2).'" y1="140" x2="'.($ax-28).'" y2="140" stroke="#4b5563" stroke-width="2" marker-end="url(#arr)"/>';
        }
        // Vertical connector
        $s .= '<line x1="565" y1="82" x2="565" y2="118" stroke="#4b5563" stroke-width="2" stroke-dasharray="4"/>';
        // Labels
        $s .= '<text x="330" y="108" fill="#4b5563" font-size="8" font-family="system-ui,sans-serif" text-anchor="middle">← Flujo de reactivación (automático al registrar pago)</text>';
        $s .= '</g></svg>';
        return $s;
    }

    // ─── TICKET FORM SVG ──────────────────────────────────────────
    private function ticketFormSvg(): string
    {
        $s  = '<svg width="660" height="290" viewBox="0 0 660 290" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:16px auto;max-width:100%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.6)">';
        $s .= '<defs><clipPath id="tf"><rect width="660" height="290" rx="12"/></clipPath></defs>';
        $s .= '<g clip-path="url(#tf)">';
        $s .= '<rect width="660" height="290" fill="#111827"/>';
        $s .= '<rect width="660" height="44" fill="#1f2937"/>';
        $s .= '<text x="20" y="28" fill="#f3f4f6" font-size="14" font-family="system-ui,sans-serif" font-weight="bold">Nuevo Ticket de Soporte</text>';
        // Row 1: client + priority
        $s .= '<rect x="10" y="54" width="310" height="52" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="22" y="70" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Cliente</text>';
        $s .= '<rect x="22" y="74" width="285" height="20" fill="#374151" rx="5"/>';
        $s .= '<text x="34" y="88" fill="#6b7280" font-size="9" font-family="system-ui,sans-serif">Buscar cliente...</text>';
        $s .= '<rect x="330" y="54" width="320" height="52" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="342" y="70" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Prioridad</text>';
        foreach (['Baja','Media','Alta','Urgente'] as $i => $p) {
            $colors = ['#374151','#374151','#374151','#7f1d1d'];
            $tc = $i === 3 ? '#fca5a5' : '#9ca3af';
            $bx = 342 + $i * 74;
            $s .= '<rect x="'.$bx.'" y="74" width="68" height="20" fill="'.$colors[$i].'" rx="5"/>';
            $s .= '<text x="'.(376 + $i * 74).'" y="88" fill="'.$tc.'" font-size="9" font-family="system-ui,sans-serif" text-anchor="middle">'.$p.'</text>';
        }
        // Row 2: title
        $s .= '<rect x="10" y="116" width="640" height="44" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="22" y="132" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Título del ticket</text>';
        $s .= '<rect x="22" y="136" width="614" height="16" fill="#374151" rx="4"/>';
        $s .= '<text x="34" y="148" fill="#6b7280" font-size="8" font-family="system-ui,sans-serif">Describe el problema brevemente...</text>';
        // Row 3: description + assignee
        $s .= '<rect x="10" y="170" width="460" height="80" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="22" y="186" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Descripción detallada</text>';
        $s .= '<rect x="22" y="190" width="436" height="50" fill="#374151" rx="4"/>';
        $s .= '<rect x="480" y="170" width="170" height="80" fill="#1f2937" rx="8" stroke="#374151" stroke-width="1"/>';
        $s .= '<text x="492" y="186" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif">Técnico asignado</text>';
        $s .= '<rect x="492" y="190" width="146" height="18" fill="#374151" rx="4"/>';
        $s .= '<text x="565" y="201" fill="#9ca3af" font-size="9" font-family="system-ui,sans-serif" text-anchor="middle">Sin asignar  ▾</text>';
        $s .= '<rect x="492" y="218" width="146" height="24" fill="#6366f1" rx="6"/>';
        $s .= '<text x="565" y="234" fill="#fff" font-size="10" font-family="system-ui,sans-serif" text-anchor="middle" font-weight="bold">Crear Ticket</text>';
        // Callouts
        $s .= $this->callout(165, 54, 1);
        $s .= $this->callout(490, 54, 2);
        $s .= $this->callout(330, 116, 3);
        $s .= $this->callout(240, 170, 4);
        $s .= $this->callout(565, 218, 5);
        $s .= '</g></svg>';
        return $s . $this->legend([
            'Seleccionar el cliente que reporta el problema',
            'Prioridad: Baja, Media, Alta o Urgente',
            'Título breve del problema',
            'Descripción detallada para el técnico',
            'Crear ticket y notificar al técnico asignado',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // CATEGORIES & ARTICLES
    // ─────────────────────────────────────────────────────────────

    private function getCategories(): array
    {
        return [
            // 1. PRIMEROS PASOS
            [
                'name' => 'Primeros Pasos',
                'icon' => 'md-dashboard-outlined',
                'display_order' => 1,
                'articles' => [
                    [
                        'title' => '¿Qué es ISPWatch?',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Lo que diferencia a ISPWatch de un software de facturación normal es que está conectado con los routers de verdad: la factura vencida dispara el corte real, y el pago dispara la reconexión real.',
                        'content' => '<h2>Bienvenido a ISPWatch</h2>
<p>ISPWatch es la plataforma donde tu empresa de internet lleva <strong>todo</strong>: los clientes, lo que cada uno paga, los equipos de la red y el soporte técnico.</p>
<h3>Qué lo hace distinto</h3>
<p>ISPWatch <strong>está conectado con los routers de verdad</strong>. Cuando das de alta a un cliente, el sistema lo configura solo en el equipo. Cuando un cliente se atrasa en el pago, le corta el internet solo. Y cuando paga, se lo devuelve solo.</p>
<ul>
  <li><strong>Clientes</strong>: registro, mapa geográfico, suspensión y reactivación automática.</li>
  <li><strong>Facturación</strong>: generación automática por router, prorrateo, meses de cortesía, recordatorios y pagos.</li>
  <li><strong>Routers MikroTik</strong>: aprovisionamiento, reglas de bloqueo y corte, todo a través de un túnel privado.</li>
  <li><strong>Soporte</strong>: tickets con mensajes internos, adjuntos y cargos facturables.</li>
  <li><strong>Inventario</strong>: equipos por serial y MAC, stock, proveedores y sucursales.</li>
  <li><strong>Roles y permisos</strong>: control granular de qué ve y qué puede hacer cada persona.</li>
</ul>
<h3>Cada empresa, su propio espacio</h3>
<p>Cada empresa registrada opera en un entorno completamente aislado. Tus datos nunca son visibles para otra empresa.</p>
<h3>Acceso desde cualquier lugar</h3>
<p>Es una aplicación web. Puedes entrar desde cualquier navegador actualizado en computadora, tablet o móvil.</p>',
                    ],
                    [
                        'title' => 'Cómo iniciar sesión',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'El error más común al entrar es usar el correo personal en vez del correo de acceso. No son el mismo.',
                        'content' => '<h2>Inicio de sesión</h2>
<h3>Tu usuario NO es tu correo personal</h3>
<p>Para entrar se usa el <strong>correo de acceso</strong>, que crea el sistema con la forma <em>nombre.apellido@dominio-de-tu-empresa</em> — por ejemplo <em>maria.gomez@mi-isp</em>. El correo personal sirve para recibir facturas y avisos, no para entrar.</p>
<p>El correo de acceso siempre se genera <strong>sin tildes ni ñ</strong> (José Muñoz queda como <em>jose.munoz@...</em>), porque los equipos de red no aceptan esos caracteres. Tu nombre sí los conserva.</p>
<h3>Pasos para ingresar</h3>
<ol>
  <li>Abre el navegador y entra a la dirección de tu empresa.</li>
  <li>Escribe tu <strong>correo de acceso</strong> y tu contraseña.</li>
  <li>Marca <strong>Recordarme</strong> si es un equipo de confianza.</li>
  <li>Pulsa <strong>Ingresar</strong>.</li>
</ol>
<h3>Si algo sale mal</h3>
<ul>
  <li><strong>Credenciales incorrectas</strong>: revisa que estés usando el correo de acceso, no el personal.</li>
  <li><strong>Verifica tu correo electrónico</strong>: tu cuenta existe pero nunca confirmaste el correo. Busca el mensaje de confirmación (mira también el correo no deseado) o usa <em>Reenviar verificación</em>.</li>
  <li><strong>Demasiados intentos</strong>: tras <strong>5 fallos en un minuto</strong> el sistema bloquea el acceso un rato. Espera lo que indique el mensaje.</li>
  <li><strong>Entrada no válida detectada</strong>: escribiste símbolos que el sistema bloquea por seguridad. Escribe sólo el correo.</li>
</ul>
<h3>Si te expulsa solo</h3>
<p>Tu sesión caducó. Vuelve a entrar: no se pierde nada de lo que ya habías guardado.</p>',
                    ],
                    [
                        'title' => 'Entendiendo el Dashboard',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'Ingresos del mes cuenta PAGOS, no facturas. Un cliente que paga en agosto una factura de julio suma a agosto.',
                        'content' => '<h2>El Dashboard principal</h2>
<p>Al ingresar verás el <strong>Dashboard</strong> — tu panel de control con un resumen del estado de la empresa.</p>'
                            . $this->dashboardSvg()
                            . '<h3>Tarjetas de resumen <span style="color:#6366f1">①</span></h3>
<ul>
  <li><strong>Clientes</strong>: totales (habilitados en el sistema), activos (con el servicio prendido) y suspendidos (la resta de los dos).</li>
  <li><strong>Ingresos del mes</strong>: el <strong>dinero que entró</strong> este mes, es decir la suma de los pagos registrados con fecha de este mes. <strong>No es lo facturado.</strong></li>
  <li><strong>Saldo pendiente</strong>: lo que el conjunto de clientes debe, sumando facturas emitidas y vencidas de <strong>todos</strong> los meses, no sólo del actual.</li>
  <li><strong>Tasa de recaudo</strong>: qué porcentaje de lo facturado este mes ya se cobró.</li>
  <li><strong>Tickets</strong>: abiertos (incluye los que están en progreso) y urgentes.</li>
  <li><strong>Infraestructura</strong>: sectoriales y routers registrados.</li>
</ul>
<h3>Alerta de falla masiva</h3>
<p>Si un router está marcado en falla general aparece resaltado con su nombre e IP. <strong>Es la alerta más importante del panel</strong>: significa que un nodo está caído y afecta a muchos clientes a la vez.</p>
<h3>Menú lateral <span style="color:#6366f1">②</span></h3>
<p>Accede a todos los módulos desde la barra izquierda. Los grupos que ves dependen de tu rol: <strong>si no ves una sección, es porque tu usuario no tiene permiso</strong>, no porque el sistema falle.</p>
<h3>Actividad reciente <span style="color:#6366f1">③</span></h3>
<p>Los últimos movimientos registrados en el sistema.</p>
<h3>Dos cosas que suelen confundir</h3>
<ul>
  <li><strong>Ingresos del mes cuenta pagos, no facturas.</strong> Si quieres ver lo facturado, ve a <em>Finanzas → Facturación</em>.</li>
  <li><strong>Saldo pendiente arrastra deuda vieja.</strong> No mide el mes: mide todo lo que está sin pagar. Por eso puede subir aunque este mes se haya cobrado bien.</li>
</ul>',
                    ],
                ],
            ],

            // 2. CLIENTES
            [
                'name' => 'Clientes',
                'icon' => 'bi-people-fill',
                'display_order' => 2,
                'articles' => [
                    [
                        'title' => 'Crear un nuevo cliente',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Si el router tiene apagada el alta automática, el cliente se guarda pero NUNCA se carga al equipo, y en pantalla no se ve nada raro. Es la causa más común de "creé el cliente y no tiene internet".',
                        'content' => '<h2>Registrar un cliente</h2>
<p>Ve a <strong>Usuarios → Agregar usuario</strong>. El formulario está dividido en bloques; los campos con asterisco son obligatorios.</p>'
                            . $this->customerFormSvg()
                            . '<h3>Datos personales <span style="color:#6366f1">①</span></h3>
<ul>
  <li><strong>Nombre y apellido</strong>. Si es una empresa, marca <em>Es empresa</em> y el apellido queda opcional.</li>
  <li><strong>Cédula</strong> y <strong>teléfono</strong> de contacto.</li>
  <li><strong>Correo personal</strong>: el correo real del cliente. Debe ser único.</li>
  <li><strong>Correo de acceso</strong>: el que usará para entrar. Si lo dejas vacío, el sistema lo crea solo.</li>
  <li><strong>Contraseña</strong>: mínimo 6 caracteres.</li>
</ul>
<h3>Servicio y red <span style="color:#6366f1">②</span></h3>
<ul>
  <li><strong>Plan</strong>: el plan contratado.</li>
  <li><strong>Router</strong>: el equipo al que se conecta.</li>
  <li><strong>Sectorial</strong>: la antena o elemento que lo atiende.</li>
  <li><strong>IP</strong>: al elegir el router, el formulario carga sus rangos y te muestra <strong>cuáles están libres y cuáles ocupadas</strong>. Elige una libre — no se asigna sola.</li>
  <li><strong>Fecha de instalación</strong>: muy importante, de aquí sale el cobro proporcional.</li>
  <li><strong>Es fibra</strong>: márcalo si el cliente es FTTH. Si eliges OLT y puerto NAP se detecta solo.</li>
</ul>
<p><strong>Regla de la IP:</strong> dos clientes del <strong>mismo</strong> router no pueden tener la misma IP. La misma IP sí puede repetirse en <strong>otro</strong> router.</p>
<h3>Primera factura</h3>
<p>Aquí decides qué se le cobra al cliente que entra a mitad de mes: <strong>No facturar</strong> (su primera factura sale el mes siguiente), <strong>Prorrateado</strong> (sólo los días que faltan) o <strong>Mes completo</strong>. También puedes darle <strong>meses de cortesía</strong>, que son meses posteriores al de instalación que salen en cero.</p>
<p>Si dejas estas casillas vacías, el cliente hereda lo que tenga configurado su plan, y si el plan tampoco lo define, lo del router. <strong>El sistema te muestra el cálculo antes de guardar</strong>, sin cobrar nada todavía.</p>
<h3>Ubicación GPS <span style="color:#6366f1">③</span></h3>
<p>Haz clic en el mapa para marcar la ubicación exacta. Aparecerá en el mapa de clientes.</p>
<h3>Guardar <span style="color:#6366f1">④</span></h3>
<p>Hay dos botones distintos:</p>
<ul>
  <li><strong>Guardar</strong>: registra al cliente <strong>sólo en el sistema</strong>. No toca el router.</li>
  <li><strong>Guardar y cargar a la RB</strong>: lo registra <strong>y lo configura en el equipo de red</strong>.</li>
</ul>
<p>El cliente se guarda <strong>de inmediato</strong> y la carga al equipo se hace <strong>en segundo plano</strong>: no tienes que esperar con la pantalla abierta ni te va a salir un tiempo de espera agotado. La parte del router tarda alrededor de medio minuto.</p>
<h3>⚠️ El router tiene que tener activada el alta automática</h3>
<p>La opción <strong>Agregar cliente a MikroTik</strong>, en la ficha del router, <strong>viene apagada de fábrica</strong>. Si está apagada, el cliente se guarda perfectamente pero <strong>nunca se carga al equipo</strong>, y el aviso sólo queda en la bitácora interna — en pantalla no se ve nada raro.</p>
<p>Si la carga falla, entra a la ficha del cliente y usa el botón de <strong>aprovisionar</strong>. Ese botón exige que el cliente tenga <strong>router, plan e IP</strong>; si le falta alguno te lo dice y no hace nada.</p>',
                    ],
                    [
                        'title' => 'Editar y gestionar un cliente',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Puedes cambiar el plan de un cliente en cualquier momento. Recuerda volver a aprovisionarlo para que el cambio llegue al equipo.',
                        'content' => '<h2>Editar un cliente existente</h2>
<p>Desde <strong>Usuarios → Lista de usuarios</strong>, pulsa el icono de editar. Verás el mismo formulario del alta con los datos actuales, más unas pestañas adicionales:</p>
<ul>
  <li><strong>Facturación</strong>: facturas del cliente, saldo y saldo a favor.</li>
  <li><strong>Documentos</strong>: cédula, contrato y otros archivos.</li>
  <li><strong>Instalaciones</strong>: historial de instalaciones.</li>
  <li><strong>Tickets</strong>: tickets de soporte del cliente.</li>
</ul>
<h3>No facturar a este cliente</h3>
<p>Es una casilla en la ficha que lo saca de <strong>todo</strong> el ciclo automático: no recibe factura, ni recordatorio, ni notificación, ni corte. Sirve para casos especiales como cortesías institucionales o pruebas.</p>
<h3>Buscar un cliente</h3>
<p>La búsqueda <strong>ya no distingue mayúsculas</strong>: buscar <em>eliud</em> encuentra a <em>Eliud</em>. Antes no era así y podía parecer que un cliente no existía.</p>',
                    ],
                    [
                        'title' => 'Estados del cliente: activo, suspendido, retirado',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'Al cliente que se fue de verdad hay que ponerlo en Retirado o Cancelado, no dejarlo suspendido. Si lo dejas suspendido seguirá generando deuda que nadie va a pagar.',
                        'content' => '<h2>Los estados no son lo mismo</h2>
<p>La diferencia entre un estado y otro <strong>se paga en la facturación</strong>. Esto es lo que más se confunde de todo el sistema:</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0">
  <tr style="background:#1e293b;color:#e2e8f0">
    <th style="padding:8px;border:1px solid #334155;text-align:left">Estado</th>
    <th style="padding:8px;border:1px solid #334155;text-align:left">Navega</th>
    <th style="padding:8px;border:1px solid #334155;text-align:left">¿Se le sigue facturando?</th>
  </tr>
  <tr><td style="padding:8px;border:1px solid #334155"><strong>Activo</strong></td><td style="padding:8px;border:1px solid #334155">Sí</td><td style="padding:8px;border:1px solid #334155">Sí</td></tr>
  <tr><td style="padding:8px;border:1px solid #334155"><strong>Gratis</strong></td><td style="padding:8px;border:1px solid #334155">Sí</td><td style="padding:8px;border:1px solid #334155">No — es un plan de cortesía</td></tr>
  <tr><td style="padding:8px;border:1px solid #334155"><strong>Suspendido</strong></td><td style="padding:8px;border:1px solid #334155">No</td><td style="padding:8px;border:1px solid #334155"><strong>Sí.</strong> Es un corte temporal: puede volver pagando, así que esos meses se cobran</td></tr>
  <tr><td style="padding:8px;border:1px solid #334155"><strong>Retirado</strong></td><td style="padding:8px;border:1px solid #334155">No</td><td style="padding:8px;border:1px solid #334155"><strong>No.</strong> Es una baja definitiva</td></tr>
  <tr><td style="padding:8px;border:1px solid #334155"><strong>Cancelado</strong></td><td style="padding:8px;border:1px solid #334155">No</td><td style="padding:8px;border:1px solid #334155"><strong>No.</strong> Es una baja definitiva</td></tr>
</table>
<h3>⚠️ Suspender no es dar de baja</h3>
<p>Al suspendido se le siguen emitiendo facturas mes a mes, <strong>a propósito</strong>: si se reconecta, esos meses de servicio existen. Lo único que frena la acumulación es el tope de <em>Dejar de facturar al moroso</em> configurado en el router.</p>
<p>Por eso, <strong>al cliente que se fue de verdad hay que ponerlo en Retirado o Cancelado</strong>. Dejarlo suspendido ensucia tus reportes de cartera con deuda incobrable.</p>
<h3>Corte automático vs. suspensión manual</h3>
<p>Ambos dejan al cliente en <strong>Suspendido</strong>. La diferencia está en la vuelta: del <strong>corte por mora el sistema lo reconecta solo cuando paga</strong>; del manual, no — hay que reactivarlo a mano.</p>',
                    ],
                    [
                        'title' => 'Suspender y reactivar clientes',
                        'display_order' => 4,
                        'is_published' => true,
                        'tips' => 'La revisión de cortes corre CADA HORA, no una vez al día. Al registrar el pago de un cliente cortado por mora, la reactivación ocurre en segundos.',
                        'content' => '<h2>Suspensión y reactivación</h2>
<p>ISPWatch corta el servicio de clientes morosos bloqueando su IP en el equipo.</p>'
                            . $this->suspensionFlowSvg()
                            . '<h3>Suspensión manual</h3>
<ol>
  <li>Entra a la ficha del cliente.</li>
  <li>Pulsa <strong>Suspender</strong> y confirma.</li>
</ol>
<p>Esto actúa <strong>de verdad sobre el router</strong>: el cliente deja de navegar. Necesitas el permiso <em>Activar y Desactivar Clientes</em>.</p>
<h3>Suspensión automática</h3>
<p>El sistema revisa <strong>cada hora</strong> si hay que cortar a alguien. Corta cuando se cumplen <strong>todas</strong> estas condiciones:</p>
<ol>
  <li>El router está configurado como <strong>Corte Automático</strong>.</li>
  <li>Ya llegó el <strong>día de corte</strong> configurado.</li>
  <li>Ya llegó la <strong>hora de corte</strong> configurada.</li>
  <li>El cliente acumula al menos <strong>N facturas vencidas</strong> (N se configura en el router).</li>
</ol>
<p>Si el router está en <strong>Corte Manual</strong>, el sistema no corta: sólo deja la lista de pendientes para que alguien decida.</p>
<h3>Reactivación</h3>
<p>Al registrar el pago, si el cliente queda <strong>completamente</strong> al día, el sistema envía solo el comando de desbloqueo. Si aún debe una factura anterior, sigue cortado.</p>
<p>La reconexión automática <strong>sólo aplica a cortes por facturación</strong>. Si fue suspendido a mano, hay que reactivarlo a mano.</p>',
                    ],
                    [
                        'title' => 'Eliminar un cliente',
                        'display_order' => 5,
                        'is_published' => true,
                        'tips' => 'Eliminar al cliente NO lo saca del router. Suspéndelo primero, confirma que quedó cortado, y después bórralo.',
                        'content' => '<h2>Eliminar un cliente</h2>
<p>En la lista, pulsa el icono de eliminar. El sistema pedirá confirmación. Se borran también su perfil, sus facturas y sus documentos.</p>
<h3>⚠️ Eliminar NO lo saca del router</h3>
<p>El sistema lo borra de la base de datos, pero <strong>su configuración se queda en el equipo y el cliente sigue navegando</strong> — sólo que ya no aparece en ninguna pantalla, así que nadie se entera.</p>
<p>Si de verdad quieres cortarle el servicio, el orden correcto es:</p>
<ol>
  <li><strong>Suspéndelo</strong> desde su ficha.</li>
  <li>Comprueba que quedó cortado de verdad.</li>
  <li><strong>Después</strong> bórralo.</li>
</ol>
<h3>Mejor desactivar que borrar</h3>
<p>Si el cliente sólo se retiró, ponlo en <strong>Retirado</strong> en vez de borrarlo: conservas su historial de pagos y el sistema deja de facturarle igual. Un cliente borrado no se recupera.</p>',
                    ],
                    [
                        'title' => 'Mapa de clientes',
                        'display_order' => 6,
                        'is_published' => true,
                        'tips' => 'Para el mapa necesitas configurar tu clave de Google Maps en Configuración → Mapas. La clave se guarda cifrada y nunca se muestra de vuelta.',
                        'content' => '<h2>Mapa geográfico de clientes</h2>
<p>Accede desde <strong>Usuarios → Mapa de usuarios</strong> para ver todos tus clientes en un mapa interactivo.</p>
<h3>Qué muestra</h3>
<ul>
  <li><strong>Marcadores por estado</strong>: cada cliente aparece como un punto según su estado.</li>
  <li><strong>Antenas con su radio de cobertura</strong>.</li>
  <li><strong>Popup informativo</strong>: al pulsar un marcador se ve nombre, plan, router y estado.</li>
  <li><strong>Filtros</strong> por sectorial, router o estado.</li>
</ul>
<h3>Agregar coordenadas</h3>
<p>Al crear o editar un cliente, haz clic en el mapa del formulario para guardar su ubicación.</p>',
                    ],
                    [
                        'title' => 'Importar clientes desde Excel',
                        'display_order' => 7,
                        'is_published' => true,
                        'tips' => 'Los planes, routers y sectoriales se crean POR NOMBRE: si escribes uno que no existe, se crea. Cuida las mayúsculas y los espacios.',
                        'content' => '<h2>Importación masiva de clientes</h2>
<p>Carga muchos clientes de golpe desde <strong>Acciones masivas</strong>.</p>
<h3>Pasos</h3>
<ol>
  <li>Pulsa <strong>Descargar plantilla</strong>. Baja un Excel con varias hojas: clientes, planes, routers y sectoriales.</li>
  <li>Llena el Excel. La opción <em>Ver documentación de campos</em> explica qué va en cada columna.</li>
  <li>Súbelo con <strong>Importar</strong>.</li>
  <li>Si hay errores, el sistema los lista y puedes <strong>descargarlos en Excel</strong> para corregirlos y volver a subir sólo lo que falló.</li>
</ol>
<h3>Cuidado con los nombres</h3>
<p>Los planes, routers y sectoriales se resuelven <strong>por nombre</strong>: si escribes un nombre que no existe, <strong>se crea</strong>; si ya existe, se reutiliza. Una mayúscula o un espacio de más te deja el catálogo duplicado.</p>',
                    ],
                    [
                        'title' => 'Estadísticas de clientes',
                        'display_order' => 8,
                        'is_published' => true,
                        'tips' => 'Usa las estadísticas para identificar qué sectoriales o planes concentran más morosos.',
                        'content' => '<h2>Estadísticas de clientes</h2>
<p>Accede desde <strong>Usuarios → Estadísticas</strong>.</p>
<h3>Métricas disponibles</h3>
<ul>
  <li><strong>Distribución por estado</strong>.</li>
  <li><strong>Clientes por plan</strong>.</li>
  <li><strong>Clientes por sectorial</strong>: distribución geográfica.</li>
  <li><strong>Clientes por router</strong>: carga de cada equipo.</li>
</ul>',
                    ],
                ],
            ],

            // 3. FACTURACIÓN
            [
                'name' => 'Facturación',
                'icon' => 'fa-file-invoice-dollar',
                'display_order' => 3,
                'articles' => [
                    [
                        'title' => 'Cómo funciona la facturación automática',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'La facturación se configura POR ROUTER, no por empresa ni por cliente. Si un cliente no recibe factura, lo primero que hay que mirar es la configuración de su router.',
                        'content' => '<h2>Facturación automática mensual</h2>
<p><strong>Las facturas se generan solas.</strong> No hay que emitirlas a mano cada mes. Pero hay algo que sorprende a casi todo el mundo la primera vez:</p>
<p style="background:#422006;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:6px"><strong>⚠️ La facturación se configura POR ROUTER</strong>, no por empresa ni por cliente.</p>
<h3>Los campos de la configuración del router</h3>
<ul>
  <li><strong>Se emite la factura — Día / Hora</strong>: cuándo se genera la factura de los clientes de ese router.</li>
  <li><strong>Vence la factura — Día límite de pago</strong>: último día para pagar. Pasado ese día la factura queda vencida, pero <strong>el servicio sigue activo</strong>.</li>
  <li><strong>Recordatorio de pago — Día / Hora</strong>: cuándo se avisa al cliente de lo que tiene pendiente.</li>
  <li><strong>Se corta el servicio — Día / Hora</strong>: desde qué día del mes se empieza a suspender morosos.</li>
  <li><strong>Suspender tras X facturas vencidas</strong>: cuántas facturas sin pagar tolera antes de cortar. <strong>Es la condición real del corte.</strong></li>
  <li><strong>Dejar de facturar al moroso</strong>: a partir de cuántas facturas pendientes se le deja de emitir la mensualidad. Por defecto, el umbral de corte <strong>+ 2</strong>.</li>
  <li><strong>Modo de facturación</strong>: <em>Anticipado</em> (se cobra el mes que empieza) o <em>Vencido</em> (el que terminó).</li>
</ul>
<h3>Qué periodo cubre la factura</h3>
<ul>
  <li>El periodo facturado es <strong>siempre el mes calendario completo</strong>, sin importar qué día se emita. La única excepción es el prorrateo de la primera factura de un cliente nuevo.</li>
  <li><strong>El día límite de pago no corta nada.</strong> Sólo marca desde cuándo la factura cuenta como vencida.</li>
  <li><strong>El día de corte es una ventana, no una fecha exacta.</strong> Desde ese día y hasta fin de mes el sistema revisa cada hora, y suspende únicamente a quien haya llegado al número de facturas vencidas configurado. Con el umbral en 2, el corte real suele caer un mes después del primer impago.</li>
  <li>Si configuras el día 31 y el mes tiene 30 días, el sistema factura el 30. No se salta.</li>
</ul>
<h3>Al cliente cortado se le sigue facturando</h3>
<p>El corte no congela la deuda: el cliente puede reconectarse pagando y esos meses de servicio existen. Lo que frena la acumulación es el tope <em>Dejar de facturar al moroso</em>. Con corte en 2 y tope +2, el cliente acumula 4 facturas y ahí para. Los clientes <strong>retirados</strong> o <strong>cancelados</strong> no facturan nunca.</p>
<h3>Así queda el ciclo</h3>
<p>El panel de facturación del router muestra un recuadro que traduce la configuración a <strong>fechas reales del mes en curso</strong> y avisa de combinaciones sospechosas (recordatorio después del vencimiento, o corte antes del vencimiento). Es sólo informativo.</p>',
                    ],
                    [
                        'title' => 'Registrar un pago',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Si el cliente paga de más, el excedente queda como saldo a favor. Si paga de menos, la factura queda pagada igual y el faltante viaja a la próxima.',
                        'content' => '<h2>Registro de pagos</h2>
<p>Ve a <strong>Finanzas → Pagos / Recaudos → Registrar pago</strong>.</p>'
                            . $this->paymentFormSvg()
                            . '<h3>Buscar cliente <span style="color:#6366f1">①</span></h3>
<p>Escribe el nombre o la cédula. El sistema carga sus facturas pendientes.</p>
<h3>Monto <span style="color:#6366f1">②</span></h3>
<p>Ingresa el valor exacto recibido. Puede ser parcial o cubrir varias facturas.</p>
<h3>Método de pago <span style="color:#6366f1">③</span></h3>
<p>Selecciona la forma de pago. El sistema trae Efectivo, Tarjeta, Corresponsal y Transacción, y puedes crear las tuyas.</p>
<h3>Facturas a cubrir <span style="color:#6366f1">④</span></h3>
<p>El pago se aplica a las facturas pendientes <strong>empezando por la más antigua</strong>.</p>
<h3>Qué pasa al guardar <span style="color:#6366f1">⑤</span></h3>
<ol>
  <li>El pago se aplica de la más antigua a la más nueva.</li>
  <li>Si <strong>sobra</strong> dinero, queda como <strong>saldo a favor</strong> y se usa en la próxima factura.</li>
  <li>Si <strong>falta</strong> dinero, la factura queda pagada igual y lo que faltó pasa a la próxima factura.</li>
  <li>Si el cliente queda al día y estaba cortado por mora, <strong>el sistema le devuelve el internet automáticamente</strong>.</li>
</ol>',
                    ],
                    [
                        'title' => 'Abonos parciales: el saldo pasa a la próxima factura',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'Al abonar, el cliente SALE DE MORA y se reconecta, aunque no haya pagado todo. Es a propósito, pero conviene saberlo antes de aceptar el abono.',
                        'content' => '<h2>Cuando el cliente paga menos de lo que debe</h2>
<ul>
  <li>La factura se marca como <strong>Pagada</strong> y su saldo queda en cero.</li>
  <li>Lo que faltó queda como <strong>saldo pendiente</strong> del cliente.</li>
  <li>La <strong>próxima factura mensual lo cobra automáticamente</strong>: sale una línea de saldo pendiente de facturas anteriores, sumada al plan del mes.</li>
</ul>
<h3>Ejemplo</h3>
<p>El cliente debe $50.000 y abona $30.000. Esa factura queda pagada y quedan $20.000 pendientes. El mes siguiente, si el plan vale $50.000, su factura será de <strong>$70.000</strong>.</p>
<h3>⚠️ El abono lo saca de mora</h3>
<p>Al quedar la factura pagada, el cliente <strong>sale de mora</strong>. Si estaba cortado se le devuelve el internet, y <strong>no se le vuelve a cortar hasta que se venza la factura nueva</strong> — la que ya trae la deuda vieja sumada. Al registrar un abono parcial el sistema te avisa de esto y te pide confirmar.</p>
<h3>Dónde ver el saldo arrastrado</h3>
<ul>
  <li>Al registrar un pago: bloque ámbar junto al saldo del cliente.</li>
  <li>Ficha del cliente → Facturación: aviso con el total arrastrado.</li>
  <li>Lista de facturas: en la factura que abonó y en la que lo cobra.</li>
</ul>
<h3>Si lo registraste por error</h3>
<p>Eliminar el pago o marcar la factura como no pagada devuelve el saldo a la factura original, siempre que la próxima factura no lo haya cobrado todavía. Si ya lo cobró, el saldo se queda ahí (no se cobra dos veces).</p>',
                    ],
                    [
                        'title' => 'Ver y descargar facturas',
                        'display_order' => 4,
                        'is_published' => true,
                        'tips' => 'La búsqueda de facturas no distingue mayúsculas y busca a la vez por número de factura, nombre, apellido y correo del cliente.',
                        'content' => '<h2>Listado y detalle de facturas</h2>
<p>Accede desde <strong>Finanzas → Facturación</strong>. Puedes filtrar por estado, cliente y fechas.</p>
<h3>Estados de factura</h3>
<ul>
  <li><strong>Borrador</strong>: creada pero no emitida.</li>
  <li><strong>Emitida</strong>: enviada al cliente, pendiente de pago.</li>
  <li><strong>Parcial</strong>: tiene un abono, falta saldo.</li>
  <li><strong>Pagada</strong>: cancelada por completo.</li>
  <li><strong>Vencida</strong>: pasó la fecha de pago sin pagarse.</li>
  <li><strong>Anulada</strong>: sin efecto.</li>
</ul>
<h3>Desde el detalle puedes</h3>
<ul>
  <li><strong>Descargar PDF</strong> con el diseño y los datos de tu empresa.</li>
  <li><strong>Ver los pagos aplicados</strong> y el saldo.</li>
  <li><strong>Editar</strong> fechas, total o notas.</li>
  <li><strong>Marcar como no pagada</strong>: revierte los pagos y restaura el saldo.</li>
</ul>
<h3>⚠️ Al eliminar una factura, el sistema NO la vuelve a generar</h3>
<p>Deja una marca interna para ese cliente y ese mes. Si la borraste por error, tendrás que crearla a mano. El mes siguiente se factura con normalidad.</p>',
                    ],
                    [
                        'title' => 'Recordatorios y avisos al cliente',
                        'display_order' => 5,
                        'is_published' => true,
                        'tips' => 'Un cliente = un mensaje. Si debe varias facturas recibe UN solo correo o WhatsApp con el listado de todas y el total adeudado.',
                        'content' => '<h2>Recordatorios de pago</h2>
<p>Se envían solos en el <strong>día y la hora configurados en el router</strong>, por correo, por WhatsApp o por ambos.</p>
<h3>Cómo funcionan</h3>
<ul>
  <li>Se envían <strong>por día del mes</strong>, una sola vez por ciclo, sobre las facturas pendientes que tenga el cliente en ese momento.</li>
  <li><strong>Un cliente = un mensaje.</strong> Si debe varias facturas recibe uno solo con el listado y el total, no uno por factura.</li>
  <li>El sistema <strong>no duplica</strong>: si ya se envió uno en ese ciclo, no lo repite.</li>
  <li>Los automáticos van sólo a clientes <strong>activos</strong>: al que ya está cortado el aviso le llega tarde, porque el corte fue el aviso.</li>
</ul>
<h3>Envío manual</h3>
<ul>
  <li><strong>Individual</strong>: desde el detalle de la factura, botón <em>Enviar recordatorio</em>.</li>
  <li><strong>Masivo</strong>: desde la lista, botón <em>Recordatorios masivos</em>.</li>
</ul>
<h3>El aviso de nueva factura</h3>
<p>Cuando se genera la mensualidad el cliente recibe el aviso configurado en el router. Dos detalles:</p>
<ul>
  <li>Si el cliente <strong>ya debía facturas anteriores</strong>, el correo lo dice: cuántas tiene pendientes, el saldo anterior y la <strong>deuda total</strong>.</li>
  <li>Si la factura <strong>nace saldada</strong> porque el saldo a favor la cubrió entera, <strong>no se envía ningún aviso</strong>.</li>
</ul>',
                    ],
                    [
                        'title' => 'Tipos de factura y servicios adicionales',
                        'display_order' => 6,
                        'is_published' => true,
                        'tips' => 'No estás limitado a los tipos de fábrica: crea "Factura de Equipos", "Factura de TV", "Reconexión", lo que uses.',
                        'content' => '<h2>Tipos de factura</h2>
<p>En <strong>Finanzas → Tipos de factura</strong> decides con qué nombres facturas.</p>
<h3>Crear uno</h3>
<ol>
  <li>Pulsa <strong>Nuevo tipo</strong>.</li>
  <li>Escribe el <strong>nombre</strong>.</li>
  <li>Elige un <strong>color</strong> para la etiqueta.</li>
  <li>Guarda — aparece de inmediato en <em>Nueva factura</em> y en <em>Servicios adicionales</em>.</li>
</ol>
<h3>Reglas</h3>
<ul>
  <li><strong>Tipos del sistema</strong> (<em>Plan Mensual</em>, <em>Instalación</em>, <em>Servicio Adicional</em>, <em>Cargo de Ticket</em>): no se editan ni se borran, la facturación automática depende de ellos.</li>
  <li><strong>Desactivar</strong> un tipo lo saca del formulario, pero las facturas que ya lo usan conservan su etiqueta. Es lo que hay que hacer cuando ya no se usa.</li>
  <li><strong>Eliminar</strong> sólo se puede si nunca se emitió una factura con él.</li>
</ul>
<h2>Servicios adicionales</h2>
<p>En <strong>Finanzas → Servicios adicionales</strong> cobras algo puntual que no viene de un ticket: traslado, cambio de equipo, reconexión. También eliges el tipo de factura que se va a emitir.</p>
<h2>Cargos desde un ticket</h2>
<p>Si la visita técnica se cobra, desde el ticket se genera un cargo que crea una factura ligada a ese ticket.</p>',
                    ],
                    [
                        'title' => 'Clientes que no se facturan',
                        'display_order' => 7,
                        'is_published' => true,
                        'tips' => 'El plan llamado "Gratis" está bloqueado para uso exclusivo de cortesía.',
                        'content' => '<h2>Dejar a un cliente fuera de la facturación</h2>
<p>Hay dos formas, y no hacen lo mismo:</p>
<ul>
  <li><strong>Plan de cortesía</strong>: se le asigna un plan marcado como cortesía. Su servicio queda en gratis y no se factura, pero sigue dentro del resto del ciclo.</li>
  <li><strong>No facturar a este cliente</strong>: casilla en su ficha. Lo saca de <strong>todo</strong>: factura, recordatorio, notificación y corte.</li>
</ul>
<h3>Y además</h3>
<p>Los clientes en estado <strong>Retirado</strong> o <strong>Cancelado</strong> no facturan nunca. Y cualquier cliente deja de recibir facturas al alcanzar el tope de <em>Dejar de facturar al moroso</em> de su router.</p>',
                    ],
                ],
            ],

            // 4. CORTE Y RECONEXIÓN
            [
                'name' => 'Corte y Reconexión',
                'icon' => 'bi-shield-exclamation',
                'display_order' => 4,
                'articles' => [
                    [
                        'title' => 'El cliente aparece cortado pero sigue navegando',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Si son MUCHOS clientes del mismo router a la vez, es casi siempre la VPN. Un cliente suelto suele ser otra cosa.',
                        'content' => '<h2>El problema más común del sistema</h2>
<p>Casi siempre es una de tres cosas. El sistema dice cortado porque hizo su parte; lo que falla está en el equipo. Revisa en este orden — está ordenado de la causa más frecuente a la menos frecuente.</p>
<h3>1. El túnel VPN del router está caído</h3>
<p>Si el router no tiene túnel contra el equipo central, ISPWatch <strong>no puede darle ninguna orden</strong>: ni cortar, ni reconectar, ni cargar clientes nuevos. Y como el corte se registra en la base de datos antes de llegar al equipo, la pantalla muestra al cliente cortado aunque en la realidad nunca se tocó nada.</p>
<ul>
  <li>Entra a <strong>Gestión → Lista de Routers</strong>, abre el equipo y pulsa <strong>Verificar VPN</strong>.</li>
  <li>Si sale caído, hay que levantar el túnel desde el router (o volver a aplicarle el script con <em>Generar script VPN</em>) antes de intentar cualquier otra cosa.</li>
  <li><strong>Señal de alarma</strong>: muchos clientes del mismo router sin cortar a la vez.</li>
</ul>
<p>El sistema revisa los túneles cada 30 minutos y avisa por correo cuando encuentra uno caído. No esperes ese aviso si ya tienes la sospecha.</p>
<h3>2. Las reglas de bloqueo no están, o quedaron muy abajo</h3>
<p>El router aplica sus reglas <strong>en orden, de arriba hacia abajo</strong>, y se queda con la primera que coincide. Si las reglas de ISPWatch quedaron por debajo de una regla que deja pasar el tráfico (muy común: las que trae el equipo de fábrica), <strong>nunca llegan a ejecutarse</strong>. Para el router las reglas están ahí; simplemente no se leen nunca.</p>
<p>Esto pasa sobre todo cuando alguien tocó el firewall del equipo a mano después de que ISPWatch instaló las reglas.</p>
<ul>
  <li>Pulsa <strong>Verificar reglas de bloqueo</strong> para ver cómo están puestas.</li>
  <li>Pulsa <strong>Aplicar reglas de bloqueo</strong>: además de instalarlas si faltan, <strong>las vuelve a subir al primer lugar</strong>. Es la forma normal de arreglar el orden.</li>
  <li>Puedes pulsarlo las veces que quieras: no duplica reglas ni rompe lo que ya está bien.</li>
</ul>
<p>Para poder aplicarlas, el router necesita tener fijada la <strong>interfaz WAN</strong>.</p>
<h3>3. El cliente sigue con conexiones abiertas de antes</h3>
<p>Un corte sólo afecta a las conexiones <strong>nuevas</strong>. El sistema corta las existentes al suspender, pero si el corte se aplicó tarde puede que alguna quede colgada unos minutos. Espera un par de minutos y vuelve a comprobar.</p>
<h3>Después de arreglar cualquiera de las tres</h3>
<p style="background:#422006;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:6px">Entra a <strong>Acciones masivas → Bitácora de cortes</strong> y pulsa <strong>Reconciliar</strong>. Eso revisa uno por uno a los clientes que figuran como suspendidos y vuelve a cortar a los que no lo estén de verdad. <strong>Sin este paso, los clientes que ya estaban mal marcados siguen navegando</strong> aunque el router ya esté bien configurado.</p>
<h3>Si con esto no se arregla</h3>
<ul>
  <li>Que el router esté en <strong>Corte Automático</strong>, no Manual.</li>
  <li>Que el equipo responda al SSH (<em>Probar conexión SSH</em>).</li>
  <li>Que el cliente no esté marcado como <strong>No facturar</strong>.</li>
  <li>Que el cliente no haya <strong>abonado</strong>: un abono parcial lo saca de mora.</li>
  <li>El error exacto del intento fallido, en <strong>Acciones masivas → Bitácora de cortes</strong>.</li>
</ul>',
                    ],
                    [
                        'title' => 'Qué le pasa al cliente cortado',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'El cliente cortado no ve un "sin conexión" a secas: ve tu página de pago. Es la forma de que entienda por qué se quedó sin servicio.',
                        'content' => '<h2>Qué ve el cliente cortado</h2>
<p>Deja de navegar, pero <strong>sí puede entrar al portal de pago</strong> de tu empresa. Ese acceso queda abierto a propósito, para que pueda pagar y reconectarse.</p>
<p>Además, mientras está cortado, <strong>cualquier página que intente abrir lo lleva al portal</strong>.</p>
<h3>Requisito</h3>
<p>El portal es una dirección que se configura una sola vez al instalar el sistema. <strong>Si esa dirección no está configurada, las reglas de bloqueo ni siquiera se pueden aplicar</strong>: el sistema te lo dirá al pulsar <em>Aplicar reglas de bloqueo</em>. En ese caso es cosa de soporte técnico.</p>
<h3>La reconexión al pagar</h3>
<p>Al registrar el pago, si el cliente queda <strong>completamente</strong> al día, el sistema le devuelve el internet solo. Si aún debe una factura anterior, sigue cortado.</p>',
                    ],
                    [
                        'title' => 'Ver qué se cortó y qué falló',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'La reconciliación corre sola cada hora. El botón Reconciliar sirve para forzarla cuando acabas de arreglar algo en el equipo.',
                        'content' => '<h2>Las bitácoras de Acciones masivas</h2>
<table style="width:100%;border-collapse:collapse;margin:16px 0">
  <tr style="background:#1e293b;color:#e2e8f0">
    <th style="padding:8px;border:1px solid #334155;text-align:left">Panel</th>
    <th style="padding:8px;border:1px solid #334155;text-align:left">Qué muestra</th>
  </tr>
  <tr><td style="padding:8px;border:1px solid #334155"><strong>Bitácora de facturación</strong></td><td style="padding:8px;border:1px solid #334155">Facturas que no se pudieron crear, con el error y el número de intentos</td></tr>
  <tr><td style="padding:8px;border:1px solid #334155"><strong>Bitácora de cortes</strong></td><td style="padding:8px;border:1px solid #334155">Cortes y reconexiones que fallaron en el equipo</td></tr>
</table>
<p>En ambos puedes pulsar <strong>Reintentar</strong> sobre una fila, o <strong>Reintentar todo</strong>.</p>
<h3>El botón Reconciliar</h3>
<p>En la bitácora de cortes. Revisa uno por uno los clientes que el sistema dio por suspendidos y comprueba que <strong>realmente</strong> estén cortados en el equipo. Si alguno no lo está, lo vuelve a cortar.</p>
<p>El sistema hace esta reconciliación solo cada hora. El botón sirve para forzarla.</p>',
                    ],
                ],
            ],

            // 5. ROUTERS Y RED
            [
                'name' => 'Routers y Red',
                'icon' => 'bi-router',
                'display_order' => 5,
                'articles' => [
                    [
                        'title' => 'Agregar un router MikroTik',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Si el SSH del equipo no está en el puerto 22, tienes que indicarlo en el campo de puerto SSH o el sistema no podrá conectarse.',
                        'content' => '<h2>Conectar un router MikroTik</h2>
<p>Ve a <strong>Gestión → Lista de Routers → Agregar router</strong>.</p>'
                            . $this->routerFormSvg()
                            . '<h3>Datos de conexión <span style="color:#6366f1">①</span></h3>
<ul>
  <li><strong>Nombre</strong> e <strong>IP</strong> del equipo.</li>
  <li><strong>Usuario y contraseña</strong> de administración del equipo.</li>
  <li><strong>Versión de firmware</strong> y estado.</li>
  <li><strong>Puerto API</strong>: 8728 por defecto. <strong>Puerto web</strong>: 80.</li>
  <li><strong>Puerto SSH</strong>: 22 por defecto. <strong>Si el equipo lo tiene en otro puerto, hay que indicarlo aquí</strong> o el sistema no podrá conectarse.</li>
</ul>
<h3>Facturación del router <span style="color:#6366f1">②</span></h3>
<p>Eliges la <strong>configuración de facturación</strong> y el <strong>tipo de corte</strong> (Automático o Manual). <strong>Sin esto, los clientes de ese router no se facturan ni se cortan.</strong></p>
<h3>Alta automática de clientes</h3>
<p>La opción <strong>Agregar cliente a MikroTik</strong> <strong>viene apagada de fábrica</strong>. Con ella apagada, los clientes se guardan en el sistema pero nunca se cargan al equipo. Actívala si quieres que el alta funcione de punta a punta.</p>
<h3>Verificar conexión <span style="color:#6366f1">③</span></h3>
<p>Después de guardar, comprueba que ISPWatch llega al equipo.</p>',
                    ],
                    [
                        'title' => 'El método de control del router',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Sólo puede haber UN método de control activo por router. IP Bindings y Amarre son adicionales: se suman al método elegido.',
                        'content' => '<h2>Cómo controla el router a sus clientes</h2>
<p>En la ficha del router eliges el método. <strong>Sólo puede haber uno activo:</strong></p>
<ul>
  <li><strong>Simple Queue</strong>: control de velocidad por IP. El más común.</li>
  <li><strong>PCQ</strong>: reparto equitativo de ancho de banda.</li>
  <li><strong>HotSpot</strong>: clientes que entran con usuario y contraseña en un portal.</li>
  <li><strong>PPPoE</strong>: clientes con usuario y contraseña de conexión.</li>
  <li><strong>DHCP Leases</strong>: asignación fija por dirección MAC.</li>
</ul>
<h3>Opciones adicionales</h3>
<p>Estas dos <strong>se suman</strong> al método elegido, no lo reemplazan:</p>
<ul>
  <li><strong>IP Bindings</strong>: fija la relación IP–equipo.</li>
  <li><strong>Amarre</strong>: bloquea al cliente si cambia de equipo.</li>
</ul>
<p>El método elegido determina qué credenciales te pide el formulario del cliente: usuario y contraseña PPPoE, usuario y contraseña HotSpot, o la dirección MAC.</p>',
                    ],
                    [
                        'title' => 'Herramientas de diagnóstico',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'Antes de estrenar el corte automático en un router nuevo: verifica la VPN y aplica las reglas de bloqueo. Si no, el primer día de corte marcará a todos como cortados y ninguno lo estará.',
                        'content' => '<h2>Los botones de la ficha del router</h2>
<ul>
  <li><strong>Probar conexión SSH</strong>: comprueba que el sistema llega al equipo.</li>
  <li><strong>Probar conexión al CORE</strong>: comprueba el equipo central.</li>
  <li><strong>Ver interfaces</strong>: lee las interfaces del router.</li>
  <li><strong>Fijar interfaz WAN</strong>: indica cuál es la salida a internet.</li>
  <li><strong>Aplicar reglas de bloqueo</strong>: instala en el equipo las reglas necesarias para cortar morosos <strong>y las sube al primer lugar</strong> si habían quedado por debajo de otras.</li>
  <li><strong>Verificar reglas de bloqueo</strong>: comprueba que las reglas siguen puestas.</li>
  <li><strong>Generar script VPN</strong>: genera el texto para configurar el túnel del equipo.</li>
  <li><strong>Verificar VPN</strong>: comprueba que el túnel está arriba.</li>
</ul>
<p style="background:#422006;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:6px"><strong>⚠️ Antes de usar el corte automático: verifica la VPN y aplica las reglas de bloqueo.</strong> Si el túnel está caído o las reglas no están, el sistema marca al cliente como cortado pero el cliente sigue navegando.</p>
<h3>Límite de operaciones</h3>
<p>Las operaciones que tocan los routers están limitadas a propósito (unas diez por minuto, y menos para las cargas masivas) para no tumbar los equipos. Si el sistema te pide esperar, espera un minuto y sigue.</p>',
                    ],
                    [
                        'title' => 'La VPN: cómo llega ISPWatch a tus equipos',
                        'display_order' => 4,
                        'is_published' => true,
                        'tips' => 'Si el túnel se cae, ISPWatch se queda ciego con ese router: sigue facturando y marcando cortes en pantalla, pero ninguna orden llega al equipo.',
                        'content' => '<h2>Por qué aparece tanto la palabra VPN</h2>
<p>ISPWatch no habla directo con cada router tuyo. Habla con un <strong>equipo central</strong> (el CORE), y ese equipo llega a tus routers por un <strong>túnel privado</strong> que se monta una sola vez, cuando das de alta el equipo. Todo lo que hace el sistema sobre la red —cargar un cliente, cortarlo, reconectarlo, leer las interfaces, medir el tráfico— pasa por ese túnel.</p>
<p style="background:#422006;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:6px"><strong>Si el túnel se cae, ISPWatch se queda ciego con ese router.</strong> Sigue mostrando sus clientes, sigue facturándoles y sigue marcando cortes en pantalla, pero <strong>ninguna orden llega al equipo</strong>. Por eso el primer paso de casi todo diagnóstico de red es <em>Verificar VPN</em>.</p>
<h3>Hay dos tipos de túnel</h3>
<p>El sistema elige solo según la versión del equipo. No tienes que decidir nada:</p>
<ul>
  <li><strong>WireGuard</strong> — para RouterOS <strong>v7</strong> en adelante. Se sabe que está vivo por el último saludo del equipo, que se renueva cada pocos minutos.</li>
  <li><strong>L2TP</strong> — para RouterOS <strong>v6</strong>, que no soporta WireGuard. Se sabe por la sesión activa contra el central.</li>
</ul>
<h3>Por qué se cambió a WireGuard</h3>
<p>Un router con dos salidas a internet podía mandar media conversación por una y media por la otra, y el túnel L2TP se caía en bucle. Pasó de verdad: un equipo estuvo <strong>8 días caído con 212 clientes sin gestión</strong> y nadie se dio cuenta, porque el sistema sólo avisaba de fallos cliente por cliente, nunca de "este router no está". WireGuard no tiene ese problema, y desde entonces hay una revisión automática.</p>
<h3>La revisión automática</h3>
<p>Cada <strong>30 minutos</strong> el sistema comprueba todos los túneles y <strong>avisa por correo</strong> los que encuentre caídos. Sólo mira y avisa: no toca nada ni intenta arreglarlo.</p>
<p>Los routers que nunca se dieron de alta por el central no se revisan y por eso tampoco salen en el aviso. Si un equipo no aparece nunca en las alertas pero tampoco responde, revísalo con <em>Probar conexión SSH</em>.</p>
<h3>Cuándo volver a generar el script VPN</h3>
<p>Cuando el equipo se formateó o se reemplazó, o cuando <em>Verificar VPN</em> da caído y el equipo sí tiene internet. El script se aplica <strong>en el router</strong>, no desde ISPWatch: el botón sólo te da el texto para pegarlo.</p>',
                    ],
                    [
                        'title' => 'Falla masiva',
                        'display_order' => 5,
                        'is_published' => true,
                        'tips' => 'ISPWatch NO envía los mensajes de WhatsApp: deja el aviso registrado para que el sistema de mensajería conectado lo difunda. Confirma con tu proveedor que esa conexión existe.',
                        'content' => '<h2>Reportar una caída que afecta a muchos clientes</h2>
<ol>
  <li>Entra a <strong>Gestión → Lista de Routers</strong>.</li>
  <li>Pulsa <strong>Reportar falla masiva</strong> en el router afectado.</li>
  <li>Cuando se restablezca, pulsa <strong>Marcar como resuelta</strong>.</li>
</ol>
<p>Al reportarla, el sistema marca el router, lo resalta en el Dashboard y <strong>cuenta cuántos clientes activos quedan afectados</strong>. Ambos avisos (la falla y la recuperación) quedan guardados con la hora y el usuario que los reportó; ese registro no se puede editar ni borrar, sirve como historial de la caída.</p>
<h3>⚠️ Sobre el aviso por WhatsApp</h3>
<p>ISPWatch <strong>no envía los mensajes</strong>. Lo que hace es dejar el aviso registrado para que el sistema de mensajería conectado lo lea y lo difunda. Si esa conexión todavía no está montada en tu empresa, el botón sigue siendo útil (marca el router, alerta en el Dashboard y deja el historial), pero <strong>a los clientes no les llega nada</strong>. Confírmalo con tu proveedor antes de contar con el aviso automático.</p>',
                    ],
                    [
                        'title' => 'Historial de tráfico',
                        'display_order' => 6,
                        'is_published' => true,
                        'tips' => 'Empieza a medir desde que lo activas. No hay historial de antes: si acabas de prenderlo, la gráfica sale vacía hasta la siguiente medición.',
                        'content' => '<h2>Medir el tráfico de un router</h2>
<p>Si activas <strong>Historial de tráfico</strong> en el router, el sistema mide el tráfico de su salida a internet <strong>cada 5 minutos</strong> y lo guarda. Se consulta desde la ficha del router.</p>
<h3>Cuánto se conserva</h3>
<ul>
  <li>El <strong>detalle de 5 en 5 minutos</strong> se conserva <strong>30 días</strong>. Sirve para ver el pico de ayer o la caída de anoche.</li>
  <li>El <strong>consumo diario</strong> se guarda <strong>para siempre</strong>. Sirve para comparar meses o años.</li>
</ul>
<h3>Requisitos</h3>
<p>Para que mida hace falta tener fijada la <strong>interfaz WAN</strong> del router.</p>',
                    ],
                    [
                        'title' => 'Planes de internet',
                        'display_order' => 7,
                        'is_published' => true,
                        'tips' => 'Configurar la primera factura en el plan ahorra hacerlo cliente por cliente: todo el que lo contrate hereda la promoción.',
                        'content' => '<h2>Planes de internet</h2>
<p>Define velocidades y precios desde <strong>Gestión → Plan de Internet</strong>.</p>
<h3>Campos</h3>
<ul>
  <li><strong>Nombre</strong>, <strong>velocidad de bajada y subida</strong>, <strong>precio mensual</strong> y <strong>tipo</strong>.</li>
  <li>Según el tipo aparecen campos específicos: pool PPPoE, usuarios compartidos de HotSpot, tasa PCQ, ráfaga.</li>
</ul>
<h3>Dos opciones importantes</h3>
<ul>
  <li><strong>Plan de cortesía</strong>: los clientes con este plan <strong>nunca se facturan</strong>.</li>
  <li><strong>Primera factura</strong>: define para todos los clientes del plan qué se cobra el mes de instalación y cuántos meses de cortesía siguen.</li>
</ul>
<p><strong>Ejemplo:</strong> el plan "Hogar 100M — instalación con mes de regalo" se configura como <em>Prorrateado + 1 mes de cortesía</em>. Todo cliente que lo contrate hereda esa promoción sin configurarlo uno por uno.</p>
<p>El plan llamado <strong>Gratis</strong> está bloqueado para uso exclusivo de cortesía.</p>',
                    ],
                    [
                        'title' => 'Sectoriales y fibra óptica',
                        'display_order' => 8,
                        'is_published' => true,
                        'tips' => 'Los puertos ocupados nunca se editan: si el número no cuadra, lo que está mal es lo que cuelga de ese elemento, no el contador.',
                        'content' => '<h2>Los elementos físicos de tu red</h2>
<p>Se registran desde <strong>Gestión → Sectoriales</strong>. Cada uno tiene un tipo:</p>
<ul>
  <li><strong>Sectorial</strong>: antena que da cobertura a una zona.</li>
  <li><strong>Nodo</strong>: punto de concentración.</li>
  <li><strong>Switch</strong>: conmutador.</li>
  <li><strong>OLT</strong>: cabecera de fibra óptica.</li>
  <li><strong>Splitter</strong>: divisor óptico.</li>
  <li><strong>NAP</strong>: caja de distribución donde se conectan los clientes.</li>
  <li><strong>Mufa</strong>: empalme.</li>
</ul>
<h3>Topología de fibra</h3>
<p><strong>Gestión → Topología FTTH</strong> muestra el árbol completo: OLT → splitter → NAP → cliente. Para armarlo, al crear un elemento indica cuál es su <strong>elemento padre</strong>.</p>
<ul>
  <li>En un <strong>splitter</strong> no escribes el número de puertos: lo saca de la <strong>relación de división</strong> que le pongas (1:8 son 8 salidas).</li>
  <li>En el resto de elementos sí indicas el total de puertos a mano.</li>
  <li>Los <strong>puertos ocupados se calculan solos</strong> y nunca se editan.</li>
</ul>
<h3>Clientes de fibra</h3>
<p>Un cliente de fibra tiene que estar marcado como fibra. Si le asignas OLT y puerto NAP pero la casilla <em>Es fibra</em> quedó apagada, al abrir <em>Editar</em> verás los campos de fibra vacíos. Hoy el formulario <strong>lo detecta solo</strong> al cargar el cliente.</p>
<h3>Fotos, notas e historial</h3>
<p>Cada elemento tiene tres pestañas: <strong>Fotos</strong> para documentar la instalación en campo, <strong>Notas</strong> de mantenimiento e <strong>Historial</strong> automático de cambios.</p>',
                    ],
                ],
            ],

            // 6. PROSPECTOS E INSTALACIONES
            [
                'name' => 'Prospectos e Instalaciones',
                'icon' => 'bi-clipboard-check',
                'display_order' => 6,
                'articles' => [
                    [
                        'title' => 'Agendar y ejecutar una instalación',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Ya puedes seleccionar todas las fotos juntas: el sistema las comprime en el teléfono y las va enviando una por una solo.',
                        'content' => '<h2>Prospectos e instalaciones</h2>
<p>Un <strong>prospecto</strong> es alguien interesado que todavía no es cliente.</p>
<h3>Agendar</h3>
<ol>
  <li>Ve a <strong>Soporte → Instalaciones</strong>.</li>
  <li>Pulsa <strong>Nueva instalación</strong>.</li>
  <li>Llena los datos de la persona: nombre, cédula, teléfono, dirección, estrato.</li>
  <li>Elige <strong>fecha</strong> y <strong>técnico</strong>.</li>
  <li>Guarda. El prospecto queda en estado <strong>agendado</strong>.</li>
</ol>
<h3>El día de la instalación</h3>
<p>El técnico abre la instalación desde <strong>Soporte → Instalaciones</strong> y allí:</p>
<ol>
  <li><strong>Llena el acta</strong>: equipos instalados, observaciones, mediciones.</li>
  <li><strong>Sube fotos</strong>: puedes seleccionarlas <strong>todas juntas</strong>. El sistema las comprime y las va enviando una por una por su cuenta. Cada foto puede pesar hasta <strong>10 MB</strong> y ser JPG, PNG o WEBP.</li>
  <li><strong>Registra el cobro</strong>: costo de instalación, cargos adicionales, descuento con motivo, forma de pago y cuánto recibió.</li>
  <li><strong>Recoge las firmas</strong>: la del cliente y la del técnico, dibujadas en pantalla.</li>
</ol>
<p>Al completar la instalación se genera automáticamente la <strong>factura de instalación</strong>.</p>
<h3>Convertir el prospecto en cliente</h3>
<ol>
  <li>Crea el cliente normalmente en <strong>Usuarios → Agregar usuario</strong>.</li>
  <li>Vuelve al prospecto y pulsa <strong>Marcar como convertido</strong>, eligiendo el cliente creado.</li>
</ol>',
                    ],
                ],
            ],

            // 7. SOPORTE
            [
                'name' => 'Soporte',
                'icon' => 'bi-headset',
                'display_order' => 7,
                'articles' => [
                    [
                        'title' => 'Crear un ticket de soporte',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Cuanto más detallada sea la descripción, más rápido podrá resolverlo el técnico. Adjunta fotos cuando sea posible.',
                        'content' => '<h2>Crear un ticket de soporte</h2>
<p>Ve a <strong>Soporte → Nuevo Ticket</strong>.</p>'
                            . $this->ticketFormSvg()
                            . '<h3>Cliente <span style="color:#6366f1">①</span></h3>
<p>Selecciona el cliente que reporta el problema buscando por nombre o cédula.</p>
<h3>Prioridad <span style="color:#6366f1">②</span></h3>
<ul>
  <li><strong>Baja</strong>: problema menor, sin urgencia.</li>
  <li><strong>Media</strong>: afecta al cliente pero tiene alternativa.</li>
  <li><strong>Alta</strong>: sin servicio, requiere atención pronto.</li>
  <li><strong>Urgente</strong>: impacto crítico, atención inmediata.</li>
</ul>
<h3>Título <span style="color:#6366f1">③</span></h3>
<p>Breve descripción del problema (ej. "Sin internet desde las 8am").</p>
<h3>Descripción <span style="color:#6366f1">④</span></h3>
<p>Detalla el problema, síntomas e intentos de solución.</p>
<h3>Categoría y sectorial</h3>
<p>Elige la <strong>categoría</strong> (Técnico, Facturación, Servicios, General). Si el problema es de un elemento de red concreto, selecciona el <strong>sectorial</strong> afectado: así queda ligado a la infraestructura.</p>
<h3>Crear <span style="color:#6366f1">⑤</span></h3>
<p>El ticket queda en estado <strong>Abierto</strong>.</p>',
                    ],
                    [
                        'title' => 'Gestionar y responder tickets',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Usa los mensajes internos para comunicarte con otros técnicos sin que el cliente vea la conversación.',
                        'content' => '<h2>Gestión de tickets</h2>
<p>Desde <strong>Soporte</strong> haz clic en cualquier ticket para ver su detalle.</p>
<h3>Dentro del ticket puedes</h3>
<ul>
  <li><strong>Añadir mensajes</strong>. Márcalos como <strong>internos</strong> si no los debe ver el cliente.</li>
  <li><strong>Cambiar el estado</strong>: Abierto → En progreso → Resuelto → Cerrado.</li>
  <li><strong>Adjuntar archivos</strong>.</li>
  <li><strong>Generar un cargo</strong>: si la visita se cobra, esto crea una factura ligada al ticket.</li>
</ul>',
                    ],
                    [
                        'title' => 'Estadísticas de soporte',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'Revisa las estadísticas semanalmente para identificar problemas recurrentes y tomar medidas preventivas.',
                        'content' => '<h2>Estadísticas de soporte</h2>
<p>Accede desde <strong>Soporte → Estadísticas</strong>.</p>
<h3>Métricas</h3>
<ul>
  <li>Tickets por <strong>estado</strong>.</li>
  <li>Tickets por <strong>prioridad</strong>.</li>
  <li>Tickets por <strong>categoría</strong>.</li>
</ul>',
                    ],
                ],
            ],

            // 8. INVENTARIO
            [
                'name' => 'Inventario',
                'icon' => 'bi-box-seam',
                'display_order' => 8,
                'articles' => [
                    [
                        'title' => 'Gestionar dispositivos e inventario',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Registra cada equipo con su serial y su MAC: es lo que permite rastrearlo y controlar garantías.',
                        'content' => '<h2>Módulo de inventario</h2>
<p>Se organiza en cuatro niveles:</p>
<ul>
  <li><strong>Stock / Modelos</strong>: los modelos de equipo que manejas (marca, modelo, precio).</li>
  <li><strong>Proveedores</strong>: a quién le compras, con datos del asesor comercial.</li>
  <li><strong>Sucursales</strong>: dónde están físicamente los equipos.</li>
  <li><strong>Lista de equipos</strong>: <strong>cada equipo individual</strong>, con su serial y su MAC.</li>
</ul>
<h3>Registrar un equipo</h3>
<ol>
  <li>Ve a <strong>Inventarios → Lista de equipos</strong> y pulsa nuevo.</li>
  <li>Ingresa modelo, <strong>serial</strong> y <strong>MAC</strong>.</li>
  <li>Selecciona proveedor y sucursal.</li>
</ol>
<p>Un equipo se puede <strong>asignar a un cliente</strong>.</p>
<h3>Carga masiva</h3>
<p>Para cargar muchos equipos de golpe, ve a <strong>Acciones masivas → Importar inventario</strong>. Cada fila es un equipo con su serial y su MAC; el stock, el proveedor y la sucursal se crean por nombre si no existen.</p>',
                    ],
                    [
                        'title' => 'Proveedores y sucursales',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Crea primero proveedores y sucursales antes de registrar equipos: el formulario los pide.',
                        'content' => '<h2>Proveedores y sucursales</h2>
<h3>Proveedores</h3>
<p>Empresas o personas de quienes adquieres equipos, con los datos del asesor comercial.</p>
<h3>Sucursales</h3>
<p>Bodegas o puntos donde se almacenan físicamente los equipos.</p>
<p>Al registrar un equipo especificas de qué proveedor se adquirió y en qué sucursal está.</p>',
                    ],
                ],
            ],

            // 9. USUARIOS Y ROLES
            [
                'name' => 'Usuarios y Roles',
                'icon' => 'md-adminpanelsettings-round',
                'display_order' => 9,
                'articles' => [
                    [
                        'title' => 'Gestión de personal',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Crea un usuario individual para cada miembro del equipo. Nunca compartas credenciales: perderías la trazabilidad de las acciones.',
                        'content' => '<h2>Gestión de personal</h2>
<p>Usuarios internos que acceden a ISPWatch. Accede desde <strong>Personal</strong>.</p>
<h3>Agregar un empleado</h3>
<ol>
  <li>Ve a <strong>Personal → Nuevo</strong>.</li>
  <li>Llena nombre, correo y contraseña.</li>
  <li>Asigna un <strong>rol</strong>: es lo que determina qué podrá ver y hacer.</li>
  <li>Guarda.</li>
</ol>
<h3>Desactivar un usuario</h3>
<p>Si un empleado deja la empresa, desactiva su cuenta para revocar el acceso inmediatamente.</p>',
                    ],
                    [
                        'title' => 'Roles y permisos',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Cuando el sistema estrena un permiso nuevo, los roles que ya existían NO lo reciben solos. Hay que marcarlo en Roles y volver a entrar.',
                        'content' => '<h2>Roles y permisos</h2>
<p>Un rol es un conjunto de permisos: se crea una vez y se asigna a varias personas. Accede desde <strong>Roles</strong>.</p>
<h3>Todos los permisos, por grupo</h3>
<ul>
  <li><strong>Clientes</strong>: Lista de Clientes · Agregar Clientes · Editar Servicio Internet · Activar y Desactivar Clientes · Editar Descuento · Editar Saldo Pendiente · Eliminar Instalaciones · Tráfico Clientes.</li>
  <li><strong>Facturas</strong>: Dashboard / Estadísticas · Buscar Facturas · Registrar Pagos · Eliminar Factura · Editar Total a Pagar · Agregar Gasto · Promesas de Pago.</li>
  <li><strong>Contabilidad</strong>: Lista de Gastos · Editar Gasto · Lista de Facturas · Registrar Pagos · Editar Fecha de Pago · Registrar Pago Mayor 3 Días · Agregar Transferencia · Eliminar Transferencia.</li>
  <li><strong>Infraestructura</strong>: Gestionar Routers · Ver Planes de Internet · Ver Sectoriales.</li>
  <li><strong>Inventario</strong>: Ver Inventario.</li>
  <li><strong>Soporte</strong>: Ver Soporte Técnico.</li>
  <li><strong>Facturación</strong>: Ver Facturación.</li>
  <li><strong>Sistema</strong>: Ver Personal · Gestionar Roles · Gestionar Configuración de Empresa · Gestionar Plantillas de Documentos · Ver Ajustes del Sistema · Ejecutar Acciones Masivas.</li>
</ul>
<h3>Los roles que trae el sistema</h3>
<ul>
  <li><strong>Administrador</strong>: todo, sin excepción.</li>
  <li><strong>Técnico</strong>: sólo clientes — verlos, agregarlos, editar su servicio, activar/desactivar, ver su tráfico y eliminar instalaciones. <strong>No ve dinero.</strong></li>
  <li><strong>Contabilidad</strong>: todo lo de plata — facturas, pagos, gastos, transferencias y estadísticas. <strong>No gestiona la red</strong> ni el personal.</li>
  <li><strong>Staff</strong>: el operador de mostrador — clientes, planes, sectoriales, inventario, soporte, ver facturación y registrar pagos. <strong>No borra facturas ni toca configuración.</strong></li>
  <li><strong>Cliente</strong>: sin permisos de gestión. Es el rol de los clientes finales.</li>
</ul>
<h3>⚠️ Ojo con "Activar y Desactivar Clientes"</h3>
<p>Ese permiso no sólo cambia un estado en pantalla: <strong>actúa sobre el router de verdad</strong>. Es también el que habilita cargar clientes al equipo. No se lo des a quien no deba tocar la red.</p>
<h3>⚠️ Los permisos nuevos no llegan solos a los roles viejos</h3>
<p>Cuando el sistema estrena un permiso, <strong>los roles que ya existían no lo reciben automáticamente</strong>. Si tras una actualización una pestaña desaparece para los administradores, ve a <strong>Roles</strong>, marca el permiso nuevo, guarda, y pide a los usuarios afectados que <strong>cierren sesión y vuelvan a entrar</strong>.</p>
<h3>Cuándo hace falta volver a entrar</h3>
<p>Si te acaban de cambiar el rol, <strong>recarga la página</strong>: normalmente basta. Si sigues sin ver lo que deberías, cierra sesión y vuelve a entrar. Y si aun así no aparece, es que el permiso no está marcado en tu rol.</p>',
                    ],
                ],
            ],

            // 10. CONFIGURACIÓN
            [
                'name' => 'Configuración',
                'icon' => 'ri-settings-4-line',
                'display_order' => 10,
                'articles' => [
                    [
                        'title' => 'Configuración general del sistema',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Los datos de la empresa aparecen en TODAS las facturas y contratos. Revísalos bien antes de emitir el primer documento.',
                        'content' => '<h2>Configuración general</h2>
<p>Accede desde <strong>Configuración</strong>.</p>
<h3>Datos de la empresa</h3>
<p>Razón social, nombre comercial, NIT y dígito de verificación, régimen tributario, actividad económica, dirección, ciudad, departamento, teléfono y correo de facturación.</p>
<p><strong>Todo esto aparece en las facturas y contratos</strong>, así que revísalo bien.</p>
<h3>Marca</h3>
<p>Logo y color corporativo. Se aplican a los documentos que genera el sistema.</p>
<h3>Mapas</h3>
<p>Clave de Google Maps para el mapa de clientes. <strong>La clave se guarda cifrada y nunca se muestra de vuelta.</strong></p>',
                    ],
                    [
                        'title' => 'Plantillas de documentos',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Si escribes un marcador que no existe, o el de otro tipo de documento, simplemente no aparece nada ahí y no da ningún aviso. Revisa bien el nombre exacto antes de guardar.',
                        'content' => '<h2>Editar los documentos que genera el sistema</h2>
<p>En la pestaña <strong>Plantillas</strong> puedes editar el contenido de tres documentos:</p>
<ul>
  <li><strong>Factura</strong>: cuerpo de la factura en PDF.</li>
  <li><strong>Contrato</strong>: contrato de servicio que firma el cliente.</li>
  <li><strong>Instalación</strong>: acta de instalación.</li>
</ul>
<h3>Marcadores</h3>
<p>Se editan con un editor de texto enriquecido. Puedes insertar <strong>marcadores</strong> que el sistema reemplaza por datos reales (nombre del cliente, plan, monto).</p>
<p>⚠️ Si escribes un marcador que <strong>no existe</strong>, o el de <strong>otro tipo de documento</strong> por error (por ejemplo uno de factura dentro de la plantilla de Contrato), simplemente no aparece nada ahí — <strong>no da ningún aviso de error</strong>.</p>
<h3>Bloques de contenido</h3>
<p>Además de los marcadores de texto hay marcadores especiales que insertan contenido más complejo: la tabla de ítems de la factura, la galería de fotos de la instalación, y las firmas del cliente y del técnico. Se insertan con botones aparte y el sistema los coloca en su propio párrafo.</p>
<p>Si uno de estos bloques no se pudo insertar donde lo pusiste, la <strong>Vista previa</strong> te avisa con un mensaje explícito — a diferencia de los marcadores de texto simples, que se quedan callados.</p>
<h3>Vista previa y restaurar</h3>
<p><strong>Vista previa</strong> muestra cómo queda con datos de ejemplo. <strong>Restaurar</strong> vuelve a la plantilla original sin perder tu borrador.</p>
<h3>Modo avanzado</h3>
<p>Un interruptor cambia a un modo donde editas el documento HTML completo (diseño y colores, no sólo el texto) en un cuadro de texto plano. El sistema sigue revisando el contenido por seguridad, así que no todo lo que escribas va a sobrevivir tal cual: usa <strong>Vista previa</strong> antes de guardar.</p>
<p><strong>Si no sabes HTML/CSS, no actives este modo</strong>: no hay ayuda visual, es edición de código.</p>
<h3>Permiso</h3>
<p>Esta pestaña necesita el permiso <em>Gestionar Plantillas de Documentos</em>, que es <strong>distinto</strong> del de configuración de empresa.</p>',
                    ],
                ],
            ],

            // 11. ACCIONES MASIVAS
            [
                'name' => 'Acciones Masivas',
                'icon' => 'vi-file-type-excel',
                'display_order' => 11,
                'articles' => [
                    [
                        'title' => 'Importación masiva desde Excel',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Si hay errores, descarga el archivo de errores, corrígelo y vuelve a subir SOLO lo que falló.',
                        'content' => '<h2>Importación masiva</h2>
<p>Carga grandes volúmenes de datos desde Excel. Accede desde <strong>Acciones masivas</strong>.</p>
<h3>Pasos</h3>
<ol>
  <li>Pulsa <strong>Descargar plantilla</strong> — trae hojas para clientes, planes, routers y sectoriales.</li>
  <li>Completa el archivo sin modificar los encabezados. La opción <em>Ver documentación de campos</em> explica qué va en cada columna.</li>
  <li>Súbelo y pulsa <strong>Importar</strong>.</li>
  <li>Revisa el resumen. Si hay errores, descarga el archivo de errores, corrígelos y vuelve a importar sólo los fallidos.</li>
</ol>
<h3>Reglas importantes</h3>
<ul>
  <li>No modifiques los nombres de columnas ni las hojas.</li>
  <li>Los planes, routers y sectoriales se resuelven <strong>por nombre</strong>: si no existe, <strong>se crea</strong>. Cuida las mayúsculas y los espacios o acabarás con catálogos duplicados.</li>
  <li>Fechas en formato <strong>YYYY-MM-DD</strong>.</li>
</ul>
<h3>Otras importaciones</h3>
<ul>
  <li><strong>Actualización masiva de clientes</strong>: mismo flujo, pero la plantilla <strong>modifica</strong> clientes existentes en vez de crearlos.</li>
  <li><strong>Importar inventario</strong>: cada fila es un equipo con su serial y su MAC.</li>
</ul>',
                    ],
                    [
                        'title' => 'Aprovisionamiento masivo y paneles de reintentos',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Puedes cerrar la pantalla mientras el aprovisionamiento masivo corre: no se cancela.',
                        'content' => '<h2>Aprovisionamiento masivo</h2>
<p>Carga a los routers a varios clientes de golpe. Como cada cliente tarda alrededor de medio minuto, el proceso corre <strong>en segundo plano</strong> y verás una barra de progreso. Puedes cerrar la pantalla y seguir trabajando.</p>
<p>Vale lo mismo que en el alta individual: los routers que tengan <strong>apagada el alta automática</strong> se saltan, y los clientes sin router, plan o IP no se pueden aprovisionar.</p>
<h2>Paneles de reintentos</h2>
<ul>
  <li><strong>Bitácora de facturación</strong>: facturas que no se pudieron crear, con el error y el número de intentos.</li>
  <li><strong>Bitácora de cortes</strong>: cortes y reconexiones que fallaron en el equipo, con el botón <strong>Reconciliar</strong>.</li>
</ul>
<p>En ambos puedes pulsar <strong>Reintentar</strong> sobre una fila o <strong>Reintentar todo</strong>.</p>',
                    ],
                ],
            ],
        ];
    }
}
