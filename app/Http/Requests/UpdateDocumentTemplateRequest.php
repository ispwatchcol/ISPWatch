<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentTemplateRequest extends FormRequest
{
    /**
     * Authorization is handled by the 'permission:manage_document_templates'
     * route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body_html'        => ['required', 'string', 'max:200000'],
            // Modo avanzado permite un documento HTML completo (más grande
            // que un fragmento de body) — mismo límite, dompdf ya tiene sus
            // propios límites prácticos de tamaño de documento.
            'is_advanced_mode' => ['sometimes', 'boolean'],
        ];
    }
}
