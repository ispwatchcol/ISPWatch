<template>
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">Dashboard</h1>
        <p class="text-gray-600 mb-6">Bienvenido al panel de control. Aquí puedes ver un resumen de la actividad reciente.</p>

        <!-- Tarjetas de estadísticas -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="bg-white rounded-2xl shadow p-4">
                <h2 class="text-lg font-semibold">Clientes activos</h2>
                <p class="text-3xl font-bold mt-2">1</p>
            </div> 
            <div class="bg-white rounded-2xl shadow p-4">
                <h2 class="text-lg font-semibold">Ingresos del mes</h2>
                <p class="text-3xl font-bold mt-2">$120,230</p>
            </div> 
            <div class="bg-white rounded-2xl shadow p-4">
                <h2 class="text-lg font-semibold">Conexiones activas</h2>
                <p class="text-3xl font-bold mt-2">1</p>
            </div> 
            <div class="bg-white rounded-2xl shadow p-4">
                <h2 class="text-lg font-semibold">Tickets abiertos</h2>
                <p class="text-3xl font-bold mt-2">23</p>
            </div>
        </div>

        <!-- Gráfico de actividad -->
        <div class="mt-8 bg-white rounded-2xl shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Estadísticas mensuales</h2>
            <p>Aquí va la gráfica</p>
        </div>
    </div>
</template>

<script>
export default {
    name: 'Dashboard'
};
</script>