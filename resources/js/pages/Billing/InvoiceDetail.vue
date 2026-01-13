<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import billingService from '@/services/billing'

const route = useRoute()
const invoice = ref(null)
const loading = ref(true)

const fetchInvoice = async () => {
    loading.value = true
    try {
        const response = await billingService.getInvoice(route.params.id)
        invoice.value = response.data
    } catch (e) {
        alert('Error loading invoice')
    } finally {
        loading.value = false
    }
}

const newItem = ref({ description: '', amount: 0, type: 'adjustment' })
const addItem = async () => {
    try {
        await billingService.addItems(invoice.value.id, newItem.value)
        newItem.value = { description: '', amount: 0, type: 'adjustment' }
        fetchInvoice() // Reload
    } catch (e) {
        alert('Error adding item')
    }
}

const downloadPdf = async () => {
    if (!invoice.value) return
    try {
        const response = await billingService.downloadPdf(invoice.value.id)
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `Invoice-${invoice.value.number}.pdf`)
        document.body.appendChild(link)
        link.click()
    } catch (e) {
        alert('Error downloading PDF')
    }
}

onMounted(() => {
    fetchInvoice()
})
</script>

<template>
    <DefaultLayout>
        <div class="p-6">
            <div v-if="loading" class="text-center">Loading...</div>
            <div v-else-if="invoice" class="max-w-4xl mx-auto bg-white shadow-lg rounded-lg overflow-hidden">
                <!-- Header -->
                <div class="bg-gray-800 text-white p-6 flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold">INVOICE</h1>
                        <p class="text-gray-400">#{{ invoice.number || 'DRAFT' }}</p>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-400">Status</div>
                        <div class="text-xl font-bold uppercase" :class="invoice.status === 'paid' ? 'text-green-400' : 'text-yellow-400'">
                            {{ invoice.status }}
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="p-6 grid grid-cols-2 gap-6">
                    <div>
                        <h3 class="font-bold text-gray-700 mb-2">Bill To</h3>
                        <p class="text-gray-600">{{ invoice.customer?.name }} {{ invoice.customer?.last_name }}</p>
                        <p class="text-gray-600">{{ invoice.customer?.email }}</p>
                    </div>
                    <div class="text-right">
                        <p><span class="font-bold">Issue Date:</span> {{ invoice.issue_date }}</p>
                        <p><span class="font-bold">Due Date:</span> {{ invoice.due_date }}</p>
                        <p><span class="font-bold">Period:</span> {{ invoice.period_start }} to {{ invoice.period_end }}</p>
                    </div>
                </div>

                <!-- Items -->
                <div class="p-6">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b bg-gray-50">
                                <th class="p-3">Description</th>
                                <th class="p-3 text-right">Qty</th>
                                <th class="p-3 text-right">Price</th>
                                <th class="p-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in invoice.items" :key="item.id" class="border-b">
                                <td class="p-3">{{ item.description }} <span class="text-xs text-gray-500 uppercase">({{ item.type }})</span></td>
                                <td class="p-3 text-right">{{ Number(item.quantity) }}</td>
                                <td class="p-3 text-right">{{ Number(item.unit_price).toLocaleString() }}</td>
                                <td class="p-3 text-right font-medium">{{ Number(item.amount).toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="p-6 bg-gray-50 border-t">
                    <div class="flex justify-end text-lg">
                        <div class="w-1/2 max-w-xs">
                            <div class="flex justify-between mb-2">
                                <span>Subtotal:</span>
                                <span>{{ Number(invoice.subtotal).toLocaleString() }}</span>
                            </div>
                            <div class="flex justify-between font-bold text-2xl border-t pt-2">
                                <span>Total:</span>
                                <span>{{ Number(invoice.total).toLocaleString() }}</span>
                            </div>
                            <div class="flex justify-between text-red-600 font-bold mt-2">
                                <span>Balance Due:</span>
                                <span>{{ Number(invoice.balance_due).toLocaleString() }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="p-6 border-t flex justify-between bg-gray-100 items-center">
                    <button @click="$router.push('/billing/invoices')" class="text-gray-600 hover:text-gray-900 font-medium">
                        &larr; Back to List
                    </button>
                    
                    <div class="flex gap-4">
                        <!-- Add Item Form (Mini) -->
                        <div v-if="invoice.status !== 'paid' && invoice.status !== 'void'" class="flex gap-2 items-center border p-2 rounded bg-white">
                            <input v-model="newItem.description" placeholder="Item Desc" class="border p-1 text-sm rounded w-32">
                            <input v-model="newItem.amount" type="number" placeholder="Price" class="border p-1 text-sm rounded w-20">
                            <button @click="addItem" class="bg-indigo-600 text-white px-3 py-1 text-sm rounded hover:bg-indigo-700">Add Item</button>
                        </div>

                        <button @click="downloadPdf" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800 flex items-center gap-2">
                            Download PDF
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>
