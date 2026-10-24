<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * Talks to the Shop Migrator Bridge installed on the old shop. Read-only calls; data goes
 * directly from the old server to this one.
 */
class Client
{
    private ?\CurlHandle $ch = null;

    public function __construct(private string $shopUrl, private string $key)
    {
        $this->shopUrl = rtrim($shopUrl, '/') . '/';
    }

    public function endpoint(): string
    {
        return $this->shopUrl . 'modules/shopmigratorbridge/api.php';
    }

    /** @throws \RuntimeException */
    public function call(string $action, array $params = []): array
    {
        [$status, $body] = $this->post(array_merge($params, ['key' => $this->key, 'action' => $action]));
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException($status === 404
                ? 'The connector was not found at ' . $this->endpoint() . '. Install Shop Migrator Bridge on the old shop.'
                : 'The old shop sent an unexpected answer (HTTP ' . $status . ').');
        }
        if (empty($data['ok'])) {
            throw new \RuntimeException((string) ($data['error'] ?? 'Unknown error from the old shop'));
        }

        return $data;
    }

    public function info(): array
    {
        return $this->call('info');
    }

    /** @return array<string, array{columns: array, pk: string[]}> */
    public function describe(array $tables): array
    {
        return $tables ? $this->call('describe', ['tables' => $tables])['tables'] : [];
    }

    /** @return array<string, int> */
    public function count(array $tables): array
    {
        return $tables ? $this->call('count', ['tables' => $tables])['counts'] : [];
    }

    /** @return array{rows: array, next: mixed} */
    /** @param array{0: string, 1: int}|null $filter only rows whose column is above the value */
    public function rows(string $table, mixed $cursor, int $limit, ?array $filter = null): array
    {
        $r = $this->call('rows', ['table' => $table, 'cursor' => $cursor === null ? '' : json_encode($cursor), 'limit' => $limit, 'filter' => $filter ? json_encode($filter) : '']);

        return ['rows' => $r['rows'], 'next' => $r['next']];
    }

    /** Raw original image bytes, or null when the old shop has no file. */
    public function image(string $kind, int $id): ?string
    {
        [$status, $body] = $this->post(['key' => $this->key, 'action' => 'image', 'kind' => $kind, 'id' => $id], false);

        return $status === 200 && $body !== '' ? $body : null;
    }

    /** @return array{0: int, 1: string} */
    private function post(array $fields, bool $json = true): array
    {
        // One connection for the whole tick (keep-alive): no new TCP/TLS handshake per batch.
        if ($this->ch === null) {
            $this->ch = curl_init();
        } else {
            curl_reset($this->ch);
        }
        $ch = $this->ch;
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '', // accept gzip
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'ShopMigrator/1.0',
            CURLOPT_HTTPHEADER => $json ? ['Accept: application/json'] : [],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($body === false) {
            throw new \RuntimeException('Could not reach the old shop: ' . $error);
        }

        return [$status, (string) $body];
    }
}
