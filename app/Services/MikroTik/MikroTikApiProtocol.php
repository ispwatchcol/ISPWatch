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
     * Authenticate with the router via API.
     *
     * IMPORTANT: this method only returns true when an explicit !done reply was
     * received. Just hitting "no !trap" is NOT enough — a closed/timed-out
     * socket also yields zero !trap words, and silently treating that as
     * success has burned us before (subsequent reads return [] and the UI ends
     * up showing "0 records" instead of an auth failure).
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

        $challenge = null;
        $sawDone = false;
        $sawTrap = false;
        $trapMessage = null;

        while (true) {
            $word = $this->readWord($socket);
            if ($word === '') {
                if ($this->isSocketClosed($socket)) {
                    Log::error('[MikroTikApiProtocol] Login: socket closed by router before reply', [
                        'user' => $user,
                    ]);
                    return false;
                }
                // Empty word == sentence terminator. End of this login reply.
                break;
            }

            if ($word === '!done') {
                $sawDone = true;
                continue;
            }

            if ($word === '!trap') {
                $sawTrap = true;
                continue;
            }

            if ($sawTrap && str_starts_with($word, '=message=')) {
                $trapMessage = substr($word, 9);
                continue;
            }

            if (str_starts_with($word, '=ret=')) {
                $challenge = substr($word, 5);
            }
        }

        if ($sawTrap) {
            Log::error('[MikroTikApiProtocol] Login rejected by router', [
                'user' => $user,
                'message' => $trapMessage,
            ]);
            return false;
        }

        // Handle MD5 challenge-response authentication (RouterOS < 6.43)
        if ($challenge) {
            $challengeBin = hex2bin($challenge);
            $hash = md5(chr(0) . $pass . $challengeBin);

            $this->sendCommand($socket, '/login', [
                '=name=' . $user,
                '=response=00' . $hash,
            ]);

            $sawDone = false;
            $sawTrap = false;
            $trapMessage = null;

            while (true) {
                $word = $this->readWord($socket);
                if ($word === '') {
                    if ($this->isSocketClosed($socket)) {
                        Log::error('[MikroTikApiProtocol] Login challenge: socket closed before reply');
                        return false;
                    }
                    break;
                }
                if ($word === '!done') {
                    $sawDone = true;
                    continue;
                }
                if ($word === '!trap') {
                    $sawTrap = true;
                    continue;
                }
                if ($sawTrap && str_starts_with($word, '=message=')) {
                    $trapMessage = substr($word, 9);
                }
            }

            if ($sawTrap) {
                Log::error('[MikroTikApiProtocol] Login challenge rejected', [
                    'user' => $user,
                    'message' => $trapMessage,
                ]);
                return false;
            }
        }

        // Require an explicit !done before declaring success. If neither !done
        // nor a challenge was seen, the router answered something we don't
        // understand (or, more commonly, closed the socket without speaking).
        if (!$sawDone && !$challenge) {
            Log::error('[MikroTikApiProtocol] Login: no !done received and no challenge — treating as failure', [
                'user' => $user,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Like login() but returns a structured result so callers can distinguish
     * wrong credentials (router sent !trap) from Login Protection blocking
     * (router closed the TCP connection before answering).
     *
     * @param resource $socket
     * @return array{success: bool, reason: 'ok'|'trap'|'socket_closed'|'no_response', message: string}
     */
    public function loginDetailed($socket, string $user, string $pass): array
    {
        $this->sendCommand($socket, '/login', [
            '=name=' . $user,
            '=password=' . $pass,
        ]);

        $challenge   = null;
        $sawDone     = false;
        $sawTrap     = false;
        $trapMessage = null;

        while (true) {
            $word = $this->readWord($socket);
            if ($word === '') {
                if ($this->isSocketClosed($socket)) {
                    Log::error('[MikroTikApiProtocol] loginDetailed: socket closed before reply', ['user' => $user]);
                    return ['success' => false, 'reason' => 'socket_closed', 'message' => 'socket_closed'];
                }
                break;
            }
            if ($word === '!done')                            { $sawDone = true; continue; }
            if ($word === '!trap')                            { $sawTrap = true; continue; }
            if ($sawTrap && str_starts_with($word, '=message=')) { $trapMessage = substr($word, 9); continue; }
            if (str_starts_with($word, '=ret='))              { $challenge = substr($word, 5); }
        }

        if ($sawTrap) {
            Log::error('[MikroTikApiProtocol] loginDetailed rejected', ['user' => $user, 'message' => $trapMessage]);
            return ['success' => false, 'reason' => 'trap', 'message' => $trapMessage ?? 'login trap'];
        }

        if ($challenge) {
            $hash = md5(chr(0) . $pass . hex2bin($challenge));
            $this->sendCommand($socket, '/login', ['=name=' . $user, '=response=00' . $hash]);

            $sawDone = false; $sawTrap = false; $trapMessage = null;
            while (true) {
                $word = $this->readWord($socket);
                if ($word === '') {
                    if ($this->isSocketClosed($socket)) {
                        return ['success' => false, 'reason' => 'socket_closed', 'message' => 'socket_closed'];
                    }
                    break;
                }
                if ($word === '!done')                            { $sawDone = true; continue; }
                if ($word === '!trap')                            { $sawTrap = true; continue; }
                if ($sawTrap && str_starts_with($word, '=message=')) { $trapMessage = substr($word, 9); }
            }

            if ($sawTrap) {
                return ['success' => false, 'reason' => 'trap', 'message' => $trapMessage ?? 'login trap (MD5)'];
            }
            if (!$sawDone) {
                return ['success' => false, 'reason' => 'no_response', 'message' => 'no_done_md5'];
            }
            return ['success' => true, 'reason' => 'ok', 'message' => ''];
        }

        if (!$sawDone) {
            Log::error('[MikroTikApiProtocol] loginDetailed: no !done and no challenge', ['user' => $user]);
            return ['success' => false, 'reason' => 'no_response', 'message' => 'no_done'];
        }

        return ['success' => true, 'reason' => 'ok', 'message' => ''];
    }

    /**
     * Cheap "is the socket still alive" check that doesn't consume bytes.
     */
    private function isSocketClosed($socket): bool
    {
        if (!is_resource($socket)) {
            return true;
        }
        $meta = @stream_get_meta_data($socket);
        return !empty($meta['eof']) || feof($socket);
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
     * Read all records from an API response and capture trap status.
     *
     * Unlike readAllRecords(), this method keeps reading after a !trap to
     * collect the =message= word and only stops at !done. Useful when the
     * caller needs to distinguish "0 results" from "router rejected the
     * command" (e.g. missing API policy).
     *
     * @param resource $socket Active socket connection
     * @param int $maxWords Safety limit on words read
     * @return array{records: array, trap: ?string} Records and trap message (if any)
     */
    public function readAllRecordsWithStatus($socket, int $maxWords = 2000): array
    {
        $records = [];
        $current = [];
        $wordCount = 0;
        $trapMessage = null;
        $gotTrap = false;
        $gotDone = false;
        $consecutiveEmpty = 0;
        $closed = false;

        while ($wordCount < $maxWords) {
            $word = $this->readWord($socket);
            $wordCount++;

            if ($word === '!re') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                $current = [];
                $consecutiveEmpty = 0;
                continue;
            }

            if ($word === '!trap') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                $current = [];
                $gotTrap = true;
                $consecutiveEmpty = 0;
                continue;
            }

            if ($word === '!done') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                $gotDone = true;
                $consecutiveEmpty = 0;
                // Consume the final sentence terminator and stop.
                break;
            }

            if ($word === '') {
                $consecutiveEmpty++;
                // If the socket is closed (eof / feof), bail out — reading
                // forever from a dead socket would mask the failure as "0
                // records, no trap", which is what burned us before.
                if ($this->isSocketClosed($socket)) {
                    $closed = true;
                    break;
                }
                // Two empty words in a row, when no !done was seen, means the
                // router isn't sending more data — almost always a half-closed
                // socket. Stop early to avoid spinning until maxWords.
                if ($consecutiveEmpty >= 2 && !$gotDone) {
                    $closed = true;
                    break;
                }
                if ($gotTrap) {
                    break;
                }
                continue;
            }

            $consecutiveEmpty = 0;

            if ($gotTrap && str_starts_with($word, '=message=')) {
                $trapMessage = substr($word, 9);
                continue;
            }

            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) {
                    $current[$parts[0]] = $parts[1];
                }
            }
        }

        Log::debug('[MikroTikApiProtocol] readAllRecordsWithStatus completed', [
            'records_count' => count($records),
            'trap' => $trapMessage,
            'closed_early' => $closed,
            'got_done' => $gotDone,
            'word_count' => $wordCount,
        ]);

        // Closed socket without !done = the router dropped us mid-response.
        // Surface that distinctly so callers don't confuse it with "0 results".
        if ($closed && !$gotDone && !$gotTrap) {
            return [
                'records' => $records,
                'trap' => null,
                'connection_closed' => true,
            ];
        }

        return [
            'records' => $records,
            'trap' => $gotTrap ? ($trapMessage ?? 'unknown error') : null,
            'connection_closed' => false,
        ];
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
