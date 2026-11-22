<?php

namespace App\Services;

/**
 * "Live display": the last scans of each channel, so a laptop can show what a
 * Honeywell scanner just scanned. Stored as small JSON files (no database).
 */
class LiveFeed
{
    private const KEEP = 30;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/live');
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public static function cleanChannel(?string $c): string
    {
        $c = trim((string) $c);
        $c = preg_replace('/[^\p{L}\p{N}_\-]+/u', '-', $c);
        $c = mb_substr(trim($c, '-'), 0, 40);

        return $c !== '' ? $c : 'main';
    }

    private function path(string $channel): string
    {
        return $this->dir.'/'.substr(sha1($channel), 0, 16).'.json';
    }

    /** Append an event and return it (with its id). */
    public function push(string $channel, array $event): array
    {
        $path = $this->path($channel);
        $fh = fopen($path, 'c+');
        flock($fh, LOCK_EX);
        try {
            $raw = stream_get_contents($fh);
            $data = json_decode($raw ?: '', true);
            if (! is_array($data)) {
                $data = ['last_id' => 0, 'events' => []];
            }
            $event['id'] = ++$data['last_id'];
            $event['ts'] = now()->toIso8601String();
            $data['events'][] = $event;
            $data['events'] = array_slice($data['events'], -self::KEEP);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $event;
    }

    /** Events newer than $after (all recent ones when $after = 0). */
    public function since(string $channel, int $after = 0): array
    {
        $path = $this->path($channel);
        if (! is_file($path)) {
            return ['last_id' => 0, 'events' => []];
        }
        $fh = fopen($path, 'r');
        flock($fh, LOCK_SH);
        $data = json_decode((string) stream_get_contents($fh), true);
        flock($fh, LOCK_UN);
        fclose($fh);
        if (! is_array($data)) {
            return ['last_id' => 0, 'events' => []];
        }
        $events = array_values(array_filter($data['events'], fn ($e) => $e['id'] > $after));

        return ['last_id' => $data['last_id'], 'events' => $events];
    }
}
