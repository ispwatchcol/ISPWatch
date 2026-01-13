<script setup>
import { ref, onMounted, computed } from 'vue'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import billingService from '@/services/billing'
import api from '@/services/api' // To get customers

const customers = ref([])
const loading = ref(false)
const successInfo = ref(null)

const form = ref({
    customer_id: '',
    amount: 0,
    payment_date: new Date().toISOString().split('T')[0],
    method: 'cash',
    reference: '',
    notes: ''
})

const customerBalance = ref(0)

const fetchCustomers = async () => {
    try {
        const res = await api.customers.getAll()
        customers.value = res.data.data // Assuming paginated or data key
    } catch (e) {
        console.error(e)
    }
}

const getBalance = async () => {
    if(!form.value.customer_id) return
    try {
        const res = await billingService.getBalance(form.value.customer_id)
        customerBalance.value = res.data.balance // Or similar
    } catch (e) {
        // ignore
    }
}

const submitPayment = async () => {
    loading.value = true
    successInfo.value = null
    try {
        const res = await billingService.registerPayment(form.value)
        successInfo.value = res.data
        // Reset non-fixed fields
        form.value.amount = 0
        form.value.reference = ''
        form.value.notes = ''
        // Update balance
        getBalance()
        alert('Payment registered successfully!')
    } catch (e) {
        alert('Error registering payment: ' + (e.response?.data?.message || e.message))
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    fetchCustomers()
})
</script>

<template>
    <DefaultLayout>
        <div class="p-6 max-w-2xl mx-auto">
            <h1 class="text-2xl font-bold mb-6">Register Payment</h1>
            
            <div class="bg-white shadow rounded p-6">
                <form @submit.prevent="submitPayment" class="space-y-4">
                    <!-- Customer Select -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Customer</label>
                        <select v-model="form.customer_id" @change="getBalance" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2" required>
                            <option value="">Select Customer</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">
                                {{ c.name }} {{ c.last_name }} ({{ c.email }})
                            </option>
                        </select>
                        <p v-if="form.customer_id" class="text-sm mt-1">
                            Current Balance Due: <span class="font-bold text-red-600">{{ Number(customerBalance).toLocaleString() }}</span>
                        </p>
                    </div>

                    <!-- Amount -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Amount</label>
                        <div class="relative mt-1 rounded-md shadow-sm">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <span class="text-gray-500 sm:text-sm">$</span>
                            </div>
                            <input v-model="form.amount" type="number" step="0.01" class="block w-full rounded-md border-gray-300 pl-7 p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="0.00" required>
                        </div>
                    </div>

                    <!-- Date & Method -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date</label>
                            <input v-model="form.payment_date" type="date" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Method</label>
                            <select v-model="form.method" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                                <option value="cash">Cash</option>
                                <option value="transfer">Bank Transfer</option>
                                <option value="consignation">Consignation</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- References -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Reference / Transaction ID</label>
                        <input v-model="form.reference" type="text" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea v-model="form.notes" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></textarea>
                    </div>

                    <button type="submit" :disabled="loading" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:bg-gray-400">
                        {{ loading ? 'Processing...' : 'Register Payment' }}
                    </button>
                </form>

                <div v-if="successInfo" class="mt-4 p-4 bg-green-50 text-green-700 rounded">
                    <p class="font-bold">Payment Recorded! ID: {{ successInfo.id }}</p>
                    <ul class="text-sm list-disc pl-5">
                       <li v-for="alloc in successInfo.allocations" :key="alloc.id">
                           Applied {{ alloc.amount }} to Invoice #{{ alloc.invoice_id }}
                       </li>
                    </ul>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>
