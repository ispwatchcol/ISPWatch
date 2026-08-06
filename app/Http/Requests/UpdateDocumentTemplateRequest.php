<?php

namespace App\Http\Requests;

use App\Models\DocumentTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Whitelist cerrada: dompdf acepta más tamaños, pero cualquier
            // valor que no esté aquí llegaría hasta setPaper() como string
            // arbitrario. Nullable = "usa el default" (a4 vertical), que es
            // como se comportaba antes de existir estas columnas.
            'page_size'        => ['sometimes', 'nullable', Rule::in(DocumentTemplate::PAGE_SIZES)],
            'page_orientation' => ['sometimes', 'nullable', Rule::in(DocumentTemplate::PAGE_ORIENTATIONS)],
        ];
    }

    public function attributes(): array
    {
        return [
            'page_size'        => 'tamaño de página',
            'page_orientation' => 'orientación de página',
        ];
    }
}
