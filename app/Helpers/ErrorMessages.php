<?php

namespace App\Helpers;

use Illuminate\Database\QueryException;

class ErrorMessages
{
    /**
     * Get a user-friendly error message from a database exception.
     */
    public static function getDatabaseErrorMessage(\Throwable $exception): string
    {
        if (!($exception instanceof QueryException)) {
            return self::getGenericErrorMessage();
        }

        $errorCode = $exception->errorInfo[0] ?? null;
        $errorMessage = $exception->getMessage();

        // PostgreSQL error codes
        switch ($errorCode) {
            case '23505': // Unique violation
                return self::getUniqueViolationMessage($errorMessage);
            
            case '23503': // Foreign key violation
                return 'No se puede completar la operación. Verifica que los datos relacionados sean válidos.';
            
            case '23502': // Not null violation
                return self::getNotNullViolationMessage($errorMessage);
            
            case '23514': // Check constraint violation
                return 'Los datos ingresados no cumplen con los requisitos necesarios.';
            
            case '42P01': // Undefined table
                return 'Error en la configuración del sistema. Por favor, contacta al administrador.';
            
            case '42703': // Undefined column
                return 'Error en la configuración del sistema. Por favor, contacta al administrador.';
            
            default:
                return self::getGenericErrorMessage();
        }
    }

    /**
     * Extract field name from unique violation error and return friendly message.
     */
    private static function getUniqueViolationMessage(string $errorMessage): string
    {
        // Check if it's about email
        if (str_contains($errorMessage, 'email')) {
            return 'Este correo electrónico ya está registrado. Por favor, usa otro.';
        }

        // Check if it's about username
        if (str_contains($errorMessage, 'user_name')) {
            return 'Este nombre de usuario ya está en uso. Por favor, elige otro.';
        }

        // Check if it's about primary key (id)
        if (str_contains($errorMessage, '_pkey') || str_contains($errorMessage, 'Key (id)')) {
            return 'Error al crear el registro. Por favor, intenta nuevamente.';
        }

        // Generic unique violation
        return 'Ya existe un registro con estos datos. Por favor, verifica la información ingresada.';
    }

    /**
     * Extract field name from not null violation and return friendly message.
     */
    private static function getNotNullViolationMessage(string $errorMessage): string
    {
        // Try to extract column name from error message
        if (preg_match('/column "([^"]+)"/', $errorMessage, $matches)) {
            $column = $matches[1];
            
            $fieldNames = [
                'name' => 'nombre',
                'email' => 'correo electrónico',
                'password' => 'contraseña',
                'user_name' => 'nombre de usuario',
                'user_lastname' => 'apellido',
                'tel' => 'teléfono',
                'role_id' => 'rol',
                'tenant_id' => 'empresa',
            ];

            $friendlyName = $fieldNames[$column] ?? $column;
            return "El campo '{$friendlyName}' es obligatorio.";
        }

        return 'Faltan campos obligatorios. Por favor, completa todos los datos requeridos.';
    }

    /**
     * Get a generic error message.
     */
    private static function getGenericErrorMessage(): string
    {
        return 'Ocurrió un error al procesar tu solicitud. Por favor, intenta nuevamente.';
    }

    /**
     * Check if the error should be shown to the user.
     */
    public static function shouldShowToUser(\Throwable $exception): bool
    {
        // Always show database errors as friendly messages
        if ($exception instanceof QueryException) {
            return true;
        }

        // Show validation errors
        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return true;
        }

        // Don't show other exceptions in production
        return config('app.debug', false);
    }
}
