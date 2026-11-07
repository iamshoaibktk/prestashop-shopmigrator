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
    /** Exception code: the old server didn't answer or was busy; the same request may work again. */
    public const NETWORK = 1001;

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
            if ($status === 404) {
                throw new \RuntimeException('The connector was not found at ' . $this->endpoint() . '. Install Shop Migrator Bridge on the old shop.');
            }
            if ($status === 403) {
                throw new \RuntimeException('The old shop\'s server refused ' . $this->endpoint() . ' (HTTP 403). PrestaShop 9 blocks PHP files in /modules: update Shop Migrator Bridge to 1.0.2 or later, which allows its api.php.');
            }
            // 502, 503, 504, an empty answer…: a busy or restarting server, worth trying again.
            throw new \RuntimeException('The old shop sent an unexpected answer (HTTP ' . $status . ').', $status === 0 || $status >= 500 ? self::NETWORK : 0);
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

    /**
     * @param array{0: string, 1: int}|null $filter only rows whose column is above the value
     *
     * @return array{rows: array, next: mixed}
     */
    public function rows(string $table, mixed $cursor, int $limit, ?array $filter = null): array
    {
        $r = $this->call('rows', ['table' => $table, 'cursor' => $cursor === null ? '' : json_encode($cursor), 'limit' => $limit, 'filter' => $filter ? json_encode($filter) : '']);

        return ['rows' => $r['rows'], 'next' => $r['next']];
    }

    /** Every row of a small table (countries, module settings…), page by page. */
    public function allRows(string $table): array
    {
        $rows = [];
        $cursor = null;
        do {
            $r = $this->rows($table, $cursor, 2000);
            array_push($rows, ...$r['rows']);
            $cursor = $r['next'];
        } while ($cursor !== null);

        return $rows;
    }

    /**
     * Several images downloaded at the same time (one connection each). A transfer that stopped
     * early (connection cut, fewer bytes than the old server announced) is downloaded again.
     *
     * @param int[] $ids
     *
     * @return array<int, string|null> id => raw bytes, null when the old shop has no file
     */
    public function images(string $kind, array $ids): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($ids as $id) {
            $ch = curl_init($this->endpoint());
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['key' => $this->key, 'action' => 'image', 'kind' => $kind, 'id' => $id]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'ShopMigrator/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }
        $results = [];
        do {
            $status = curl_multi_exec($mh, $running);
            while ($done = curl_multi_info_read($mh)) {
                $results[spl_object_id($done['handle'])] = (int) $done['result'];
            }
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        while ($done = curl_multi_info_read($mh)) {
            $results[spl_object_id($done['handle'])] = (int) $done['result'];
        }
        $out = [];
        $retry = [];
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $whole = ($results[spl_object_id($ch)] ?? CURLE_OK) === CURLE_OK && self::whole($ch);
            $out[$id] = $code === 200 && $whole && $body !== '' && $body !== null ? $body : null;
            if ($out[$id] === null && $code !== 404) {
                $retry[] = $id; // busy server, cut connection or network hiccup, not a missing file
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        foreach ($retry as $id) {
            $out[$id] = $this->image($kind, $id);
        }

        return $out;
    }

    /**
     * Raw original image bytes, or null when the old shop has no file. A cut transfer is tried
     * once more; if it is cut again, the partial bytes are returned and Images reports the file.
     */
    public function image(string $kind, int $id): ?string
    {
        $fields = ['key' => $this->key, 'action' => 'image', 'kind' => $kind, 'id' => $id];
        for ($try = 1; ; ++$try) {
            try {
                [$status, $body, $whole] = $this->post($fields, false);
            } catch (\RuntimeException $e) {
                if ($try < 2) {
                    continue;
                }
                throw $e;
            }
            if ($status !== 200 || $body === '') {
                return null;
            }
            if ($whole || $try >= 2) {
                return $body;
            }
        }
    }

    /** @return array{0: int, 1: string, 2: bool} status, body, whether every announced byte arrived */
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
            throw new \RuntimeException('Could not reach the old shop: ' . $error, self::NETWORK);
        }

        return [$status, (string) $body, self::whole($ch)];
    }

    /** False when the server announced a length (Content-Length) and fewer bytes arrived. */
    private static function whole(\CurlHandle $ch): bool
    {
        $announced = (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $received = (float) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);

        return $announced <= 0 || $received >= $announced;
    }
}
