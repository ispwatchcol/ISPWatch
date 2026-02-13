<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * MikroTik API Protocol Implementation
 * 
 * Low-level implementation of the MikroTik RouterOS API binary protocol.
 * Handles socket communication, word encoding/decoding, and authentication.
 */
class MikroTikApiProtocol
{
    /**
     * Create a socket connection to a MikroTik router
     * 
     * @param string $host Router IP address
     * @param int $port API port (default: 8728)
     * @param int $timeout Connection timeout in seconds
     * @return resource|false Socket resource or false on failure
     */
    public function connect(string $host, int $port = 8728, int $timeout = 10)
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            Log::error('[MikroTikApiProtocol] Connection failed', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
                'errno' => $errno,
            ]);
            return false;
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $timeout);

        return $socket;
    }

    /**
     * Authenticate with the router via API
     * 
     * @param resource $socket Active socket connection
     * @param string $user Username
     * @param string $pass Password
     * @return bool True if authentication succeeded
     */
    public function login($socket, string $user, string $pass): bool
    {
        $this->sendCommand($socket, '/login', [
            '=name=' . $user,
            '=password=' . $pass,
        ]);

        $response = [];
        $challenge = null;

        while (true) {
            $word = $this->readWord($socket);
            if ($word === '')
                break;

            $response[] = $word;

            if (str_starts_with($word, '=ret=')) {
                $challenge = substr($word, 5);
            }

            if ($word === '!trap') {
                Log::error('[MikroTikApiProtocol] Login trap received');
                return false;
            }
        }

        // Handle MD5 challenge-response authentication (RouterOS < 6.43)
        if ($challenge) {
            $challengeBin = hex2bin($challenge);
            $hash = md5(chr(0) . $pass . $challengeBin);

            $this->sendCommand($socket, '/login', [
                '=name=' . $user,
                '=response=00' . $hash,
            ]);

            while (true) {
                $word = $this->readWord($socket);
                if ($word === '')
                    break;
                if ($word === '!trap')
                    return false;
            }
        }

        return true;
    }

    /**
     * Send an API command with optional parameters
     * 
     * @param resource $socket Active socket connection
     * @param string $command API command (e.g., '/system/identity/print')
     * @param array $params Command parameters (e.g., ['=name=test'])
     * @throws \RuntimeException If socket write fails
     */
    public function sendCommand($socket, string $command, array $params = []): void
    {
        $this->writeWord($socket, $command);
        foreach ($params as $param) {
            $this->writeWord($socket, $param);
        }
        $result = @fwrite($socket, chr(0));
        if ($result === false) {
            throw new \RuntimeException('Socket write failed: Broken pipe or connection closed');
        }
    }

    /**
     * Read all records from an API response
     * 
     * @param resource $socket Active socket connection
     * @param int $maxWords Maximum words to read (safety limit)
     * @return array Array of associative arrays with record data
     */
    public function readAllRecords($socket, int $maxWords = 2000): array
    {
        $records = [];
        $current = [];
        $wordCount = 0;

        while ($wordCount < $maxWords) {
            $word = $this->readWord($socket);
            $wordCount++;

            if ($word === '!re') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                $current = [];
                continue;
            }

            if ($word === '!done' || $word === '!trap') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                break;
            }

            if ($word === '') {
                continue;
            }

            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) {
                    $current[$parts[0]] = $parts[1];
                }
            }
        }

        Log::debug('[MikroTikApiProtocol] readAllRecords completed', [
            'records_count' => count($records),
        ]);

        return $records;
    }

    /**
     * Read response until !done and return error if !trap was received
     * 
     * @param resource $socket Active socket connection
     * @return string|null Error message if trap received, null on success
     */
    public function readUntilDoneWithError($socket): ?string
    {
        $count = 0;
        $trapMessage = null;
        $gotTrap = false;
        $allWords = [];

        while ($count < 100) {
            $word = $this->readWord($socket);
            $count++;
            $allWords[] = $word;

            if ($word === '!trap') {
                $gotTrap = true;
                continue;
            }

            if ($word === '!done') {
                break;
            }

            if ($gotTrap && str_starts_with($word, '=message=')) {
                $trapMessage = substr($word, 9);
            }

            if ($word === '') {
                if ($gotTrap)
                    break;
                continue;
            }
        }

        if ($gotTrap) {
            Log::warning('[MikroTikApiProtocol] Trap received', [
                'message' => $trapMessage,
                'words' => $allWords,
            ]);
        }

        return $trapMessage;
    }

    /**
     * Read response until !done (ignore content)
     * 
     * @param resource $socket Active socket connection
     */
    public function readUntilDone($socket): void
    {
        $count = 0;
        while ($count < 100) {
            $word = $this->readWord($socket);
            $count++;
            if ($word === '!done' || $word === '!trap')
                break;
        }
    }

    /**
     * Write a word to the API socket using length-encoded format
     * 
     * @param resource $socket Active socket connection
     * @param string $word Word to write
     * @throws \RuntimeException If socket write fails
     */
    public function writeWord($socket, string $word): void
    {
        $len = strlen($word);
        $result = false;

        if ($len < 0x80) {
            $result = @fwrite($socket, chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            $result = @fwrite($socket, chr(($len >> 8) & 0xFF));
            if ($result !== false) {
                $result = @fwrite($socket, chr($len & 0xFF));
            }
        } else {
            $result = @fwrite($socket, chr(($len >> 16) & 0xFF));
            if ($result !== false) {
                $result = @fwrite($socket, chr(($len >> 8) & 0xFF));
            }
            if ($result !== false) {
                $result = @fwrite($socket, chr($len & 0xFF));
            }
        }

        if ($result === false) {
            throw new \RuntimeException('Socket write failed: Broken pipe or connection closed');
        }

        $result = @fwrite($socket, $word);
        if ($result === false) {
            throw new \RuntimeException('Socket write failed: Broken pipe or connection closed');
        }
    }

    /**
     * Read a word from the API socket using length-encoded format
     * 
     * @param resource $socket Active socket connection
     * @return string The word read, or empty string on failure
     */
    public function readWord($socket): string
    {
        $byte = @fread($socket, 1);
        if ($byte === '' || $byte === false)
            return '';

        $len = ord($byte);
        if ($len === 0)
            return '';

        if (($len & 0x80) == 0x00) {
            // 1 byte length
        } elseif (($len & 0xC0) == 0x80) {
            $b2 = ord(@fread($socket, 1));
            $len = (($len & 0x3F) << 8) + $b2;
        } elseif (($len & 0xE0) == 0xC0) {
            $b2 = ord(@fread($socket, 1));
            $b3 = ord(@fread($socket, 1));
            $len = (($len & 0x1F) << 16) + ($b2 << 8) + $b3;
        }

        if ($len <= 0)
            return '';

        $data = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = @fread($socket, $remaining);
            if ($chunk === '' || $chunk === false)
                break;
            $data .= $chunk;
            $remaining = $len - strlen($data);
        }

        return $data;
    }

    /**
     * Close a socket connection
     * 
     * @param resource $socket Socket to close
     */
    public function close($socket): void
    {
        if ($socket) {
            @fclose($socket);
        }
    }
}
