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
            return 560;
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
        return max(560, min(980, 420 + max(0, $dominant - 2) * 110));
    }

    public static function buildClientPayload(array $events, int $boardHeight): array {
        $payload = [];
        foreach ($events as $event) {
            $payload[] = [
                'id' => $event['id'],
                'title' => $event['title'],
                'city_name' => $event['city_name'],
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
            'side' => ($row['side'] ?? '') === 'right' ? 'right' : 'left',
            'teams_summary' => trim((string)($row['teams_summary'] ?? '')),
            'note' => trim((string)($row['note'] ?? '')),
            'contest_url' => trim((string)($row['contest_url'] ?? '')),
            'accent_color' => self::normalizeAccentColor((string)($row['accent_color'] ?? '')),
            'display_order' => (int)($row['display_order'] ?? 0)
        ];
    }

    private static function attachLayout(array $events): array {
        $left = [];
        $right = [];

        foreach ($events as $event) {
            if ($event['side'] === 'right') {
                $right[] = $event;
            } else {
                $left[] = $event;
            }
        }

        $left = self::assignSidePositions($left);
        $right = self::assignSidePositions($right);

        $merged = [];
        foreach ([$left, $right] as $bucket) {
            foreach ($bucket as $event) {
                $merged[$event['id']] = $event;
            }
        }

        $result = [];
        foreach ($events as $event) {
            $result[] = $merged[$event['id']];
        }
        return $result;
    }

    private static function assignSidePositions(array $events): array {
        $count = count($events);
        if ($count === 0) {
            return $events;
        }

        usort($events, function ($a, $b) {
            if ($a['timestamp'] !== $b['timestamp']) {
                return $a['timestamp'] <=> $b['timestamp'];
            }
            if ($a['display_order'] !== $b['display_order']) {
                return $a['display_order'] <=> $b['display_order'];
            }
            return $a['id'] <=> $b['id'];
        });

        $lower = 12.0;
        $upper = 88.0;
        $span = $upper - $lower;
        $positions = [];

        $minTimestamp = $events[0]['timestamp'];
        $maxTimestamp = $events[$count - 1]['timestamp'];
        if ($count === 1 || $minTimestamp === $maxTimestamp) {
            for ($i = 0; $i < $count; $i++) {
                $positions[$i] = $count === 1
                    ? 50.0
                    : $lower + $span * ($i + 1) / ($count + 1);
            }
        } else {
            for ($i = 0; $i < $count; $i++) {
                $positions[$i] = $lower + $span * ($events[$i]['timestamp'] - $minTimestamp) / ($maxTimestamp - $minTimestamp);
            }

            $minGap = max(7.0, min(14.0, $span / ($count + 1)));
            for ($i = 1; $i < $count; $i++) {
                $positions[$i] = max($positions[$i], $positions[$i - 1] + $minGap);
            }
            for ($i = $count - 2; $i >= 0; $i--) {
                $positions[$i] = min($positions[$i], $positions[$i + 1] - $minGap);
            }

            if ($positions[0] < $lower || $positions[$count - 1] > $upper) {
                for ($i = 0; $i < $count; $i++) {
                    $positions[$i] = $lower + $span * ($i + 1) / ($count + 1);
                }
            }
        }

        foreach ($events as $i => &$event) {
            $event['position_pct'] = round($positions[$i], 2);
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

    private static function positionToY(float $positionPct, int $boardHeight): float {
        $top = 60;
        $bottom = 60;
        $usableHeight = max(1, $boardHeight - $top - $bottom);
        return round($top + $usableHeight * $positionPct / 100, 2);
    }
}
