import { createClient } from '@supabase/supabase-js';

// Cargamos las variables desde el .env
const supabaseUrl = import.meta.env.VITE_SUPABASE_URL;
const supabaseAnonKey = import.meta.env.VITE_SUPABASE_ANON_KEY;

// Creamos el cliente de Supabase
if (!supabaseUrl || !supabaseAnonKey) {
    console.error('❌ Error: VITE_SUPABASE_URL o VITE_SUPABASE_ANON_KEY no están definidas en el entorno.');
}

export const supabase = createClient(supabaseUrl, supabaseAnonKey);

