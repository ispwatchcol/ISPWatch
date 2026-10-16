La llave de API «{{ $keyName }}» de la integración «{{ $integrationName }}» vence el {{ $expiresAt }} (en {{ $daysLeft }} día(s)).

Empresa (tenant): {{ $tenantId }}
Último uso:       {{ $lastUsedAt ?? 'nunca se ha usado' }}

Ese día la integración dejará de poder consultar la API y responderá 401 key_expired.

Qué hacer: emite una llave nueva desde Configuración → Llaves API, cámbiala en el sistema integrador y revoca la vieja cuando confirmes que la nueva funciona. La vigencia de una llave existente no se puede extender.

Si la integración ya no se usa, no hace falta hacer nada: la llave vencerá sola.
