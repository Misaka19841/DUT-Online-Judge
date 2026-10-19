<?php

class RegionalContest {
    public const TABLE = 'regional_contests';
    private static ?bool $tableExistsCache = null;

    public static function tableExists(): bool {
        if (self::$tableExistsCache === null) {
            self::$tableExistsCache = DB::checkTableExists(self::TABLE);
        }
        return self::$tableExistsCache;
    }

    public static function fetchVisible(?int $limit = null): array {
        if (!self::tableExists()) {
            return [];
        }

        $query = [
            'select id, title, city_name, province_name, venue, longitude, latitude, contest_time, side, teams_summary, note, contest_url, accent_color, display_order',
            'from', self::TABLE,
            'where', ['is_hidden' => 0],
            'order by contest_time asc, display_order asc, id asc'
        ];

        if ($limit !== null) {
            $query[] = DB::limit($limit);
        }

        $rows = DB::selectAll($query);
        if (!is_array($rows)) {
            return [];
        }

        $events = array_map([self::class, 'normalizeRow'], $rows);
        return self::attachLayout($events);
    }

    public static function getEmptyMessage(): string {
        if (!self::tableExists()) {
            return '区域赛数据表尚未创建，执行建表 SQL 后这里会自动展示。';
        }
        return '暂时还没有可展示的区域赛记录。';
    }

    public static function recommendBoardHeight(array $events): int {
        if (empty($events)) {
            return 760;
        }

        $leftCount = 0;
        $rightCount = 0;
        foreach ($events as $event) {
            if ($event['side'] === 'right') {
                $rightCount++;
            } else {
                $leftCount++;
            }
        }

        $dominant = max($leftCount, $rightCount);
        return max(760, min(1280, 620 + max(0, $dominant - 2) * 150));
    }

    public static function buildClientPayload(array $events, int $boardHeight): array {
        $payload = [];
        foreach ($events as $event) {
            $payload[] = [
                'id' => $event['id'],
                'title' => $event['title'],
                'city_name' => $event['city_name'],
                'display_time' => $event['display_time'],
                'longitude' => $event['longitude'],
                'latitude' => $event['latitude'],
                'side' => $event['side'],
                'accent_color' => $event['accent_color'],
                'position_pct' => $event['position_pct'],
                'timeline_y' => self::positionToY($event['position_pct'], $boardHeight)
            ];
        }
        return $payload;
    }

    private static function normalizeRow(array $row): array {
        $dt = date_create($row['contest_time']);
        $timestamp = $dt ? $dt->getTimestamp() : time();
        $fallbackSide = ($row['side'] ?? '') === 'right' ? 'right' : 'left';

        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'city_name' => (string)$row['city_name'],
            'province_name' => (string)($row['province_name'] ?? ''),
            'venue' => (string)($row['venue'] ?? ''),
            'longitude' => (float)$row['longitude'],
            'latitude' => (float)$row['latitude'],
            'contest_time' => (string)$row['contest_time'],
            'timestamp' => $timestamp,
            'display_time' => self::formatDisplayTime((string)$row['contest_time']),
            'side' => self::detectContestSide((string)$row['title'], $fallbackSide),
            'teams_summary' => trim((string)($row['teams_summary'] ?? '')),
            'note' => trim((string)($row['note'] ?? '')),
            'contest_url' => trim((string)($row['contest_url'] ?? '')),
            'accent_color' => self::normalizeAccentColor((string)($row['accent_color'] ?? '')),
            'display_order' => (int)($row['display_order'] ?? 0)
        ];
    }

    private static function attachLayout(array $events): array {
        $count = count($events);
        if ($count === 0) {
            return $events;
        }

        $sorted = $events;
        usort($sorted, function ($a, $b) {
            if ($a['timestamp'] !== $b['timestamp']) {
                return $a['timestamp'] <=> $b['timestamp'];
            }
            if ($a['display_order'] !== $b['display_order']) {
                return $a['display_order'] <=> $b['display_order'];
            }
            return $a['id'] <=> $b['id'];
        });

        $lower = 4.0;
        $upper = 96.0;
        $span = $upper - $lower;
        $timestamps = array_values(array_unique(array_map(function ($event) {
            return $event['timestamp'];
        }, $sorted)));
        sort($timestamps, SORT_NUMERIC);

        $timestampPositions = [];
        $timestampCount = count($timestamps);
        $minTimestamp = $timestamps[0];
        $maxTimestamp = $timestamps[$timestampCount - 1];

        if ($timestampCount === 1 || $minTimestamp === $maxTimestamp) {
            $center = 50.0;
            foreach ($timestamps as $timestamp) {
                $timestampPositions[$timestamp] = $center;
            }
        } else {
            foreach ($timestamps as $timestamp) {
                $timestampPositions[$timestamp] = $lower + $span * ($timestamp - $minTimestamp) / ($maxTimestamp - $minTimestamp);
            }

            $minGap = max(7.0, min(14.0, $span / ($timestampCount + 1)));
            for ($i = 1; $i < $timestampCount; $i++) {
                $current = $timestamps[$i];
                $prev = $timestamps[$i - 1];
                $timestampPositions[$current] = max($timestampPositions[$current], $timestampPositions[$prev] + $minGap);
            }
            for ($i = $timestampCount - 2; $i >= 0; $i--) {
                $current = $timestamps[$i];
                $next = $timestamps[$i + 1];
                $timestampPositions[$current] = min($timestampPositions[$current], $timestampPositions[$next] - $minGap);
            }

            $firstTimestamp = $timestamps[0];
            $lastTimestamp = $timestamps[$timestampCount - 1];
            if ($timestampPositions[$firstTimestamp] < $lower || $timestampPositions[$lastTimestamp] > $upper) {
                foreach ($timestamps as $i => $timestamp) {
                    $timestampPositions[$timestamp] = $lower + $span * ($i + 1) / ($timestampCount + 1);
                }
            }
        }

        foreach ($events as &$event) {
            $event['position_pct'] = round($timestampPositions[$event['timestamp']], 2);
        }
        unset($event);

        return $events;
    }

    private static function formatDisplayTime(string $rawTime): string {
        $dt = date_create($rawTime);
        if (!$dt) {
            return $rawTime;
        }

        if ($dt->format('H:i:s') === '00:00:00') {
            return $dt->format('Y-m-d');
        }
        return $dt->format('Y-m-d H:i');
    }

    private static function normalizeAccentColor(string $color): string {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtoupper($color);
        }
        return '#D9534F';
    }

    private static function detectContestSide(string $title, string $fallbackSide): string {
        $normalizedTitle = strtoupper($title);
        if (strpos($normalizedTitle, 'ICPC') !== false) {
            return 'left';
        }
        if (strpos($normalizedTitle, 'CCPC') !== false) {
            return 'right';
        }
        return $fallbackSide;
    }

    private static function positionToY(float $positionPct, int $boardHeight): float {
        $top = 20;
        $bottom = 20;
        $usableHeight = max(1, $boardHeight - $top - $bottom);
        return round($top + $usableHeight * $positionPct / 100, 2);
    }
}
