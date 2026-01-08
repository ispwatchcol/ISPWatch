<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background-color: #3b82f6;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }

        .content {
            background-color: #f9fafb;
            padding: 30px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }

        .ticket-info {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            font-weight: bold;
            width: 120px;
            color: #6b7280;
        }

        .info-value {
            flex: 1;
            color: #111827;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-open {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-in_progress {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-resolved {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-closed {
            background-color: #e5e7eb;
            color: #374151;
        }

        .priority-low {
            background-color: #d1fae5;
            color: #065f46;
        }

        .priority-medium {
            background-color: #fef3c7;
            color: #92400e;
        }

        .priority-high {
            background-color: #fed7aa;
            color: #9a3412;
        }

        .priority-urgent {
            background-color: #fecaca;
            color: #991b1b;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }

        .description {
            background-color: #f3f4f6;
            padding: 15px;
            border-radius: 6px;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h2 style="margin: 0;">
            @if($type === 'created')
                🆕 Nuevo Ticket de Soporte
            @elseif($type === 'updated')
                🔄 Ticket Actualizado
            @elseif($type === 'message')
                💬 Nuevo Mensaje en Ticket
            @endif
        </h2>
    </div>

    <div class="content">
        <div class="ticket-info">
            <div class="info-row">
                <span class="info-label">Ticket #:</span>
                <span class="info-value">{{ $ticket->id }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Asunto:</span>
                <span class="info-value"><strong>{{ $ticket->subject }}</strong></span>
            </div>
            <div class="info-row">
                <span class="info-label">Categoría:</span>
                <span class="info-value">{{ ucfirst($ticket->category) }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Estado:</span>
                <span class="info-value">
                    <span class="badge status-{{ $ticket->status }}">
                        {{ ucfirst(str_replace('_', ' ', $ticket->status)) }}
                    </span>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Prioridad:</span>
                <span class="info-value">
                    <span class="badge priority-{{ $ticket->priority }}">
                        {{ ucfirst($ticket->priority) }}
                    </span>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Cliente:</span>
                <span class="info-value">{{ $ticket->user->user_name }} {{ $ticket->user->user_lastname }}</span>
            </div>
            @if($ticket->staff)
                <div class="info-row">
                    <span class="info-label">Asignado a:</span>
                    <span class="info-value">{{ $ticket->staff->user_name }} {{ $ticket->staff->user_lastname }}</span>
                </div>
            @endif
            <div class="info-row">
                <span class="info-label">Fecha:</span>
                <span class="info-value">{{ $ticket->created_at->format('d/m/Y H:i') }}</span>
            </div>
        </div>

        @if($ticket->description)
            <div>
                <strong>Descripción:</strong>
                <div class="description">
                    {{ $ticket->description }}
                </div>
            </div>
        @endif

        @if($type === 'created')
            <p style="margin-top: 20px;">
                Se ha creado un nuevo ticket de soporte. Recibirás notificaciones cuando haya actualizaciones.
            </p>
        @elseif($type === 'updated')
            <p style="margin-top: 20px;">
                El estado del ticket ha sido actualizado. Por favor revisa los cambios.
            </p>
        @elseif($type === 'message')
            <p style="margin-top: 20px;">
                Has recibido un nuevo mensaje en este ticket. Por favor revisa la conversación.
            </p>
        @endif
    </div>

    <div class="footer">
        <p>Este es un mensaje automático de ISPWatch.</p>
        <p>Por favor no responder a este correo.</p>
    </div>
</body>

</html>