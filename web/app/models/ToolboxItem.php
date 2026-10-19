<?php

class ToolboxItem {
    public const TABLE = 'toolbox_items';
    private static ?bool $tableExistsCache = null;

    public static function tableExists(): bool {
        if (self::$tableExistsCache === null) {
            self::$tableExistsCache = DB::checkTableExists(self::TABLE);
        }
        return self::$tableExistsCache;
    }

    public static function fetchVisible(): array {
        $items = [self::builtinCppReference()];

        if (!self::tableExists()) {
            return $items;
        }

        $rows = DB::selectAll([
            'select id, title, subtitle, tool_url, tile_color, grid_x, grid_y, grid_w, grid_h, display_order',
            'from', self::TABLE,
            'where', ['is_hidden' => 0],
            'order by grid_y asc, grid_x asc, display_order asc, id asc'
        ]);
        if (!is_array($rows)) {
            return $items;
        }

        foreach ($rows as $row) {
            $items[] = self::normalizeRow($row);
        }
        return $items;
    }

    public static function getNotice(): ?string {
        if (!self::tableExists()) {
            return '工具箱数据表尚未创建，当前先展示内置工具。';
        }
        return null;
    }

    private static function builtinCppReference(): array {
        return [
            'id' => 'builtin-cpp-reference',
            'title' => 'CPP reference',
            'subtitle' => 'C++语言特性一览，赛时可参考',
            'tool_url' => '/cppr/reference/zh/cpp.html',
            'tile_color' => '#D9ECFF',
            'grid_x' => 0,
            'grid_y' => 0,
            'grid_w' => 1,
            'grid_h' => 1,
            'is_builtin' => true
        ];
    }

    private static function normalizeRow(array $row): array {
        return [
            'id' => (int)$row['id'],
            'title' => trim((string)$row['title']),
            'subtitle' => trim((string)($row['subtitle'] ?? '')),
            'tool_url' => trim((string)($row['tool_url'] ?? '')),
            'tile_color' => self::normalizeColor((string)($row['tile_color'] ?? '')),
            'grid_x' => max(0, (int)($row['grid_x'] ?? 0)),
            'grid_y' => max(0, (int)($row['grid_y'] ?? 0)),
            'grid_w' => max(1, min(4, (int)($row['grid_w'] ?? 1))),
            'grid_h' => max(1, min(4, (int)($row['grid_h'] ?? 1))),
            'is_builtin' => false
        ];
    }

    private static function normalizeColor(string $color): string {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtoupper($color);
        }
        return '#EAF2FF';
    }
}
