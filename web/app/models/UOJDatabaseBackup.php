<?php

class UOJDatabaseBackup {
        private const BACKUP_DIR_NAME = '/db-backup';
        private const LOCK_FILE_NAME = '/.db-backup.lock';
        public const TARGET_MAIN = 'main';
        public const TARGET_ACM_GAME = 'acm_game';

        public static function getTargets(): array {
                return [
                        self::TARGET_MAIN => [
                                'key' => self::TARGET_MAIN,
                                'label' => '主站数据库',
                                'dir_name' => 'main',
                                'file_tag' => 'main',
                                'database' => UOJConfig::$data['database']['database']
                        ],
                        self::TARGET_ACM_GAME => [
                                'key' => self::TARGET_ACM_GAME,
                                'label' => '卡牌数据库',
                                'dir_name' => 'acm_game',
                                'file_tag' => 'acm-game',
                                'database' => 'card_game_new'
                        ]
                ];
        }

        public static function getTarget(string $target): array {
                $targets = self::getTargets();
                if (!isset($targets[$target])) {
                        throw new Exception('无效的数据库目标');
                }
                return $targets[$target];
        }

        public static function backupRootDir(): string {
                return UOJContext::storagePath() . self::BACKUP_DIR_NAME;
        }

        public static function backupDir(string $target): string {
                $target_cfg = self::getTarget($target);
                return self::backupRootDir() . '/' . $target_cfg['dir_name'];
        }

        public static function ensureBackupDir(string $target): void {
                $dir = self::backupDir($target);
                if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                        throw new Exception('无法创建数据库备份目录');
                }
        }

        public static function listBackups(string $target): array {
                $target_cfg = self::getTarget($target);
                self::ensureBackupDir($target);
                $items = [];
                foreach (FS::scandir(self::backupDir($target)) as $name) {
                        $path = self::backupDir($target) . '/' . $name;
                        if (!is_file($path) || !preg_match('/\.sql$/', $name)) {
                                continue;
                        }
                        $items[] = [
                                'target' => $target_cfg['key'],
                                'target_label' => $target_cfg['label'],
                                'name' => $name,
                                'path' => $path,
                                'size' => filesize($path),
                                'mtime' => filemtime($path)
                        ];
                }
                usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
                return $items;
        }

        public static function createBackup(string $target = self::TARGET_MAIN, ?string $target_path = null): array {
                return self::withLock($target, function() use ($target, $target_path) {
                        return self::createBackupUnlocked($target, $target_path);
                });
        }

        public static function restoreFromBackupName(string $target, string $backup_name, bool $create_safety_backup = true): array {
                $path = self::resolveBackupPath($target, $backup_name);
                return self::restoreFromFile($target, $path, $create_safety_backup);
        }

        public static function restoreFromFile(string $target, string $sql_path, bool $create_safety_backup = true): array {
                return self::withLock($target, function() use ($target, $sql_path, $create_safety_backup) {
                        return self::restoreFromFileUnlocked($target, $sql_path, $create_safety_backup);
                });
        }

        public static function resolveBackupPath(string $target, string $backup_name): string {
                self::getTarget($target);
                if (!preg_match('/\A[a-zA-Z0-9._-]+\.sql\z/', $backup_name)) {
                        throw new Exception('无效的备份文件名');
                }
                $path = self::backupDir($target) . '/' . $backup_name;
                if (!is_file($path)) {
                        throw new Exception('备份文件不存在');
                }
                return $path;
        }

        public static function buildBackupFileName(string $target, string $tag = ''): string {
                $target_cfg = self::getTarget($target);
                $time = date('Ymd-His');
                $tag_suffix = $tag === '' ? '' : "-{$tag}";
                return "uoj-{$target_cfg['file_tag']}-db-backup-{$time}{$tag_suffix}.sql";
        }

        private static function withLock(string $target, callable $func) {
                self::ensureBackupDir($target);
                $ret = FS::lock_file(self::backupDir($target) . self::LOCK_FILE_NAME, LOCK_EX, $func);
                if ($ret === false) {
                        throw new Exception('当前已有数据库备份或恢复任务在执行，请稍后再试');
                }
                return $ret;
        }

        private static function createBackupUnlocked(string $target, ?string $target_path = null): array {
                $target_cfg = self::getTarget($target);
                self::ensureBackupDir($target);
                if ($target_path === null) {
                        $target_path = self::backupDir($target) . '/' . self::buildBackupFileName($target);
                } else {
                        $target_path = self::normalizePath($target_path);
                        $dir = dirname($target_path);
                        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                                throw new Exception('无法创建目标备份目录');
                        }
                }

                $stderr_path = "{$target_path}.stderr";
                @unlink($target_path);
                @unlink($stderr_path);

                exec(self::buildDumpCommand($target_cfg, $target_path, $stderr_path), $output, $ret);
                $stderr = self::consumeCommandError($stderr_path);
                if ($ret !== 0 || !is_file($target_path) || filesize($target_path) === 0) {
                        @unlink($target_path);
                        $msg = $stderr === '' ? 'mysqldump 执行失败' : "mysqldump 执行失败：\n{$stderr}";
                        throw new Exception($msg);
                }

                return [
                        'target' => $target_cfg['key'],
                        'target_label' => $target_cfg['label'],
                        'database' => $target_cfg['database'],
                        'path' => $target_path,
                        'download_name' => basename($target_path),
                        'size' => filesize($target_path)
                ];
        }

        private static function restoreFromFileUnlocked(string $target, string $sql_path, bool $create_safety_backup): array {
                $target_cfg = self::getTarget($target);
                $sql_path = self::normalizePath($sql_path);
                if (!is_file($sql_path)) {
                        throw new Exception('待恢复的 SQL 文件不存在');
                }

                $safety_backup = null;
                if ($create_safety_backup) {
                        $safety_backup = self::createBackupUnlocked($target, self::backupDir($target) . '/' . self::buildBackupFileName($target, 'pre-restore'));
                }

                $stderr_path = "{$sql_path}.restore.stderr";
                @unlink($stderr_path);
                exec(self::buildRestoreCommand($target_cfg, $sql_path, $stderr_path), $output, $ret);
                $stderr = self::consumeCommandError($stderr_path);
                if ($ret !== 0) {
                        $msg = $stderr === '' ? 'mysql 恢复执行失败' : "mysql 恢复执行失败：\n{$stderr}";
                        throw new Exception($msg);
                }

                return [
                        'target' => $target_cfg['key'],
                        'target_label' => $target_cfg['label'],
                        'database' => $target_cfg['database'],
                        'source_path' => $sql_path,
                        'source_name' => basename($sql_path),
                        'safety_backup' => $safety_backup
                ];
        }

        private static function buildDumpCommand(array $target_cfg, string $target_path, string $stderr_path): string {
                $cmd = self::buildMysqlCliPrefix('mysqldump');
                $cmd .= ' --single-transaction --quick --skip-lock-tables';
                $cmd .= ' --hex-blob --routines --events --triggers --add-drop-table';
                $cmd .= ' --default-character-set=utf8mb4';
                $cmd .= ' --databases ' . escapeshellarg($target_cfg['database']);
                $cmd .= ' > ' . escapeshellarg($target_path);
                $cmd .= ' 2> ' . escapeshellarg($stderr_path);
                return $cmd;
        }

        private static function buildRestoreCommand(array $target_cfg, string $sql_path, string $stderr_path): string {
                $cmd = self::buildMysqlCliPrefix('mysql');
                $cmd .= ' --default-character-set=utf8mb4';
                $cmd .= ' ' . escapeshellarg($target_cfg['database']);
                $cmd .= ' < ' . escapeshellarg($sql_path);
                $cmd .= ' 2> ' . escapeshellarg($stderr_path);
                return $cmd;
        }

        private static function buildMysqlCliPrefix(string $binary): string {
                $cfg = UOJConfig::$data['database'];
                $cmd = escapeshellcmd($binary);
                if (isset($cfg['host']) && $cfg['host'] !== '') {
                        $cmd .= ' -h ' . escapeshellarg($cfg['host']);
                }
                if (isset($cfg['port']) && $cfg['port'] !== '') {
                        $cmd .= ' -P ' . escapeshellarg((string)$cfg['port']);
                }
                $cmd .= ' -u ' . escapeshellarg($cfg['username']);
                $cmd .= ' --password=' . escapeshellarg($cfg['password']);
                return $cmd;
        }

        private static function consumeCommandError(string $stderr_path): string {
                if (!is_file($stderr_path)) {
                        return '';
                }
                $stderr = trim((string)file_get_contents($stderr_path));
                @unlink($stderr_path);
                return $stderr;
        }

        private static function normalizePath(string $path): string {
                if ($path === '') {
                        throw new Exception('路径不能为空');
                }
                if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
                        return $path;
                }
                return getcwd() . '/' . $path;
        }
}
