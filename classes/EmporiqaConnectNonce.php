<?php
/**
 * One-click connect state: (sha256(state), PKCE verifier) rows.
 *
 * A row lives through three stages: pending (initiate, up to TTL_SECONDS),
 * exchanging (callback claimed it and is calling /connect/exchange, up to
 * EXCHANGING_TTL_SECONDS), then deleted when the exchange returns. Emporiqa
 * proves the shop origin during the exchange by asking the action
 * controller for HMAC(verifier, nonce), so the verifier must survive the
 * claim.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaConnectNonce
{
    public const TTL_SECONDS = 300;

    public const EXCHANGING_TTL_SECONDS = 120;

    private const TABLE = 'emporiqa_connect_nonce';

    /**
     * Store a pending (state, verifier). State is hashed so a database leak
     * cannot complete an in-flight handshake.
     */
    public static function store(string $state, string $verifier): bool
    {
        $db = Db::getInstance();
        $now = time();
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` '
            . 'WHERE `created_at` < ' . (int) ($now - self::TTL_SECONDS)
            . ' AND (`exchanging_at` IS NULL OR `exchanging_at` < ' . (int) ($now - self::EXCHANGING_TTL_SECONDS) . ')',
        );

        return (bool) $db->insert(self::TABLE, [
            'state_hash' => pSQL(hash('sha256', $state)),
            'verifier' => pSQL($verifier),
            'created_at' => (int) $now,
        ]);
    }

    /**
     * Claim a pending row for the exchange. Only the request whose UPDATE
     * affects the row wins, so a second tab or a replayed callback gets null.
     */
    public static function beginExchange(string $state): ?string
    {
        $db = Db::getInstance();
        $now = time();
        $where = '`state_hash` = "' . pSQL(hash('sha256', $state)) . '"';
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET `exchanging_at` = ' . (int) $now
            . ' WHERE ' . $where . ' AND `exchanging_at` IS NULL'
            . ' AND `created_at` >= ' . (int) ($now - self::TTL_SECONDS),
        );
        if ((int) $db->Affected_Rows() < 1) {
            return null;
        }
        $verifier = $db->getValue('SELECT `verifier` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $where);

        return is_string($verifier) && $verifier !== '' ? $verifier : null;
    }

    /**
     * The verifier of an exchange in flight for $state, else null. Pending
     * rows never answer: only an exchange this shop itself started can.
     */
    public static function exchangingVerifier(string $state): ?string
    {
        $verifier = Db::getInstance()->getValue(
            'SELECT `verifier` FROM `' . _DB_PREFIX_ . self::TABLE . '` '
            . 'WHERE `state_hash` = "' . pSQL(hash('sha256', $state)) . '" '
            . 'AND `exchanging_at` >= ' . (int) (time() - self::EXCHANGING_TTL_SECONDS),
        );

        return is_string($verifier) && $verifier !== '' ? $verifier : null;
    }

    public static function finish(string $state): void
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` '
            . 'WHERE `state_hash` = "' . pSQL(hash('sha256', $state)) . '"',
        );
    }
}
