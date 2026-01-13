<script setup>
import { ref, onMounted, watch } from 'vue'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import billingService from '@/services/billing'
// Assuming oh-vue-icons are registered globally or we need to import specific ones.
// I'll try to use standard classes or just text for simplicity if icons fail.

const invoices = ref([])
const loading = ref(false)
const filters = ref({
    status: '',
    period: '',
    customer_id: ''
})
const pagination = ref({})

const fetchInvoices = async (page = 1) => {
    loading.value = true
    try {
        const params = { 
            page, 
            ...filters.value 
        }
        const response = await billingService.getInvoices(params)
        invoices.value = response.data.data
        pagination.value = {
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total
        }
    } catch (error) {
        console.error('Error fetching invoices', error)
    } finally {
        loading.value = false
    }
}

const downloadPdf = async (invoiceId, number) => {
    try {
        const response = await billingService.downloadPdf(invoiceId)
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `Invoice-${number}.pdf`)
        document.body.appendChild(link)
        link.click()
    } catch (e) {
        alert('Error downloading PDF')
    }
}

onMounted(() => {
    fetchInvoices()
})

watch(filters, () => {
    fetchInvoices(1)
}, { deep: true })
</script>

<template>
    <DefaultLayout>
        <div class="p-6">
            <h1 class="text-2xl font-bold mb-6 text-gray-800">Invoices</h1>
            
            <!-- Filters -->
            <div class="bg-white p-4 rounded shadow mb-6 flex gap-4 flex-wrap">
                <input v-model="filters.period" type="month" placeholder="Period" class="border p-2 rounded">
                <select v-model="filters.status" class="border p-2 rounded">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="issued">Issued</option>
                    <option value="paid">Paid</option>
                    <option value="overdue">Overdue</option>
                    <option value="void">Void</option>
                </select>
                <button @click="fetchInvoices(1)" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filter</button>
            </div>

            <!-- Table -->
            <div class="bg-white rounded shadow overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-100 border-b">
                            <th class="p-4 font-semibold text-gray-600">Number</th>
                            <th class="p-4 font-semibold text-gray-600">Customer</th>
                            <th class="p-4 font-semibold text-gray-600">Period</th>
                            <th class="p-4 font-semibold text-gray-600">Issue Date</th>
                            <th class="p-4 font-semibold text-gray-600">Due Date</th>
                            <th class="p-4 font-semibold text-gray-600">Amount</th>
                            <th class="p-4 font-semibold text-gray-600">Balance</th>
                            <th class="p-4 font-semibold text-gray-600">Status</th>
                            <th class="p-4 font-semibold text-gray-600">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="9" class="p-4 text-center">Loading...</td>
                        </tr>
                        <tr v-for="invoice in invoices" :key="invoice.id" class="border-b hover:bg-gray-50">
                            <td class="p-4 font-medium">{{ invoice.number || 'Draft' }}</td>
                            <td class="p-4">{{ invoice.customer?.name }} {{ invoice.customer?.last_name }}</td>
                            <td class="p-4">{{ invoice.period_start }}</td>
                            <td class="p-4">{{ invoice.issue_date }}</td>
                            <td class="p-4">{{ invoice.due_date }}</td>
                            <td class="p-4 font-bold">{{ Number(invoice.total).toLocaleString() }}</td>
                            <td class="p-4 text-red-500">{{ Number(invoice.balance_due).toLocaleString() }}</td>
                            <td class="p-4">
                                <span :class="{
                                    'bg-green-100 text-green-800': invoice.status === 'paid',
                                    'bg-red-100 text-red-800': invoice.status === 'overdue',
                                    'bg-yellow-100 text-yellow-800': invoice.status === 'issued' || invoice.status === 'partial',
                                    'bg-gray-100 text-gray-800': invoice.status === 'draft' || invoice.status === 'void'
                                }" class="px-2 py-1 rounded text-xs uppercase font-bold">
                                    {{ invoice.status }}
                                </span>
                            </td>
                            <td class="p-4 flex gap-2">
                                <router-link :to="`/billing/invoices/${invoice.id}`" class="text-blue-600 hover:text-blue-800">
                                    View
                                </router-link>
                                <button @click="downloadPdf(invoice.id, invoice.number)" class="text-gray-600 hover:text-gray-800">
                                    PDF
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!loading && invoices.length === 0">
                            <td colspan="9" class="p-4 text-center text-gray-500">No invoices found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mt-4 flex gap-2 justify-center" v-if="pagination.last_page > 1">
                <button 
                    @click="fetchInvoices(pagination.current_page - 1)" 
                    :disabled="pagination.current_page === 1"
                    class="px-3 py-1 border rounded disabled:opacity-50"
                >
                    Prev
                </button>
                <span class="px-3 py-1">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                <button 
                    @click="fetchInvoices(pagination.current_page + 1)" 
                    :disabled="pagination.current_page === pagination.last_page"
                    class="px-3 py-1 border rounded disabled:opacity-50"
                >
                    Next
                </button>
            </div>
        </div>
    </DefaultLayout>
</template>
