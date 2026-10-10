<?php

namespace Tests\Unit;

use App\Services\MikroTik\Concerns\BuildsCoreSshExec;
use App\Services\MikroTik\Concerns\DetectsSshExecFailures;
use PHPUnit\Framework\TestCase;

/**
 * Guards the two ssh-exec building blocks the whole CORE→client push path now
 * shares: the port-aware command builder (BuildsCoreSshExec) and the hardened
 * failure/timeout detection (DetectsSshExecFailures).
 *
 * These encode the exact RouterOS behaviours proven live against CORE_TOCAIMA
 * (CCR2116, ROS 7.23) reached through the ROS 7.22 CORE:
 *   - a command the client rejects still returns `exit-code: 0` with the error
 *     in the `output:` body (so exit-code alone is not enough), and
 *   - a dead endpoint returns `action timed out ... (13)` while a wrong port
 *     returns `<connection failed> <ip>:22`.
 */
class CoreSshExecTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new class {
            use BuildsCoreSshExec;
            use DetectsSshExecFailures;

            public function build(string $ip, string $u, string $p, string $cmd, ?int $port): string
            {
                return $this->coreSshExecCommand($ip, $u, $p, $cmd, $port);
            }
            public function isConn(string $o): bool { return $this->isSshExecConnectionFailure($o); }
            public function isCmd(string $o): bool { return $this->isSshExecCommandFailure($o); }
            public function body(string $o): string { return $this->sshExecOutputBody($o); }
            public function connMsg(string $ip, string $o, ?int $port): string
            {
                return $this->sshExecConnectionFailureMessage($ip, $o, $port);
            }
            public function isAuth(string $o): bool { return $this->isSshExecAuthFailure($o); }
            public function authMsg(string $ip, string $o, ?int $port): string
            {
                return $this->sshExecAuthFailureMessage($ip, $o, $port);
            }
        };
    }

    public function test_default_port_22_emits_no_port_argument(): void
    {
        $cmd = $this->subject->build('172.16.16.254', 'ispwatch', 'pw', '/system identity print', null);
        $this->assertStringNotContainsString('port=', $cmd);
        $this->assertStringContainsString('address=172.16.16.254', $cmd);
    }

    public function test_explicit_22_still_emits_no_port_argument(): void
    {
        $cmd = $this->subject->build('172.16.16.254', 'ispwatch', 'pw', '/system identity print', 22);
        $this->assertStringNotContainsString('port=', $cmd);
    }

    public function test_non_default_port_is_injected(): void
    {
        $cmd = $this->subject->build('172.16.16.254', 'ispwatch', 'pw', '/system identity print', 2200);
        $this->assertStringContainsString('address=172.16.16.254 port=2200 user=ispwatch', $cmd);
    }

    public function test_password_quotes_are_escaped_once(): void
    {
        $cmd = $this->subject->build('10.0.0.1', 'admin', 'pa"ss', '/x', 2200);
        $this->assertStringContainsString('password="pa\\"ss"', $cmd);
    }

    public function test_connection_failure_is_detected(): void
    {
        $this->assertTrue($this->subject->isConn('failure: closing connection: <connection failed> 172.16.16.254:22 (12)'));
        $this->assertTrue($this->subject->isConn('action timed out - try again ... (13)'));
        $this->assertFalse($this->subject->isConn("exit-code: 0\n     output:   name: CORE_TOCAIMA_2116"));
    }

    public function test_command_failure_detected_even_with_exit_code_zero(): void
    {
        // Verified live: a bad parameter comes back as exit-code 0 with the
        // parser complaint (and a "(line N column M)" position) in the body.
        $this->assertTrue($this->subject->isCmd("exit-code: 0\n     output: bad parameter comentario (line 1 column 37)"));
        $this->assertTrue($this->subject->isCmd("exit-code: 1\n     output: syntax error"));
        $this->assertTrue($this->subject->isCmd('no such item'));
    }

    public function test_clean_success_output_is_not_a_command_failure(): void
    {
        $this->assertFalse($this->subject->isCmd("exit-code: 0\n     output:   name: CORE_TOCAIMA_2116"));
        // A set/add that produces no client stdout still carries the envelope.
        $this->assertFalse($this->subject->isCmd("exit-code: 0\n     output:"));
    }

    public function test_output_body_strips_the_envelope(): void
    {
        $this->assertSame('name: CORE_TOCAIMA_2116', $this->subject->body("exit-code: 0\n     output:   name: CORE_TOCAIMA_2116"));
        $this->assertSame('bare v6 line', $this->subject->body('bare v6 line'));
    }

    public function test_timeout_and_refused_messages_point_at_different_fixes(): void
    {
        $timeout = $this->subject->connMsg('172.16.16.254', 'action timed out (13)', 2200);
        $this->assertStringContainsString('172.16.16.254:2200', $timeout);
        $this->assertStringContainsString('/ppp active', $timeout); // drift hint

        $refused = $this->subject->connMsg('172.16.16.254', '<connection failed> 172.16.16.254:22 (12)', null);
        $this->assertStringContainsString('PUERTO', $refused); // wrong-port hint
        $this->assertStringContainsString('172.16.16.254:22', $refused);
    }

    /**
     * El caso que reportó el ISP: el CORE abre la sesión y el router rechaza la
     * clave. No es un fallo de conexión —el TCP se estableció— ni un fallo de
     * comando, porque en el router no se ejecutó nada.
     */
    public function test_auth_failure_is_its_own_case(): void
    {
        $real = 'failure: authentication failure (/system/ssh-exec; line 1)';

        $this->assertTrue($this->subject->isAuth($real));
        $this->assertFalse($this->subject->isConn($real), 'El TCP sí se estableció: no es un fallo de conexión.');
    }

    /**
     * La guarda que evita que el detector se coma casos ajenos: «not enough
     * permissions» es el usuario que SÍ entró pero no puede correr el comando,
     * y ése es un fallo de comando de toda la vida.
     */
    public function test_not_enough_permissions_is_still_a_command_failure(): void
    {
        $permisos = "exit-code: 0
     output: not enough permissions (13)";

        $this->assertFalse($this->subject->isAuth($permisos));
        $this->assertTrue($this->subject->isCmd($permisos));
    }

    public function test_connection_failure_is_not_read_as_an_auth_failure(): void
    {
        $this->assertFalse($this->subject->isAuth('failure: closing connection: <connection failed> 172.16.16.254:22 (12)'));
        $this->assertFalse($this->subject->isAuth('action timed out - try again ... (13)'));
    }

    /**
     * El mensaje tiene que decir el salto exacto que falló y las dos causas que
     * desde el panel son indistinguibles: la clave cambiada y el usuario de
     * RouterOS restringido por dirección.
     */
    public function test_auth_failure_message_names_the_hop_and_the_traps(): void
    {
        $msg = $this->subject->authMsg('172.16.16.254', 'failure: authentication failure', 2200);

        $this->assertStringContainsString('172.16.16.254:2200', $msg);
        $this->assertStringContainsString('RECHAZÓ', $msg);
        $this->assertStringContainsString('/user print detail', $msg);  // usuario limitado por address=
        $this->assertStringContainsString('/ppp active print', $msg);   // la IP se movió de router
        $this->assertStringNotContainsString('no se pudo crear', $msg); // ya no culpa al comando
    }

    /**
     * Dentro de una cadena de RouterOS `\` escapa y `$` interpola. Una clave
     * con cualquiera de los dos llegaba deformada al router y volvía como
     * `authentication failure`, idéntica a una credencial equivocada.
     */
    public function test_password_backslash_and_dollar_are_escaped(): void
    {
        $conBarra = $this->subject->build('10.0.0.1', 'admin', 'a\b', '/x', null);
        $this->assertStringContainsString('password="a\\\\b"', $conBarra);

        $conDolar = $this->subject->build('10.0.0.1', 'admin', 'cla$ve', '/x', null);
        $this->assertStringContainsString('password="cla\$ve"', $conDolar);
    }

    public function test_plain_password_is_left_untouched(): void
    {
        $cmd = $this->subject->build('10.0.0.1', 'admin', 'Abc123xyz', '/x', null);
        $this->assertStringContainsString('password="Abc123xyz"', $cmd);
    }
}
