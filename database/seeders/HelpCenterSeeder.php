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
                        'tips' => 'ISPWatch está diseñado para ISPs pequeños y medianos. Cada empresa tiene su propio espacio (tenant) completamente aislado del resto.',
                        'content' => '<h2>Bienvenido a ISPWatch</h2>
<p>ISPWatch es una plataforma de gestión integral para Proveedores de Servicios de Internet (ISP). Centraliza en un solo lugar todo lo necesario para administrar tu negocio:</p>
<ul>
  <li><strong>Clientes</strong>: registro, mapa geográfico, suspensión y reactivación automática.</li>
  <li><strong>Facturación</strong>: generación automática de facturas, registro de pagos y recordatorios.</li>
  <li><strong>Routers MikroTik</strong>: integración directa vía API y SSH para aprovisionar y cortar servicios.</li>
  <li><strong>Soporte</strong>: sistema de tickets con mensajes, archivos adjuntos y estadísticas.</li>
  <li><strong>Inventario</strong>: control de equipos, stock, proveedores y sucursales.</li>
  <li><strong>Roles y permisos</strong>: control granular de acceso para cada miembro del equipo.</li>
</ul>
<h3>Arquitectura multi-tenant</h3>
<p>Cada empresa registrada opera en un entorno completamente aislado. Tus datos nunca son visibles para otras empresas.</p>
<h3>Acceso desde cualquier lugar</h3>
<p>ISPWatch es una aplicación web moderna. Puedes acceder desde cualquier navegador actualizado (Chrome, Firefox, Edge) en computadora, tablet o móvil.</p>',
                    ],
                    [
                        'title' => 'Cómo iniciar sesión',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Si olvidaste tu contraseña, contacta a tu administrador para que te restablezca el acceso.',
                        'content' => '<h2>Inicio de sesión</h2>
<p>Para acceder a ISPWatch necesitas las credenciales que te proporcionó tu administrador.</p>
<h3>Pasos para ingresar</h3>
<ol>
  <li>Abre tu navegador y dirígete a la URL de tu instancia de ISPWatch.</li>
  <li>Ingresa tu <strong>correo electrónico</strong> y <strong>contraseña</strong>.</li>
  <li>Haz clic en <strong>Iniciar Sesión</strong>.</li>
  <li>Si es la primera vez, verifica tu correo electrónico antes de ingresar.</li>
</ol>
<h3>Verificación de correo</h3>
<p>Al registrarte recibirás un correo de verificación. Debes hacer clic en el enlace para activar tu cuenta. Si no lo recibiste, usa la opción <em>"Reenviar verificación"</em> en la pantalla de login.</p>
<h3>Recordar sesión</h3>
<p>Marca <strong>"Recordarme"</strong> si accedes desde un equipo de confianza para mantener tu sesión activa por más tiempo.</p>',
                    ],
                    [
                        'title' => 'Entendiendo el Dashboard',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'El dashboard se actualiza cada vez que lo visitas. Úsalo como punto de partida para revisar el estado general de tu empresa.',
                        'content' => '<h2>El Dashboard principal</h2>
<p>Al ingresar verás el <strong>Dashboard</strong> — tu panel de control con un resumen visual de los indicadores más importantes.</p>'
                            . $this->dashboardSvg()
                            . '<h3>Tarjetas de resumen <span style="color:#6366f1">①</span></h3>
<ul>
  <li><strong>Clientes Activos</strong>: total con servicio vigente.</li>
  <li><strong>Suspendidos</strong>: clientes con servicio cortado por mora.</li>
  <li><strong>Facturas Pendientes</strong>: monto total adeudado.</li>
  <li><strong>Ingresos del Mes</strong>: pagos registrados en el mes actual.</li>
  <li><strong>Routers Online</strong>: estado de conectividad de tus routers.</li>
  <li><strong>Tickets Abiertos</strong>: tickets de soporte sin resolver.</li>
</ul>
<h3>Menú lateral <span style="color:#6366f1">②</span></h3>
<p>Accede a todos los módulos desde la barra izquierda. El ítem resaltado indica la sección activa.</p>
<h3>Actividad reciente <span style="color:#6366f1">③</span></h3>
<p>La tabla inferior muestra las últimas facturas generadas con su estado (Pendiente, Pagada, Vencida).</p>',
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
                        'tips' => 'Completa siempre el campo de router asignado. Sin él, el sistema no puede aprovisionar ni cortar el servicio automáticamente.',
                        'content' => '<h2>Registrar un cliente</h2>
<p>Ve a <strong>Gestión → Clientes</strong> y haz clic en <strong>Nuevo Cliente</strong>.</p>'
                            . $this->customerFormSvg()
                            . '<h3>Datos personales <span style="color:#6366f1">①</span></h3>
<ul>
  <li><strong>Nombre completo</strong>: nombre y apellidos del titular.</li>
  <li><strong>Cédula / DNI</strong>: documento de identidad.</li>
  <li><strong>Correo electrónico</strong>: para facturas y notificaciones.</li>
  <li><strong>Teléfono / WhatsApp</strong>: para recordatorios de pago.</li>
  <li><strong>Dirección</strong>: dirección física del cliente.</li>
</ul>
<h3>Servicio y red <span style="color:#6366f1">②</span></h3>
<ul>
  <li><strong>Plan</strong>: el plan contratado (velocidad y precio).</li>
  <li><strong>Router</strong>: el MikroTik que dará servicio al cliente.</li>
  <li><strong>IP</strong>: déjala en blanco para asignación automática.</li>
  <li><strong>Sectorial</strong>: zona geográfica del cliente.</li>
</ul>
<h3>Ubicación GPS <span style="color:#6366f1">③</span></h3>
<p>Haz clic en el mapa para marcar la ubicación exacta. Las coordenadas se guardan y aparecerán en el mapa de clientes.</p>
<h3>Guardar <span style="color:#6366f1">④</span></h3>
<p>Al guardar, el sistema crea el perfil y aprovisiona automáticamente la cola o perfil PPPoE en MikroTik.</p>',
                    ],
                    [
                        'title' => 'Editar y gestionar un cliente',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Puedes cambiar el plan de un cliente en cualquier momento. El sistema actualizará la cola en MikroTik automáticamente.',
                        'content' => '<h2>Editar un cliente existente</h2>
<p>Desde <strong>Gestión → Clientes</strong>, haz clic en el ícono de edición junto al cliente.</p>
<h3>Estado del cliente</h3>
<ul>
  <li><strong>Activo</strong>: servicio vigente.</li>
  <li><strong>Suspendido</strong>: cortado por mora, IP bloqueada en el firewall.</li>
  <li><strong>Inactivo</strong>: dado de baja definitiva.</li>
</ul>
<h3>Documentos</h3>
<p>Sube contratos firmados, identificaciones y otros archivos desde la pestaña de documentos del cliente.</p>
<h3>Instalaciones</h3>
<p>Registra visitas técnicas al domicilio: fecha, técnico asignado, descripción y estado.</p>',
                    ],
                    [
                        'title' => 'Suspender y reactivar clientes',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'La suspensión automática corre una vez al día. Al registrar el pago de un cliente suspendido, la reactivación ocurre en segundos.',
                        'content' => '<h2>Suspensión y reactivación</h2>
<p>ISPWatch corta el servicio de clientes morosos bloqueando su IP en MikroTik.</p>'
                            . $this->suspensionFlowSvg()
                            . '<h3>Suspensión manual</h3>
<ol>
  <li>Ve a <strong>Gestión → Clientes</strong>.</li>
  <li>Localiza al cliente y haz clic en el ícono de corte.</li>
  <li>Confirma la acción.</li>
</ol>
<h3>Suspensión automática</h3>
<p>El scheduler diario busca facturas vencidas y envía el comando de bloqueo al router de cada cliente moroso, registrando la acción en el log.</p>
<h3>Reactivación</h3>
<p>Al registrar el pago, el sistema envía automáticamente el comando de desbloqueo y actualiza el estado del cliente a <strong>Activo</strong>.</p>',
                    ],
                    [
                        'title' => 'Mapa de clientes',
                        'display_order' => 4,
                        'is_published' => true,
                        'tips' => 'Para el mapa satelital necesitas configurar tu clave de Google Maps en Configuración → Tenant.',
                        'content' => '<h2>Mapa geográfico de clientes</h2>
<p>Accede desde <strong>Gestión → Clientes → Mapa</strong> para visualizar todos tus clientes en un mapa interactivo.</p>
<h3>Funcionalidades</h3>
<ul>
  <li><strong>Marcadores por estado</strong>: verde = activo, rojo = suspendido.</li>
  <li><strong>Popup informativo</strong>: clic en un marcador muestra nombre, plan, router y estado.</li>
  <li><strong>Filtros</strong>: filtra por sectorial, router o estado.</li>
  <li><strong>Capas</strong>: alterna entre vista de calle y satelital.</li>
</ul>
<h3>Agregar coordenadas</h3>
<p>Al crear o editar un cliente, haz clic en el mapa del formulario para guardar su ubicación GPS automáticamente.</p>',
                    ],
                    [
                        'title' => 'Importar clientes desde Excel',
                        'display_order' => 5,
                        'is_published' => true,
                        'tips' => 'Descarga siempre la plantilla oficial antes de importar. No modifiques los encabezados de columna.',
                        'content' => '<h2>Importación masiva de clientes</h2>
<p>Carga grandes bases de datos desde Excel en <strong>Acciones Masivas</strong>.</p>
<h3>Pasos</h3>
<ol>
  <li>Haz clic en <strong>Descargar Plantilla</strong>.</li>
  <li>Llena el archivo con los datos de tus clientes.</li>
  <li>Arrastra el archivo al área de carga.</li>
  <li>Haz clic en <strong>Importar</strong>.</li>
</ol>
<h3>Resultado</h3>
<p>El sistema muestra registros importados y errores. Descarga el archivo de errores para corregirlos y reimportar solo los fallidos.</p>',
                    ],
                    [
                        'title' => 'Estadísticas de clientes',
                        'display_order' => 6,
                        'is_published' => true,
                        'tips' => 'Usa las estadísticas para identificar qué sectoriales o planes tienen más morosos.',
                        'content' => '<h2>Estadísticas de clientes</h2>
<p>Accede desde <strong>Gestión → Clientes → Estadísticas</strong>.</p>
<h3>Métricas disponibles</h3>
<ul>
  <li><strong>Distribución por estado</strong>: activos, suspendidos, inactivos.</li>
  <li><strong>Clientes por plan</strong>: cuántos clientes tiene cada plan.</li>
  <li><strong>Clientes por sectorial</strong>: distribución geográfica.</li>
  <li><strong>Clientes por router</strong>: carga en cada MikroTik.</li>
  <li><strong>Crecimiento mensual</strong>: nuevos clientes mes a mes.</li>
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
                        'tips' => 'Las facturas se generan una sola vez por mes por cliente (proceso idempotente). Puedes ejecutar el proceso dos veces sin generar duplicados.',
                        'content' => '<h2>Facturación automática mensual</h2>
<p>Cada router tiene un <strong>día de facturación</strong> configurado. Ese día el sistema genera automáticamente una factura para cada cliente activo del router.</p>
<h3>Configurar el día de facturación</h3>
<ol>
  <li>Ve a <strong>Gestión → Routers → Editar Router</strong>.</li>
  <li>Ingresa el número del día del mes en <strong>Día de facturación</strong>.</li>
</ol>
<h3>¿Qué incluye cada factura?</h3>
<ul>
  <li>Datos del cliente y del ISP.</li>
  <li>Plan de servicio y precio.</li>
  <li>Número correlativo único.</li>
  <li>Fecha de emisión y vencimiento.</li>
  <li>Cargos adicionales si los hubiera.</li>
</ul>
<h3>Planes cortesía</h3>
<p>Los clientes con planes marcados como <strong>Cortesía</strong> se excluyen automáticamente del proceso de facturación.</p>',
                    ],
                    [
                        'title' => 'Registrar un pago',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Si el cliente paga más de lo adeudado, el excedente se guarda como saldo a favor y se aplica en la siguiente factura.',
                        'content' => '<h2>Registro de pagos</h2>
<p>Ve a <strong>Finanzas → Pagos → Nuevo Pago</strong>.</p>'
                            . $this->paymentFormSvg()
                            . '<h3>Buscar cliente <span style="color:#6366f1">①</span></h3>
<p>Escribe el nombre o cédula del cliente. El sistema cargará sus facturas pendientes automáticamente.</p>
<h3>Monto <span style="color:#6366f1">②</span></h3>
<p>Ingresa el valor exacto recibido. Puede ser parcial o cubrir varias facturas.</p>
<h3>Método de pago <span style="color:#6366f1">③</span></h3>
<p>Selecciona la forma de pago (efectivo, transferencia, Nequi, etc.).</p>
<h3>Facturas a cubrir <span style="color:#6366f1">④</span></h3>
<p>El sistema asigna el pago a las facturas más antiguas primero. Si el monto cubre todas, quedan marcadas como pagadas.</p>
<h3>Registrar <span style="color:#6366f1">⑤</span></h3>
<p>Al confirmar, si el cliente estaba suspendido se envía el comando de reactivación al router MikroTik.</p>',
                    ],
                    [
                        'title' => 'Ver y descargar facturas',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'Filtra las facturas por estado, cliente o rango de fechas para encontrar lo que buscas rápidamente.',
                        'content' => '<h2>Listado y detalle de facturas</h2>
<p>Accede desde <strong>Finanzas → Facturas</strong>.</p>
<h3>Estados de factura</h3>
<ul>
  <li><strong>Pendiente</strong>: generada pero no pagada, dentro del plazo.</li>
  <li><strong>Pagada</strong>: cubierta total o parcialmente.</li>
  <li><strong>Vencida</strong>: no pagada y fuera del plazo de vencimiento.</li>
</ul>
<h3>Desde el detalle de la factura puedes</h3>
<ul>
  <li><strong>Descargar PDF</strong>: genera el comprobante oficial para enviar al cliente.</li>
  <li><strong>Ver pagos aplicados</strong>: qué pagos han cubierto esta factura.</li>
  <li><strong>Agregar cargo adicional</strong>: instalación, equipo, otros cobros.</li>
</ul>',
                    ],
                    [
                        'title' => 'Recordatorios de pago',
                        'display_order' => 4,
                        'is_published' => true,
                        'tips' => 'Activa los recordatorios en la configuración de facturación. Se envían X días antes del vencimiento por email y/o WhatsApp.',
                        'content' => '<h2>Recordatorios de pago automáticos</h2>
<h3>Configurar</h3>
<ol>
  <li>Ve a <strong>Finanzas → Facturación</strong>.</li>
  <li>Activa <strong>Recordatorios de pago</strong>.</li>
  <li>Indica los días antes del vencimiento.</li>
  <li>Elige el canal: <strong>Email</strong>, <strong>WhatsApp</strong> o ambos.</li>
</ol>
<h3>Canales</h3>
<ul>
  <li><strong>Email</strong>: correo al cliente con el detalle de la factura.</li>
  <li><strong>WhatsApp</strong>: mensaje al número registrado vía API.</li>
</ul>
<h3>Envío manual</h3>
<p>Desde el detalle de cualquier factura haz clic en <strong>Enviar Recordatorio</strong> para notificar al cliente en ese momento.</p>',
                    ],
                    [
                        'title' => 'Cargos adicionales',
                        'display_order' => 5,
                        'is_published' => true,
                        'tips' => 'Los cargos también pueden generarse desde un ticket de soporte cuando el técnico registra materiales o mano de obra.',
                        'content' => '<h2>Cargos adicionales en facturas</h2>
<p>Agrega cobros extra a facturas: instalaciones, reparaciones, equipos u otros servicios.</p>
<h3>Agregar un cargo</h3>
<ol>
  <li>Abre el detalle de la factura (<strong>Finanzas → Facturas → [Factura]</strong>).</li>
  <li>Clic en <strong>Agregar Cargo Adicional</strong>.</li>
  <li>Escribe la descripción y el monto.</li>
  <li>Guarda — el cargo se suma al total.</li>
</ol>
<h3>Desde un ticket de soporte</h3>
<p>Al cerrar el ticket, el técnico puede registrar materiales usados. El sistema genera automáticamente el cargo adicional en la factura activa del cliente.</p>',
                    ],
                    [
                        'title' => 'Métodos de pago',
                        'display_order' => 6,
                        'is_published' => true,
                        'tips' => 'Configura todos los métodos antes de empezar a registrar pagos: efectivo, transferencia, Nequi, PSE, etc.',
                        'content' => '<h2>Métodos de pago</h2>
<p>Administra las formas de pago aceptadas desde <strong>Finanzas → Métodos de Pago</strong>.</p>
<h3>Crear un método</h3>
<ol>
  <li>Clic en <strong>Nuevo Método de Pago</strong>.</li>
  <li>Ingresa el nombre y descripción opcional.</li>
  <li>Guarda — quedará disponible al registrar pagos.</li>
</ol>',
                    ],
                    [
                        'title' => 'Dashboard de facturación',
                        'display_order' => 7,
                        'is_published' => true,
                        'tips' => 'Revisa el dashboard de facturación antes de hacer pagos a proveedores para tener claridad del flujo de caja.',
                        'content' => '<h2>Dashboard de facturación</h2>
<p>Accede desde <strong>Finanzas → Facturación</strong>.</p>
<h3>Indicadores</h3>
<ul>
  <li><strong>Ingresos del mes</strong>: total cobrado en el mes actual.</li>
  <li><strong>Facturas pendientes</strong>: monto total adeudado.</li>
  <li><strong>Facturas vencidas</strong>: cuya fecha de pago ya pasó.</li>
  <li><strong>Tasa de cobranza</strong>: % de facturas pagadas vs. emitidas.</li>
  <li><strong>Gráfica mensual</strong>: evolución de ingresos mes a mes.</li>
</ul>',
                    ],
                ],
            ],

            // 4. ROUTERS Y RED
            [
                'name' => 'Routers y Red',
                'icon' => 'bi-router',
                'display_order' => 4,
                'articles' => [
                    [
                        'title' => 'Agregar un router MikroTik',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'El router debe tener habilitada la API (puerto 8728) y SSH (puerto 22). El usuario debe tener permisos de lectura y escritura en MikroTik.',
                        'content' => '<h2>Conectar un router MikroTik</h2>
<p>Ve a <strong>Gestión → Routers → Agregar Router</strong>.</p>'
                            . $this->routerFormSvg()
                            . '<h3>Datos de conexión <span style="color:#6366f1">①</span></h3>
<ul>
  <li><strong>IP / Hostname</strong>: dirección accesible desde el servidor.</li>
  <li><strong>Puerto API</strong>: 8728 (sin SSL) o 8729 (con SSL).</li>
  <li><strong>Puerto SSH</strong>: 22 por defecto.</li>
  <li><strong>Usuario y contraseña</strong>: credenciales del usuario API de MikroTik.</li>
</ul>
<h3>Facturación <span style="color:#6366f1">②</span></h3>
<ul>
  <li><strong>Día de facturación</strong>: día del mes en que se generan las facturas.</li>
  <li><strong>Días de gracia</strong>: días adicionales antes del corte automático.</li>
</ul>
<h3>Verificar conexión <span style="color:#6366f1">③</span></h3>
<p>Después de guardar, usa este botón para confirmar que ISPWatch puede comunicarse con el router por API y SSH.</p>',
                    ],
                    [
                        'title' => 'Verificar y gestionar routers',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Si un router aparece desconectado, verifica que la IP sea accesible y que el firewall no bloquee los puertos 8728 y 22.',
                        'content' => '<h2>Gestión de routers</h2>
<p>Desde <strong>Gestión → Routers</strong> ves todos tus routers con su estado de conectividad.</p>
<h3>Estados</h3>
<ul>
  <li><strong>Conectado</strong> (verde): ISPWatch se comunica correctamente.</li>
  <li><strong>Desconectado</strong> (rojo): sin comunicación. Verifica IP, credenciales y firewall.</li>
</ul>
<h3>Acciones disponibles</h3>
<ul>
  <li><strong>Verificar conexión</strong>: prueba en tiempo real.</li>
  <li><strong>Sincronizar colas</strong>: compara colas en MikroTik vs. ISPWatch.</li>
  <li><strong>Asignar IP libre</strong>: consulta IPs disponibles en el pool.</li>
  <li><strong>Generar script VPN</strong>: configura el túnel L2TP/IPSec.</li>
</ul>',
                    ],
                    [
                        'title' => 'Planes de servicio',
                        'display_order' => 3,
                        'is_published' => true,
                        'tips' => 'El nombre del plan debe coincidir exactamente con el perfil en MikroTik para que el aprovisionamiento automático funcione.',
                        'content' => '<h2>Planes de servicio</h2>
<p>Define las velocidades y precios desde <strong>Gestión → Planes → Nuevo Plan</strong>.</p>
<h3>Campos principales</h3>
<ul>
  <li><strong>Nombre</strong>: debe coincidir con el perfil en MikroTik.</li>
  <li><strong>Velocidad bajada / subida</strong>.</li>
  <li><strong>Precio mensual</strong>.</li>
  <li><strong>Tipo</strong>: Queue, PPPoE, Hotspot o PCQ.</li>
  <li><strong>Plan Cortesía</strong>: actívalo para planes gratuitos (no generan facturas).</li>
</ul>',
                    ],
                    [
                        'title' => 'Sectoriales',
                        'display_order' => 4,
                        'is_published' => true,
                        'tips' => 'Organiza tus sectoriales antes de registrar clientes. Cambiar el sectorial de muchos clientes después es más laborioso.',
                        'content' => '<h2>Sectoriales — zonas geográficas</h2>
<p>Organiza tus clientes por área geográfica desde <strong>Gestión → Sectoriales</strong>.</p>
<h3>Crear una sectorial</h3>
<ol>
  <li>Clic en <strong>Nueva Sectorial</strong>.</li>
  <li>Ingresa el nombre (ej. "Barrio Norte", "Zona Industrial").</li>
  <li>Asigna un técnico responsable si aplica.</li>
  <li>Guarda.</li>
</ol>
<h3>Uso</h3>
<p>Las sectoriales se usan en el formulario de clientes, el mapa, las estadísticas y la importación masiva.</p>',
                    ],
                ],
            ],

            // 5. SOPORTE
            [
                'name' => 'Soporte',
                'icon' => 'bi-headset',
                'display_order' => 5,
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
<p>Detalla el problema, síntomas, intentos de solución y cualquier información relevante para el técnico.</p>
<h3>Crear <span style="color:#6366f1">⑤</span></h3>
<p>El técnico asignado recibirá una notificación y el ticket quedará en estado <strong>Abierto</strong>.</p>',
                    ],
                    [
                        'title' => 'Gestionar y responder tickets',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Usa los mensajes internos para comunicarte con otros técnicos sin que el cliente vea la conversación.',
                        'content' => '<h2>Gestión de tickets</h2>
<p>Desde <strong>Soporte</strong> haz clic en cualquier ticket para ver su detalle.</p>
<h3>Responder</h3>
<ol>
  <li>Escribe tu respuesta en el área de mensajes.</li>
  <li>Marca <strong>Mensaje Interno</strong> si es solo para el equipo técnico.</li>
  <li>Clic en <strong>Enviar</strong>.</li>
</ol>
<h3>Cambiar estado</h3>
<ul>
  <li><strong>Abierto</strong> → <strong>En Progreso</strong> → <strong>Resuelto</strong> → <strong>Cerrado</strong>.</li>
</ul>
<h3>Cargo por ticket</h3>
<p>Al cerrar, puedes generar un cargo adicional en la factura del cliente por materiales o mano de obra.</p>',
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
  <li>Tickets por estado y prioridad.</li>
  <li>Tiempo promedio de resolución.</li>
  <li>Tickets por técnico (carga de trabajo).</li>
  <li>Categorías más frecuentes.</li>
  <li>Evolución mensual: abiertos vs. resueltos.</li>
</ul>',
                    ],
                ],
            ],

            // 6. INVENTARIO
            [
                'name' => 'Inventario',
                'icon' => 'bi-box-seam',
                'display_order' => 6,
                'articles' => [
                    [
                        'title' => 'Gestionar dispositivos e inventario',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Registra cada equipo con su número de serie para facilitar el control de garantías y su ubicación en caso de daño.',
                        'content' => '<h2>Módulo de inventario</h2>
<p>Controla equipos y materiales desde <strong>Inventarios → Dispositivos</strong>.</p>
<h3>Registrar un dispositivo</h3>
<ol>
  <li>Clic en <strong>Nuevo Dispositivo</strong>.</li>
  <li>Ingresa nombre, modelo, número de serie y categoría.</li>
  <li>Selecciona proveedor y sucursal.</li>
  <li>Indica precio de compra y stock inicial.</li>
</ol>
<h3>Control de stock</h3>
<p>En <strong>Inventarios → Stock</strong> registra entradas (compras) y salidas (instalaciones) y consulta el historial de movimientos.</p>',
                    ],
                    [
                        'title' => 'Proveedores y sucursales',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Crea primero proveedores y sucursales antes de registrar dispositivos, ya que el formulario los requiere.',
                        'content' => '<h2>Proveedores y sucursales</h2>
<h3>Proveedores — <em>Inventarios → Proveedores</em></h3>
<p>Empresas o personas de quienes adquieres equipos. Datos: nombre, RUC/NIT, contacto, teléfono y correo.</p>
<h3>Sucursales — <em>Inventarios → Sucursales</em></h3>
<p>Bodegas o puntos de almacenamiento. Datos: nombre, dirección y ciudad.</p>
<p>Al registrar dispositivos especifica de qué proveedor se adquirió y en qué sucursal se almacena.</p>',
                    ],
                ],
            ],

            // 7. USUARIOS Y ROLES
            [
                'name' => 'Usuarios y Roles',
                'icon' => 'md-adminpanelsettings-round',
                'display_order' => 7,
                'articles' => [
                    [
                        'title' => 'Gestión de staff',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Crea un usuario individual para cada miembro del equipo. Nunca compartas credenciales: perderías trazabilidad de las acciones.',
                        'content' => '<h2>Gestión de staff</h2>
<p>Usuarios internos que acceden a ISPWatch. Accede desde <strong>Usuarios → Staff</strong>.</p>
<h3>Agregar un usuario</h3>
<ol>
  <li>Ve a <strong>Usuarios → Staff → Nuevo Staff</strong>.</li>
  <li>Ingresa nombre, apellido y correo.</li>
  <li>Asigna un <strong>rol</strong> que define sus permisos.</li>
  <li>Guarda — el usuario recibirá su acceso por correo.</li>
</ol>
<h3>Desactivar un usuario</h3>
<p>Si un empleado deja la empresa, desactiva su cuenta para revocar el acceso inmediatamente.</p>',
                    ],
                    [
                        'title' => 'Roles y permisos',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Crea roles específicos por cargo: "Técnico", "Cobrador", "Administrador". Asigna solo los permisos que cada rol realmente necesita.',
                        'content' => '<h2>Roles y permisos</h2>
<p>Un rol es un conjunto de permisos. Se crea una vez y se asigna a múltiples usuarios. Accede desde <strong>Usuarios → Roles</strong>.</p>
<h3>Crear un rol</h3>
<ol>
  <li>Clic en <strong>Nuevo Rol</strong>.</li>
  <li>Ingresa el nombre (ej. "Técnico de campo").</li>
  <li>Marca los permisos necesarios.</li>
  <li>Guarda.</li>
</ol>
<h3>Permisos por módulo</h3>
<ul>
  <li><strong>Clientes</strong>: ver, crear, editar, suspender.</li>
  <li><strong>Facturación</strong>: ver facturas, registrar pagos.</li>
  <li><strong>Soporte</strong>: ver, crear, responder, cerrar tickets.</li>
  <li><strong>Inventario</strong>: ver, crear y editar dispositivos.</li>
  <li><strong>Routers</strong>: ver, agregar, editar y ejecutar acciones.</li>
  <li><strong>Usuarios</strong>: gestionar staff y roles.</li>
  <li><strong>Configuración</strong>: acceso a ajustes del tenant.</li>
</ul>',
                    ],
                ],
            ],

            // 8. CONFIGURACIÓN
            [
                'name' => 'Configuración',
                'icon' => 'ri-settings-4-line',
                'display_order' => 8,
                'articles' => [
                    [
                        'title' => 'Configuración general del sistema',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'Configura el nombre y logo de tu empresa para que aparezcan en las facturas PDF y en los correos enviados a tus clientes.',
                        'content' => '<h2>Configuración general</h2>
<p>Accede desde <strong>Configuración</strong>. Solo usuarios con permiso <strong>manage_tenant</strong> pueden modificar estas opciones.</p>
<h3>Datos de la empresa</h3>
<ul>
  <li><strong>Nombre</strong>, <strong>RUC/NIT</strong>, <strong>Dirección</strong>, <strong>Teléfono</strong>, <strong>Correo</strong>: aparecen en facturas y correos.</li>
  <li><strong>Logo</strong>: imagen en el encabezado de facturas PDF.</li>
</ul>
<h3>Google Maps</h3>
<ol>
  <li>Obtén tu clave en <em>console.cloud.google.com</em>.</li>
  <li>Pégala en <strong>Clave de Google Maps</strong>.</li>
  <li>Guarda — el mapa de clientes quedará habilitado.</li>
</ol>
<h3>Limpiar caché</h3>
<p>Si los cambios no se reflejan de inmediato, usa <strong>Limpiar Caché</strong> para forzar la actualización.</p>',
                    ],
                ],
            ],

            // 9. ACCIONES MASIVAS
            [
                'name' => 'Acciones Masivas',
                'icon' => 'vi-file-type-excel',
                'display_order' => 9,
                'articles' => [
                    [
                        'title' => 'Importación masiva desde Excel',
                        'display_order' => 1,
                        'is_published' => true,
                        'tips' => 'La plantilla unificada acepta clientes, planes, routers y sectoriales en hojas separadas. Llena solo las que necesites.',
                        'content' => '<h2>Importación masiva</h2>
<p>Carga grandes volúmenes de datos desde Excel. Accede desde <strong>Acciones Masivas</strong>.</p>
<h3>Pasos</h3>
<ol>
  <li>Clic en <strong>Descargar Plantilla</strong> — contiene hojas para Clientes, Planes, Routers y Sectoriales.</li>
  <li>Completa el archivo sin modificar los encabezados.</li>
  <li>Arrastra el archivo o haz clic en <strong>Seleccionar Archivo</strong>.</li>
  <li>Clic en <strong>Importar</strong>.</li>
  <li>Revisa el resumen — si hay errores descarga el archivo de errores, corrígelos y vuelve a importar solo los fallidos.</li>
</ol>
<h3>Reglas importantes</h3>
<ul>
  <li>No modifiques los nombres de columnas ni las hojas.</li>
  <li>Valores como "Plan" o "Router" deben coincidir exactamente con los registros en el sistema.</li>
  <li>Fechas en formato <strong>YYYY-MM-DD</strong>.</li>
</ul>',
                    ],
                    [
                        'title' => 'Actualización masiva de clientes',
                        'display_order' => 2,
                        'is_published' => true,
                        'tips' => 'Usa esta función para cambiar el plan o router de muchos clientes a la vez. Mucho más eficiente que editar uno por uno.',
                        'content' => '<h2>Actualización masiva de clientes</h2>
<p>Modifica datos de muchos clientes a la vez descargando la plantilla de actualización desde <strong>Acciones Masivas</strong>.</p>
<h3>Pasos</h3>
<ol>
  <li>Descarga la plantilla de <strong>Actualización de Clientes</strong> — tendrá los datos actuales de todos tus clientes.</li>
  <li>Modifica solo los campos que necesitas cambiar.</li>
  <li>Sube el archivo y clic en <strong>Actualizar</strong>.</li>
</ol>
<h3>Campos actualizables</h3>
<p>Plan, router, sectorial, IP, estado, datos de contacto.</p>',
                    ],
                ],
            ],
        ];
    }
}
