# Documentación: Sistema de Plantillas VPN

## Archivos de Configuración

### 1. `backup-universal.rsc` o `backup-core-ispwatch.rsc`
**Descripción**: Configuración estática del router CORE-ISPWATCH (servidor VPN principal).  
**Uso**: Copiar y pegar directamente en el router central. **No contiene variables**.

### 2. `router-client-template.rsc`
**Descripción**: Plantilla para routers clientes que se conectan al servidor VPN central.  
**Uso**: Generar configuración dinámica reemplazando variables según cada router.

---

## Variables de Plantilla (solo para router-client-template.rsc)

Cada vez que se crea o edita un router en el sistema, se deben reemplazar las siguientes variables en la plantilla:

| Variable | Descripción | Valor Actual/Por Defecto |
|----------|-------------|--------------------------|
| `{{ROUTER_NAME}}` | Nombre identificador del router | Viene del campo `name` en la tabla `router` |
| `{{VPN_USERNAME}}` | Usuario VPN único para este router | Viene del campo `vpn_username` en la tabla `router` |
| `{{VPN_PASSWORD}}` | Contraseña VPN única para este router | Viene del campo `vpn_password` en la tabla `router` |
| `{{LARAVEL_IP}}` | IP pública del servidor Laravel | `138.197.30.155` |
| `{{IPSEC_SECRET}}` | Secreto compartido IPSec | `ISPWATCH_SECRET` |

## Almacenamiento en Base de Datos

Los campos VPN se guardan en la tabla `router`:

```sql
ALTER TABLE router ADD COLUMN vpn_username VARCHAR(255) NULL COMMENT 'Usuario VPN único para L2TP';
ALTER TABLE router ADD COLUMN vpn_password VARCHAR(255) NULL COMMENT 'Contraseña VPN para L2TP';
```

## Ejemplo de Uso

### 1. Al Crear un Nuevo Router

Cuando se crea un router cliente en el sistema:

1. **Generar credenciales VPN únicas**:
   - `vpn_username`: Por ejemplo: `router-zona-norte-01`
   - `vpn_password`: Contraseña segura generada

2. **Guardar en la base de datos**:
```php
$router = Router::create([
    'name' => 'ROUTER-ZONA-NORTE',
    'ip' => '192.168.10.1',
    'vpn_username' => 'router-zona-norte-01',
    'vpn_password' => 'Passw0rd$ecure2024!',
    // ... otros campos
]);
```

3. **Generar el script RSC personalizado**:
```php
$template = file_get_contents(base_path('backup/router-client-template.rsc'));

$script = str_replace([
    '{{ROUTER_NAME}}',
    '{{VPN_USERNAME}}',
    '{{VPN_PASSWORD}}',
    '{{VPN_SERVER_IP}}',
    '{{IPSEC_SECRET}}',
    '{{LARAVEL_IP}}'
], [
    $router->name,
    $router->vpn_username,
    $router->vpn_password,
    '138.197.30.155',
    'ISPWATCH_SECRET',
    '138.197.30.155'
], $template);

// $script ahora contiene el script personalizado listo para aplicar al router cliente
```

### 2. Script Generado (Ejemplo)

Para un router cliente con:
- Nombre: `ROUTER-ZONA-NORTE`
- Usuario VPN: `router-zona-norte-01`
- Contraseña VPN: `Passw0rd$ecure2024!`

El script generado incluirá:

```rsc
/system identity
set name=ROUTER-ZONA-NORTE

# Configuración L2TP Client para conectarse al CORE-ISPWATCH
/interface l2tp-client
add name=l2tp-ispwatch connect-to=138.197.30.155 user=router-zona-norte-01 password=Passw0rd$ecure2024! \
    use-ipsec=yes ipsec-secret=ISPWATCH_SECRET disabled=no comment="Conexión VPN a CORE-ISPWATCH"
```

## Seguridad

- **Nunca reutilizar credenciales VPN** entre routers diferentes
- **Generar contraseñas seguras** con al menos 16 caracteres
- **Almacenar las contraseñas de forma segura** (considerar encriptación en la base de datos)
- **Rotar credenciales periódicamente** para mayor seguridad

## Próximos Pasos

Para implementar el sistema de generación automática:
1. Crear un servicio/helper para generar credenciales VPN únicas
2. Implementar la lógica de reemplazo de plantilla en el controlador de routers
3. Agregar el campo de script en el formulario de edición de router (opcional)
4. Crear endpoint API para descargar/visualizar el script generado
