<template>
  <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
      <div>
        <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
          Manual de Usuario
        </h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Guía completa de funcionalidades del sistema ISPWatch</p>
      </div>
    </div>

    <!-- Content Grid -->
    <div class="mx-auto w-full">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
        
        <!-- Category Card -->
        <div 
          v-for="(category, index) in categories" 
          :key="index" 
          class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow duration-200"
        >
          <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-700/30 flex items-center gap-3">
             <v-icon :name="category.icon" class="text-indigo-600 dark:text-white w-5 h-5" />
            <h3 class="font-medium text-gray-900 dark:text-white text-lg">{{ category.title }}</h3>
          </div>
          
          <ul class="py-2">
            <li 
              v-for="(item, i) in category.items" 
              :key="i"
              @click="openItem(item)"
              class="px-6 py-3 cursor-pointer text-gray-600 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-gray-700 hover:text-indigo-600 dark:hover:text-indigo-300 text-sm font-medium transition-colors border-l-4 border-transparent hover:border-indigo-500 flex justify-between items-center group"
            >
              {{ item.title }}
              <v-icon name="hi-chevron-right" class="text-gray-300 dark:text-gray-600 w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity" />
            </li>
          </ul>
        </div>

      </div>
    </div>

    <!-- Modal for Topic Details -->
    <div 
      v-if="selectedItem" 
      class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
      @click="closeModal"
    >
      <div 
        class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full mx-4 overflow-hidden transform transition-all border border-gray-100 dark:border-gray-700"
        @click.stop
      >
        <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-start bg-indigo-50/50 dark:bg-gray-700/30">
          <div>
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">{{ selectedItem.title }}</h3>
             <span class="text-xs text-indigo-500 dark:text-indigo-300 uppercase font-semibold tracking-wider mt-1 block">Documentación</span>
          </div>
          <button 
            @click="closeModal"
            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600"
          >
            <v-icon name="io-close" class="w-6 h-6" />
          </button>
        </div>
        
        <div class="p-8 prose prose-indigo dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed">
           <p>{{ selectedItem.description }}</p>
           
           <div v-if="selectedItem.tips" class="mt-6 bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-400 p-4 rounded-r">
              <p class="text-sm text-yellow-800 dark:text-yellow-200 m-0 flex gap-2">
                  <v-icon name="hi-light-bulb" class="w-5 h-5 flex-shrink-0" />
                  <span><strong>Tip:</strong> {{ selectedItem.tips }}</span>
              </p>
           </div>
        </div>

        <div class="p-6 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex justify-end">
          <button 
            @click="closeModal"
            class="px-5 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium text-sm transition-colors"
          >
            Entendido
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref } from 'vue';

const selectedItem = ref(null);

const openItem = (item) => {
  selectedItem.value = item;
};

const closeModal = () => {
  selectedItem.value = null;
};

// Data Structure matching the requested "Box" layout
const categories = [
  {
    title: "Gestión de Clientes",
    icon: 'bi-people-fill', 
    items: [
      { 
        title: "Lista de Clientes", 
        description: "Visualice, busque y filtre su base de datos completa de suscriptores. Desde aquí puede acceder al perfil detallado de cada usuario, ver su estado de cuenta y editar su información personal.", 
        tips: "Use la barra de búsqueda superior para encontrar clientes por nombre, cédula o dirección IP ràpidamente."
      },
      { 
        title: "Mapa de Cobertura", 
        description: "Herramienta de visualización geoespacial que muestra la ubicación de todos sus clientes en el mapa. Los marcadores cambian de color según el estado del servicio o la torre a la que están conectados.",
        tips: "Asegúrese de registrar las coordenadas GPS al momento de la instalación para mantener el mapa actualizado."
      },
      { 
        title: "Registrar Nuevo Cliente", 
        description: "Formulario paso a paso para dar de alta un nuevo suscriptor. Incluye la asignación de plan, router, dirección IP y coordenadas de instalación." 
      },
      { 
        title: "Estados de Servicio", 
        description: "Explicación de los estados: Activo (servicio normal), Suspendido (corte por falta de pago), y Retirado (contrato finalizado)." 
      }
    ]
  },
  {
    title: "Infraestructura de Red",
    icon: 'bi-hdd-network',
    items: [
      { 
        title: "Routers (MikroTik)", 
        description: "Gestión de sus routers de borde y concentradores. Configure la conexión a la API para permitir que el sistema cree colas simples y gestione los cortes de servicio automáticamente." 
      },
      { 
        title: "Sectoriales y Torres", 
        description: "Inventario de sus puntos de emisión. Registre cada torre y sus antenas sectoriales asociadas para llevar un control de capacidad y cobertura." 
      },
      { 
        title: "Pools de IP", 
        description: "Administración de rangos de direcciones IP (IPv4). Defina sus segmentos de red para asignación estática o via DHCP." 
      },
      { 
        title: "Monitoreo", 
        description: "Herramientas para verificar el estado de conexión de los dispositivos en tiempo real (Ping, Estado API)." 
      }
    ]
  },
  {
    title: "Facturación y Finanzas",
    icon: 'fa-file-invoice-dollar',
    items: [
      { 
        title: "Planes de Servicio", 
        description: "Configure las velocidades de bajada/subida y los precios de sus planes de internet. Estos planes se sincronizan con las Queues de MikroTik." 
      },
      { 
        title: "Facturación Recurrente", 
        description: "Cómo funciona el ciclo de facturación mensual. Generación automática de facturas y envío de recordatorios por correo." 
      },
      { 
        title: "Cortes Automáticos", 
        description: "Configuración de reglas para suspensión automática de servicio por falta de pago y su posterior reconexión." 
      }
    ]
  },
  {
    title: "Administración y Personal",
    icon: 'ri-admin-fill',
    items: [
      { 
        title: "Usuarios del Sistema", 
        description: "Gestión de acceso para sus empleados. Cree cuentas para técnicos, personal administrativo y vendedores." 
      },
      { 
        title: "Roles y Permisos", 
        description: "Defina qué acciones puede realizar cada rol. Por ejemplo, restringir el acceso a la configuración financiera para los técnicos." 
      },
      { 
        title: "Auditoría (Logs)", 
        description: "Historial de acciones importantes realizadas en el sistema. Útil para seguridad y seguimiento de cambios." 
      }
    ]
  },
  {
    title: "Inventario",
    icon: 'oi-package',
    items: [
      { 
        title: "Stock General", 
        description: "Vista de cantidades disponibles de equipos (Routers, Antenas, Cables, etc.) en cada bodega." 
      },
      { 
        title: "Asignación a Técnicos", 
        description: "Proceso para entregar equipos a los técnicos para instalaciones diarias y su posterior descargo al instalarse en cliente." 
      }
    ]
  },
  {
    title: "Soporte Técnico",
    icon: 'bi-headset',
    items: [
      { 
        title: "Tickets de Soporte", 
        description: "Sistema de gestión de incidencias. Registre llamadas de clientes, asigne prioridad y delegue la solución a un técnico." 
      }
    ]
  }
];
</script>

<style scoped>
/* Transición suave para el modal */
.grid > div {
  break-inside: avoid; 
}
</style>
