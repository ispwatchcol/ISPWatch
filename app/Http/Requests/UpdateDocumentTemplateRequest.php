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
            'body_html' => ['required', 'string', 'max:200000'],
        ];
    }
}
