<?php

class UOJProblemBatchManager {
    public static function parseProblemIdList(string $raw): array {
        $raw = str_replace([',', "\r", "\n", "\t"], ' ', $raw);
        $tokens = preg_split('/\s+/', trim($raw));
        $ids = [];
        $seen = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^(\d+)-(\d+)$/', $token, $matches)) {
                $l = (int)$matches[1];
                $r = (int)$matches[2];
                if ($l > $r) {
                    throw new Exception("无效区间：{$token}");
                }
                for ($i = $l; $i <= $r; $i++) {
                    if (!isset($seen[$i])) {
                        $ids[] = $i;
                        $seen[$i] = true;
                    }
                }
                continue;
            }
            if (!validateUInt($token)) {
                throw new Exception("无效题号：{$token}");
            }
            $id = (int)$token;
            if (!isset($seen[$id])) {
                $ids[] = $id;
                $seen[$id] = true;
            }
        }

        if (!$ids) {
            throw new Exception('题号列表不能为空');
        }
        return $ids;
    }

    public static function parseTags(string $raw): array {
        $raw = str_replace('，', ',', $raw);
        $tags_raw = explode(',', $raw);
        $tags = [];
        foreach ($tags_raw as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            if (strlen($tag) > 30) {
                throw new Exception('标签“'.HTML::escape($tag).'”太长');
            }
            if (in_array($tag, $tags, true)) {
                continue;
            }
            $tags[] = $tag;
        }
        if (count($tags) > 15) {
            throw new Exception('标签个数不能超过15');
        }
        return $tags;
    }

    public static function updateProblemTags(UOJProblem $problem, array $tags, string $mode): array {
        $orig_tags = $problem->queryTags();
        if ($mode === 'replace') {
            $new_tags = $tags;
        } elseif ($mode === 'add') {
            $new_tags = $orig_tags;
            foreach ($tags as $tag) {
                if (!in_array($tag, $new_tags, true)) {
                    $new_tags[] = $tag;
                }
            }
        } elseif ($mode === 'remove') {
            $new_tags = array_values(array_filter($orig_tags, fn($tag) => !in_array($tag, $tags, true)));
        } elseif ($mode === 'clear') {
            $new_tags = [];
        } else {
            throw new Exception('无效标签操作');
        }

        $problem->updateTags($new_tags);
        $problem->tags = $new_tags;
        return $new_tags;
    }

    public static function updateProblemVisibility(UOJProblem $problem, bool $is_hidden): void {
        $cur_hidden = (bool)$problem->info['is_hidden'];
        if ($cur_hidden === $is_hidden) {
            return;
        }

        if (!$is_hidden) {
            $contest_problem_pairs = DB::selectAll([
                "select * from contests_problems",
                "where", ["problem_id" => $problem->info['id']]
            ]);
            foreach ($contest_problem_pairs as $pair) {
                $contest = UOJContest::query($pair['contest_id']);
                if ($contest && $contest->progress() < CONTEST_FINISHED) {
                    throw new Exception("题目 #{$problem->info['id']} 出现在未结束比赛中，不能公开");
                }
            }
        }

        DB::update([
            "update problems",
            "set", ["is_hidden" => $is_hidden ? 1 : 0],
            "where", ["id" => $problem->info['id']]
        ]);
        DB::update([
            "update submissions",
            "set", ["is_hidden" => $is_hidden ? 1 : 0],
            "where", ["problem_id" => $problem->info['id']]
        ]);
        DB::update([
            "update hacks",
            "set", ["is_hidden" => $is_hidden ? 1 : 0],
            "where", ["problem_id" => $problem->info['id']]
        ]);
        $problem->info['is_hidden'] = $is_hidden ? 1 : 0;
    }

    public static function importFromArchive(string $zip_file_name, array $options = []): array {
        $options += [
            'default_hidden' => true
        ];

        $tmp_root = '/tmp/uoj_problem_import_' . uojRandString(20);
        if (!mkdir($tmp_root, 0777, true) && !is_dir($tmp_root)) {
            throw new Exception('创建临时目录失败');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($zip_file_name) !== true) {
                throw new Exception('导入压缩包打开失败');
            }
            if (!$zip->extractTo($tmp_root)) {
                $zip->close();
                throw new Exception('导入压缩包解压失败');
            }
            $zip->close();

            $root = self::normalizeExtractRoot($tmp_root);
            $manifest = self::loadManifest($root);
            $contest = self::loadContest($manifest);

            $report = [
                'contest_id' => $contest ? $contest->info['id'] : null,
                'success' => [],
                'failed' => []
            ];

            foreach ($manifest['problems'] as $problem_cfg) {
                try {
                    $problem = self::importSingleProblem($root, $problem_cfg, $contest, $options);
                    $report['success'][] = [
                        'path' => $problem_cfg['path'],
                        'id' => $problem->info['id'],
                        'title' => $problem->info['title']
                    ];
                } catch (Exception $e) {
                    $report['failed'][] = [
                        'path' => $problem_cfg['path'] ?? '(unknown)',
                        'error' => $e->getMessage()
                    ];
                }
            }

            return $report;
        } finally {
            self::removeDir($tmp_root);
        }
    }

    private static function normalizeExtractRoot(string $root): string {
        $entries = FS::scandir($root);
        if (count($entries) === 1 && is_dir("{$root}/{$entries[0]}")) {
            return "{$root}/{$entries[0]}";
        }
        return $root;
    }

    private static function loadManifest(string $root): array {
        $manifest_file = "{$root}/manifest.json";
        if (is_file($manifest_file)) {
            $manifest = json_decode(file_get_contents($manifest_file), true);
            if (!is_array($manifest)) {
                throw new Exception('manifest.json 不是合法 JSON');
            }
            if (!isset($manifest['problems']) || !is_array($manifest['problems'])) {
                throw new Exception('manifest.json 缺少 problems 数组');
            }
            $problems = [];
            foreach ($manifest['problems'] as $problem_cfg) {
                if (!is_array($problem_cfg) || !isset($problem_cfg['path'])) {
                    throw new Exception('manifest.json 中存在无效题目项');
                }
                $problems[] = $problem_cfg;
            }
            $manifest['problems'] = $problems;
            return $manifest;
        }

        if (is_file("{$root}/title.txt") && is_file("{$root}/statement.md")) {
            return [
                'problems' => [
                    ['path' => '.']
                ]
            ];
        }

        $problems = [];
        foreach (FS::scandir($root) as $name) {
            if (is_dir("{$root}/{$name}")) {
                $problems[] = ['path' => $name];
            }
        }
        if (!$problems) {
            throw new Exception('未找到 manifest.json，也没有可导入的题目目录');
        }
        return ['problems' => $problems];
    }

    private static function loadContest(array $manifest): ?UOJContest {
        if (!isset($manifest['contest_id'])) {
            return null;
        }
        if (!validateUInt($manifest['contest_id'])) {
            throw new Exception('manifest.json 中 contest_id 非法');
        }
        $contest = UOJContest::query($manifest['contest_id']);
        if (!$contest) {
            throw new Exception("不存在比赛 #{$manifest['contest_id']}");
        }
        return $contest;
    }

    private static function importSingleProblem(string $root, array $problem_cfg, ?UOJContest $contest, array $options): UOJProblem {
        $problem_dir = self::problemDir($root, $problem_cfg);
        if (!is_dir($problem_dir)) {
            throw new Exception("目录不存在：{$problem_cfg['path']}");
        }

        $title = self::loadProblemTitle($problem_dir, $problem_cfg);
        $statement_md = self::loadProblemStatement($problem_dir, $problem_cfg);
        $statement_html = self::compileStatement($statement_md);
        $data_zip = self::prepareDataZip($problem_dir, $problem_cfg);

        try {
            $problem = self::createProblem($title, $statement_md, $statement_html, $options);

            $err = $problem->uploadDataViaZipFile($data_zip);
            if ($err !== '') {
                throw new Exception("上传数据失败：{$err}");
            }

            $err = $problem->syncData(Auth::user());
            if ($err !== '') {
                throw new Exception("同步数据失败：{$err}");
            }

            if ($contest) {
                DB::insert([
                    "insert ignore into contests_problems",
                    "(contest_id, problem_id)",
                    "values", DB::tuple([$contest->info['id'], $problem->info['id']])
                ]);
            }

            return $problem;
        } finally {
            if ($data_zip !== null && strStartWith($data_zip, '/tmp/uoj_problem_import_data_') && is_file($data_zip)) {
                unlink($data_zip);
            }
        }
    }

    private static function problemDir(string $root, array $problem_cfg): string {
        $path = $problem_cfg['path'];
        if ($path === '.') {
            return $root;
        }
        $path = trim($path, "/\\");
        return "{$root}/{$path}";
    }

    private static function loadProblemTitle(string $problem_dir, array $problem_cfg): string {
        if (isset($problem_cfg['title']) && is_string($problem_cfg['title'])) {
            $title = trim($problem_cfg['title']);
        } else {
            $title_file = $problem_cfg['title_file'] ?? 'title.txt';
            $title_path = "{$problem_dir}/{$title_file}";
            if (!is_file($title_path)) {
                throw new Exception("缺少题名文件：{$title_file}");
            }
            $title = trim(file_get_contents($title_path));
        }
        if ($title === '') {
            throw new Exception('题名不能为空');
        }
        if (strlen($title) > 100) {
            throw new Exception('题名不能超过 100 个字节');
        }
        return HTML::escape($title);
    }

    private static function loadProblemStatement(string $problem_dir, array $problem_cfg): string {
        $statement_file = $problem_cfg['statement_file'] ?? 'statement.md';
        $statement_path = "{$problem_dir}/{$statement_file}";
        if (!is_file($statement_path)) {
            throw new Exception("缺少题面文件：{$statement_file}");
        }
        $statement_md = file_get_contents($statement_path);
        if ($statement_md === false) {
            throw new Exception("读取题面文件失败：{$statement_file}");
        }
        if (strlen($statement_md) > 1000000) {
            throw new Exception('题面过长');
        }
        return $statement_md;
    }

    private static function compileStatement(string $statement_md): string {
        $statement_html = UOJMarkdown::compile_from_markdown($statement_md, ['type' => 'uoj']);
        if ($statement_html === false) {
            throw new Exception('题面 Markdown 编译失败');
        }
        return HTML::purifier()->purify($statement_html);
    }

    private static function prepareDataZip(string $problem_dir, array $problem_cfg): string {
        $zip_name = $problem_cfg['data_zip'] ?? 'data.zip';
        $zip_path = "{$problem_dir}/{$zip_name}";
        if (is_file($zip_path)) {
            self::assertZipContainsProblemConf($zip_path);
            return $zip_path;
        }

        $data_dir_name = $problem_cfg['data_dir'] ?? 'data';
        $data_dir = "{$problem_dir}/{$data_dir_name}";
        if (!is_dir($data_dir)) {
            throw new Exception("缺少数据包：{$zip_name} 或 {$data_dir_name}/");
        }
        if (!is_file("{$data_dir}/problem.conf")) {
            throw new Exception('数据目录中缺少 problem.conf');
        }

        $tmp_zip = '/tmp/uoj_problem_import_data_' . uojRandString(20) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('创建临时数据压缩包失败');
        }
        foreach (FS::scandir_r($data_dir) as $name) {
            $src = "{$data_dir}/{$name}";
            if (is_file($src) && !$zip->addFile($src, $name)) {
                $zip->close();
                throw new Exception("写入临时数据压缩包失败：{$name}");
            }
        }
        $zip->close();
        return $tmp_zip;
    }

    private static function assertZipContainsProblemConf(string $zip_path): void {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new Exception('数据压缩包打开失败');
        }

        $has_problem_conf = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && (basename($name) === 'problem.conf')) {
                $has_problem_conf = true;
                break;
            }
        }
        $zip->close();

        if (!$has_problem_conf) {
            throw new Exception('数据压缩包中缺少 problem.conf');
        }
    }

    private static function createProblem(string $title, string $statement_md, string $statement_html, array $options): UOJProblem {
        if (!function_exists('svnNewProblem')) {
            requirePHPLib('svn');
        }

        $info = [
            'title' => $title,
            'is_hidden' => $options['default_hidden'] ? '1' : '0',
            'submission_requirement' => '{}',
            'extra_config' => json_encode([]),
            'hackable' => '0'
        ];

        DB::insert([
            "insert into problems",
            DB::bracketed_fields(array_keys($info)),
            "values", DB::tuple(array_values($info))
        ]);
        $id = DB::insert_id();
        DB::insert([
            "insert into problems_contents",
            "(id, statement, statement_md)",
            "values", DB::tuple([$id, $statement_html, $statement_md])
        ]);
        svnNewProblem($id);

        $problem = UOJProblem::query($id);
        if (!$problem) {
            throw new Exception("新题 #{$id} 创建后读取失败");
        }
        return $problem;
    }

    private static function removeDir(string $path): void {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (FS::scandir($path) as $name) {
            self::removeDir("{$path}/{$name}");
        }
        @rmdir($path);
    }
}
