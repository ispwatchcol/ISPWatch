# Guía de Importación de Datos - ISPWatch

Este módulo permite migrar datos desde sistemas previos mediante archivos Excel o CSV.

## Flujo de Importación

El orden recomendado para importar datos es:

1. **Routers**: Equipos MikroTik principales.
2. **Planes de Servicio**: Definición de velocidades y precios.
3. **Clientes**: Información de usuarios y sus servicios activos.

---

## 1. Routers

**Archivo**: `plantilla_routers.xlsx`

| Campo         | Requerido | Descripción                                        |
| ------------- | --------- | -------------------------------------------------- |
| nombre        | Sí        | Nombre identificador del router (ej: Torre Centro) |
| ip            | Sí        | Dirección IP de gestión (ej: 192.168.1.1)          |
| puerto        | No        | Puerto API (Default: 8728)                         |
| usuario       | Sí        | Usuario de Winbox/API                              |
| password      | Sí        | Contraseña de Winbox/API                           |
| tipo_corte    | Sí        | "Corte Automático", "Corte Manual" o "Sin Corte"   |
| wan_interface | No        | Interfaz de salida a internet (Default: ether1)    |

## 2. Planes de Servicio

**Archivo**: `plantilla_service-plans.xlsx`

| Campo       | Requerido | Descripción                                              |
| ----------- | --------- | -------------------------------------------------------- |
| nombre      | Sí        | Nombre comercial del plan                                |
| costo       | Sí        | Precio mensual (numérico)                                |
| tipo_plan   | Sí        | Nombre del Tipo de Plan (ej: PPPoE, Hotspot, PCQ, Queue) |
| descripcion | No        | Detalles adicionales                                     |

> **Nota**: El `tipo_plan` debe coincidir con los tipos configurados en el sistema.

## 3. Clientes

**Archivo**: `plantilla_customers.xlsx`

| Campo       | Requerido | Descripción                                              |
| ----------- | --------- | -------------------------------------------------------- |
| email       | Sí        | Email único del cliente                                  |
| nombre      | Sí        | Primer nombre                                            |
| apellido    | Sí        | Apellido                                                 |
| telefono    | No        | Número de contacto                                       |
| direccion   | No        | Dirección física de instalación                          |
| ciudad      | No        | Ciudad o Municipio                                       |
| ip_usuario  | No        | IP asignada al cliente (si es estática)                  |
| ip_router   | Sí        | IP del router al que se conecta (debe existir en paso 1) |
| nombre_plan | Sí        | Nombre del plan contratado (debe existir en paso 2)      |

---

## Solución de Problemas Comunes

- **Error: "Router with IP... not found"**: Verifique que importó los routers primero y que la IP en el excel de clientes coincide exactamente.
- **Error: "Service plan... not found"**: Verifique que el nombre del plan en clientes coincida exactamente con el importado en el paso 2.
- **Errores de validación**: Use el botón "Ver Errores Detallados" para identificar filas y columnas con formatos incorrectos.
