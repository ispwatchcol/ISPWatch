<?php

namespace App\Traits;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Validación de las allowlists de IP de las llaves de API.
 *
 * Vive en un trait y no duplicada en cada controlador por una razón concreta:
 * quien decide en runtime si una IP está permitida es `IpUtils` desde el
 * middleware, así que el panel tiene que aceptar exactamente la misma notación.
 * Dos validadores parecidos pero distintos producen el peor de los fallos —una
 * llave que el formulario acepta y que después no autentica desde ninguna IP,
 * sin que el mensaje de error diga por qué.
 */
trait ValidatesIpAllowlist
{
    /**
     * IP suelta o rango CIDR, sin límite de amplitud.
     *
     * La regla `ip` de Laravel rechaza "190.24.7.0/24", que es justo la forma
     * en que un ISP describe el bloque desde el que sale su servidor.
     */
    protected function ipOrCidrRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (!$this->isIpOrCidr((string) $value)) {
                $fail("«{$value}» no es una IP ni un rango CIDR válido.");
            }
        };
    }

    /**
     * Igual que la anterior, pero además rechaza rangos demasiado anchos.
     *
     * Es el guardarraíl del auto-servicio. Un `0.0.0.0/0` convierte la allowlist
     * en decoración: la llave pasa a valer desde cualquier punto de internet, que
     * es precisamente lo que la allowlist existe para impedir. El mensaje explica
     * el límite en direcciones y no en bits, porque quien pega una IP en ese
     * formulario no tiene por qué saber leer una máscara.
     */
    protected function narrowIpOrCidrRule(int $minIpv4Prefix, int $minIpv6Prefix): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($minIpv4Prefix, $minIpv6Prefix): void {
            $candidate = (string) $value;

            if (!$this->isIpOrCidr($candidate)) {
                $fail("«{$candidate}» no es una IP ni un rango CIDR válido.");

                return;
            }

            // Una IP suelta es el caso más estrecho posible: nada que revisar.
            if (!str_contains($candidate, '/')) {
                return;
            }

            [$address, $mask] = explode('/', $candidate, 2);

            $esIpv4  = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $minimo  = $esIpv4 ? $minIpv4Prefix : $minIpv6Prefix;

            if ((int) $mask < $minimo) {
                $direcciones = $esIpv4 ? number_format(2 ** (32 - $minimo), 0, ',', '.') : null;

                $fail(sprintf(
                    '«%s» abarca demasiadas direcciones. El rango más amplio admitido es /%d%s. '
                    . 'Indica la IP pública desde la que se va a usar la llave.',
                    $candidate,
                    $minimo,
                    $direcciones ? " ({$direcciones} direcciones)" : ''
                ));
            }
        };
    }

    private function isIpOrCidr(string $candidate): bool
    {
        return str_contains($candidate, '/')
            ? $this->isValidCidr($candidate)
            : filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }

    private function isValidCidr(string $candidate): bool
    {
        [$address, $mask] = array_pad(explode('/', $candidate, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || !is_numeric($mask)) {
            return false;
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return (int) $mask >= 0 && (int) $mask <= $max
            && IpUtils::checkIp($address, $candidate);
    }
}
