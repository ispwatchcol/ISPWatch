import axios from 'axios'
import { apiClient } from '../api'

export default {
    async login(credentials) {
        const baseUrl = import.meta.env.VITE_API_URL
            ? new URL(import.meta.env.VITE_API_URL).origin
            : window.location.origin
        await axios.get(`${baseUrl}/sanctum/csrf-cookie`, { withCredentials: true })
        return apiClient.post('/login', credentials)
    },

    async register(userData) {
        const baseUrl = import.meta.env.VITE_API_URL
            ? new URL(import.meta.env.VITE_API_URL).origin
            : window.location.origin
        await axios.get(`${baseUrl}/sanctum/csrf-cookie`, { withCredentials: true })
        return apiClient.post('/register', userData)
    },

    async resendVerification(email) {
        return apiClient.post('/verify-email/resend', { email })
    },
}
