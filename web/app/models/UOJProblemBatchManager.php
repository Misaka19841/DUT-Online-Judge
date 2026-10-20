<?php

class UOJProblemBatchManager {
    private static function normalizeUIntLike($x): ?string {
        if (is_int($x)) {
            return (string)$x;
        }
        if (is_string($x)) {
            return $x;
        }
        return null;
    }

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
                        $ids[] = (string)$i;
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
                $ids[] = (string)$id;
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

    public static function getCurrentMaxProblemId(): int {
        $row = DB::selectFirst([
            "select max(id) as max_id from problems"
        ]);
        if (!$row || $row['max_id'] === null) {
            return 0;
        }
        return (int)$row['max_id'];
    }

    public static function deleteProblemTailFrom($first_delete_id): array {
        $first_delete_id = self::normalizeUIntLike($first_delete_id);
        if ($first_delete_id === null || !validateUInt($first_delete_id) || (int)$first_delete_id < 1) {
            throw new Exception('起始题号必须是正整数');
        }

        $current_max_id = self::getCurrentMaxProblemId();
        $next_problem_id = (int)$first_delete_id;
        if ($current_max_id === 0) {
            DB::query("alter table problems auto_increment = {$next_problem_id}");
            return [
                'deleted_ids' => [],
                'deleted_count' => 0,
                'next_problem_id' => $next_problem_id,
                'old_max_problem_id' => 0
            ];
        }
        if ($next_problem_id > $current_max_id + 1) {
            throw new Exception("当前最大题号是 {$current_max_id}，不能回滚到 {$next_problem_id}");
        }

        $deleted_ids = [];
        for ($id = $next_problem_id; $id <= $current_max_id; $id++) {
            $deleted_ids[] = (string)$id;
        }

        if ($deleted_ids) {
            $contest_refs = DB::selectAll([
                "select contest_id, problem_id from contests_problems",
                "where", [
                    ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                ],
                "order by problem_id asc, contest_id asc"
            ]);
            if ($contest_refs) {
                $first_ref = $contest_refs[0];
                throw new Exception("题目 #{$first_ref['problem_id']} 仍在比赛 #{$first_ref['contest_id']} 中，不能删除");
            }
        }

        $submission_ids = [];
        $submission_files = [];
        if ($deleted_ids) {
            $submissions = DB::selectAll([
                "select id, content from submissions",
                "where", [
                    ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                ]
            ]);
            foreach ($submissions as $submission) {
                $submission_ids[] = (string)$submission['id'];
                $content = json_decode($submission['content'], true);
                if (isset($content['file_name']) && is_string($content['file_name'])) {
                    $submission_files[] = $content['file_name'];
                }
            }

            $custom_test_submissions = DB::selectAll([
                "select content from custom_test_submissions",
                "where", [
                    ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                ]
            ]);
            foreach ($custom_test_submissions as $submission) {
                $content = json_decode($submission['content'], true);
                if (isset($content['file_name']) && is_string($content['file_name'])) {
                    $submission_files[] = $content['file_name'];
                }
            }

            $hacks = DB::selectAll([
                "select input from hacks",
                "where", [
                    ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                ]
            ]);
            foreach ($hacks as $hack) {
                if (isset($hack['input']) && is_string($hack['input'])) {
                    $submission_files[] = $hack['input'];
                }
            }
        }

        DB::transaction(function() use ($deleted_ids, $submission_ids) {
            if ($submission_ids) {
                DB::delete([
                    "delete from submissions_history",
                    "where", [
                        ['submission_id', 'in', DB::rawtuple($submission_ids)]
                    ]
                ]);
            }
            if ($deleted_ids) {
                DB::delete([
                    "delete from click_zans",
                    "where", [
                        'type' => 'P',
                        ['target_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from system_updates",
                    "where", [
                        'type' => 'problem',
                        ['target_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from system_updates",
                    "where", [
                        'type' => 'problem_internally',
                        ['target_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from submissions",
                    "where", [
                        ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from custom_test_submissions",
                    "where", [
                        ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from hacks",
                    "where", [
                        ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from best_ac_submissions",
                    "where", [
                        ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from problems_permissions",
                    "where", [
                        ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from problems_tags",
                    "where", [
                        ['problem_id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from problems_contents",
                    "where", [
                        ['id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
                DB::delete([
                    "delete from problems",
                    "where", [
                        ['id', 'in', DB::rawtuple($deleted_ids)]
                    ]
                ]);
            }
        });

        $storage_path = UOJContext::storagePath();
        foreach (array_unique($submission_files) as $file_name) {
            if ($file_name !== '') {
                @unlink($storage_path . $file_name);
            }
        }

        foreach ($deleted_ids as $id) {
            UOJLocalRun::exec(['rm', '-rf', "/var/svn/problem/{$id}"]);
            UOJLocalRun::exec(['rm', '-rf', "/var/uoj_data/{$id}"]);
            UOJLocalRun::exec(['rm', '-f', "/var/uoj_data/{$id}.zip"]);
        }

        DB::query("alter table problems auto_increment = {$next_problem_id}");

        return [
            'deleted_ids' => $deleted_ids,
            'deleted_count' => count($deleted_ids),
            'next_problem_id' => $next_problem_id,
            'old_max_problem_id' => $current_max_id
        ];
    }

    public static function exportToArchive(array $ids): array {
        $zip_relative_path = FS::randomAvailableTmpFileName() . '.zip';
        $zip_full_path = UOJContext::storagePath() . $zip_relative_path;
        $inner_zip_paths = [];

        $zip = new ZipArchive();
        if ($zip->open($zip_full_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('创建导出压缩包失败');
        }

        try {
            $index = 1;
            foreach ($ids as $id) {
                $problem = UOJProblem::query($id);
                if (!$problem) {
                    throw new Exception("题目 #{$id} 不存在");
                }
                $inner_zip_path = self::createSingleProblemPackageArchive($problem);
                $entry_name = sprintf('problem_%03d.zip', $index);
                if (!$zip->addFile($inner_zip_path, $entry_name)) {
                    if (is_file($inner_zip_path)) {
                        unlink($inner_zip_path);
                    }
                    throw new Exception("写入题目 #{$id} 的导出包失败");
                }
                $inner_zip_paths[] = $inner_zip_path;
                $index++;
            }
        } catch (Exception $e) {
            $zip->close();
            foreach ($inner_zip_paths as $inner_zip_path) {
                if (is_file($inner_zip_path)) {
                    unlink($inner_zip_path);
                }
            }
            if (is_file($zip_full_path)) {
                unlink($zip_full_path);
            }
            throw $e;
        }

        if (!$zip->close()) {
            foreach ($inner_zip_paths as $inner_zip_path) {
                if (is_file($inner_zip_path)) {
                    unlink($inner_zip_path);
                }
            }
            if (is_file($zip_full_path)) {
                unlink($zip_full_path);
            }
            throw new Exception('关闭导出压缩包失败');
        }

        foreach ($inner_zip_paths as $inner_zip_path) {
            if (is_file($inner_zip_path)) {
                unlink($inner_zip_path);
            }
        }

        return [
            'zip_path' => $zip_full_path,
            'download_name' => 'uoj-problems-export.zip'
        ];
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
            $report = [
                'success' => [],
                'failed' => []
            ];

            $packages = self::collectPackages($root);
            foreach ($packages as $package) {
                try {
                    if ($package['type'] === 'dir') {
                        $problem = self::importSingleProblemFromDir($package['path'], $options);
                    } else {
                        $problem = self::importSingleProblemFromArchive($package['path'], $options);
                    }
                    $report['success'][] = [
                        'path' => $package['name'],
                        'id' => $problem->info['id'],
                        'title' => $problem->info['title']
                    ];
                } catch (Exception $e) {
                    $report['failed'][] = [
                        'path' => $package['name'],
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

    private static function importSingleProblemFromArchive(string $zip_file_name, array $options): UOJProblem {
        $tmp_root = '/tmp/uoj_problem_single_import_' . uojRandString(20);
        if (!mkdir($tmp_root, 0777, true) && !is_dir($tmp_root)) {
            throw new Exception('创建临时目录失败');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($zip_file_name) !== true) {
                throw new Exception('题目包打开失败');
            }
            if (!$zip->extractTo($tmp_root)) {
                $zip->close();
                throw new Exception('题目包解压失败');
            }
            $zip->close();

            $root = self::normalizeExtractRoot($tmp_root);
            if (!self::isProblemPackageDir($root)) {
                throw new Exception('题目包格式不合法');
            }
            return self::importSingleProblemFromDir($root, $options);
        } finally {
            self::removeDir($tmp_root);
        }
    }

    private static function importSingleProblemFromDir(string $problem_dir, array $options): UOJProblem {
        if (!is_dir($problem_dir)) {
            throw new Exception('题目包目录不存在');
        }

        $problem_cfg = [];
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

            return $problem;
        } finally {
            if ($data_zip !== null && strStartWith($data_zip, '/tmp/uoj_problem_import_data_') && is_file($data_zip)) {
                unlink($data_zip);
            }
        }
    }

    private static function collectPackages(string $root): array {
        if (self::isProblemPackageDir($root)) {
            return [[
                'type' => 'dir',
                'name' => '.',
                'path' => $root
            ]];
        }

        $packages = [];
        foreach (FS::scandir($root) as $name) {
            $full_path = "{$root}/{$name}";
            if (is_dir($full_path) && self::isProblemPackageDir($full_path)) {
                $packages[] = [
                    'type' => 'dir',
                    'name' => $name,
                    'path' => $full_path
                ];
            } elseif (is_file($full_path) && strtolower(substr($name, -4)) === '.zip') {
                $packages[] = [
                    'type' => 'zip',
                    'name' => $name,
                    'path' => $full_path
                ];
            }
        }
        if (!$packages) {
            throw new Exception('未找到可导入的单题包');
        }
        return $packages;
    }

    private static function isProblemPackageDir(string $dir): bool {
        return is_file("{$dir}/title.txt")
            && is_file("{$dir}/statement.md")
            && (is_file("{$dir}/data.zip") || is_dir("{$dir}/data"));
    }

    private static function createSingleProblemPackageArchive(UOJProblem $problem): string {
        $data_zip_path = $problem->getDataZipPath();
        if (!is_file($data_zip_path)) {
            throw new Exception("题目 #{$problem->info['id']} 缺少主数据包");
        }

        $content = $problem->queryContent();
        $zip_path = UOJContext::storagePath() . FS::randomAvailableTmpFileName() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("创建题目 #{$problem->info['id']} 的导出包失败");
        }

        try {
            if (!$zip->addFromString('title.txt', self::decodeProblemTitle($problem->info['title']))) {
                throw new Exception("写入题目 #{$problem->info['id']} 的 title.txt 失败");
            }
            if (!$zip->addFromString('statement.md', $content['statement_md'])) {
                throw new Exception("写入题目 #{$problem->info['id']} 的 statement.md 失败");
            }
            if (!$zip->addFile($data_zip_path, 'data.zip')) {
                throw new Exception("写入题目 #{$problem->info['id']} 的 data.zip 失败");
            }
        } catch (Exception $e) {
            $zip->close();
            if (is_file($zip_path)) {
                unlink($zip_path);
            }
            throw $e;
        }

        if (!$zip->close()) {
            if (is_file($zip_path)) {
                unlink($zip_path);
            }
            throw new Exception("关闭题目 #{$problem->info['id']} 的导出包失败");
        }
        return $zip_path;
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

    private static function decodeProblemTitle(string $title): string {
        return htmlspecialchars_decode($title, ENT_QUOTES);
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
