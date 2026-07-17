<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\siem;

use Carbon\Carbon;
use craft\helpers\App;
use RuntimeException;

/**
 * SyslogFramer builds RFC 5424 syslog frames and writes them over a `tls://`
 * stream socket. It is the extraction of Password Policy's SIEM transport: same
 * frame shape, same TLS options, same connect + write timeout guarding against a
 * half-open peer.
 *
 * Frame shape:
 *
 *     <PRI>1 TIMESTAMP HOSTNAME APP-NAME PROCID MSGID - MSG
 *
 * where PRI = facility * 8 + severity (133 for local0.notice), TIMESTAMP is
 * ISO 8601 with a literal `Z`, and MSG is the canonical JSON body. The body is
 * produced by the caller through {@see \craftpulse\auditkit\engine\Canonicalizer},
 * so the syslog body, the webhook body, and the JSONL export body are byte-identical.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class SyslogFramer
{
    // Const Properties
    // =========================================================================

    /**
     * @var int RFC 5424 facility "local0" — the conventional application
     * facility.
     *
     * @since 1.0.0
     */
    public const SYSLOG_FACILITY_LOCAL0 = 16;

    /**
     * @var int RFC 5424 severity "notice".
     *
     * @since 1.0.0
     */
    public const SYSLOG_SEVERITY_NOTICE = 5;

    /**
     * @var int Connect + write timeout in seconds.
     *
     * @since 1.0.0
     */
    public const TLS_CONNECT_TIMEOUT = 5;

    // Public Methods
    // =========================================================================

    /**
     * Builds an RFC 5424 syslog frame around a pre-canonicalised body.
     *
     * @param string $body the canonical JSON message
     * @param string $appName the APP-NAME field (the emitting plugin handle)
     * @param string $msgId the MSGID field
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function frame(string $body, string $appName = 'audit-kit', string $msgId = 'audit-log'): string
    {
        $priority = (self::SYSLOG_FACILITY_LOCAL0 * 8) + self::SYSLOG_SEVERITY_NOTICE;

        return sprintf(
            '<%d>1 %s %s %s %s %s - %s',
            $priority,
            Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            (string)(gethostname() ?: '-'),
            $appName,
            (string)getmypid() ?: '-',
            $msgId,
            $body,
        );
    }

    /**
     * Opens a TLS stream to `$host:$port` and writes the frame. Throws on any
     * failure so the caller records it against the circuit breaker.
     *
     * @param string $host the SIEM host
     * @param int $port the SIEM port
     * @param string $frame the RFC 5424 frame
     * @param bool $verifyCert whether to verify the peer certificate
     * @param string|null $caBundlePath an optional CA bundle path (env-resolved)
     *
     * @throws RuntimeException on connect failure, handshake error, timeout, or
     * partial write
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function send(
        string $host,
        int $port,
        string $frame,
        bool $verifyCert = true,
        ?string $caBundlePath = null,
    ): void {
        $contextOptions = [
            'ssl' => [
                'verify_peer' => $verifyCert,
                'verify_peer_name' => $verifyCert,
            ],
        ];

        if ($caBundlePath !== null && $caBundlePath !== '') {
            $resolved = App::parseEnv($caBundlePath);

            if (is_string($resolved) && $resolved !== '') {
                $contextOptions['ssl']['cafile'] = $resolved;
            }
        }

        $context = stream_context_create($contextOptions);
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            sprintf('tls://%s:%d', $host, $port),
            $errno,
            $errstr,
            self::TLS_CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'TLS connect failed (%d): %s',
                $errno,
                $errstr !== '' ? $errstr : 'unknown error',
            ));
        }

        // The connect timeout does not cover the write — a half-open peer that
        // accepts the connection but never drains the buffer would otherwise
        // stall until the job TTR elapses. stream_set_timeout + the timed_out
        // check turn that stall into a thrown exception the breaker records.
        stream_set_timeout($socket, self::TLS_CONNECT_TIMEOUT);

        $payload = $frame . "\n";
        $written = @fwrite($socket, $payload);

        $meta = stream_get_meta_data($socket);
        @fclose($socket);

        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('TLS write timed out; the SIEM peer accepted the connection but did not drain the frame.');
        }

        if ($written === false || $written < strlen($payload)) {
            throw new RuntimeException('TLS write was incomplete or failed.');
        }
    }
}
