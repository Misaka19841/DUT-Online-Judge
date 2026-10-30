<?php

class UOJDatabaseBackup {
        private const BACKUP_DIR_NAME = '/db-backup';
        private const LOCK_FILE_NAME = '/.db-backup.lock';

        public static function backupDir(): string {
                return UOJContext::storagePath() . self::BACKUP_DIR_NAME;
        }

        public static function ensureBackupDir(): void {
                $dir = self::backupDir();
                if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                        throw new Exception('无法创建数据库备份目录');
                }
        }

        public static function listBackups(): array {
                self::ensureBackupDir();
                $items = [];
                foreach (FS::scandir(self::backupDir()) as $name) {
                        $path = self::backupDir() . '/' . $name;
                        if (!is_file($path) || !preg_match('/\.sql$/', $name)) {
                                continue;
                        }
                        $items[] = [
                                'name' => $name,
                                'path' => $path,
                                'size' => filesize($path),
                                'mtime' => filemtime($path)
                        ];
                }
                usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
                return $items;
        }

        public static function createBackup(?string $target_path = null): array {
                return self::withLock(function() use ($target_path) {
                        return self::createBackupUnlocked($target_path);
                });
        }

        public static function restoreFromBackupName(string $backup_name, bool $create_safety_backup = true): array {
                $path = self::resolveBackupPath($backup_name);
                return self::restoreFromFile($path, $create_safety_backup);
        }

        public static function restoreFromFile(string $sql_path, bool $create_safety_backup = true): array {
                return self::withLock(function() use ($sql_path, $create_safety_backup) {
                        return self::restoreFromFileUnlocked($sql_path, $create_safety_backup);
                });
        }

        public static function resolveBackupPath(string $backup_name): string {
                if (!preg_match('/\A[a-zA-Z0-9._-]+\.sql\z/', $backup_name)) {
                        throw new Exception('无效的备份文件名');
                }
                $path = self::backupDir() . '/' . $backup_name;
                if (!is_file($path)) {
                        throw new Exception('备份文件不存在');
                }
                return $path;
        }

        public static function buildBackupFileName(string $tag = ''): string {
                $time = date('Ymd-His');
                $tag_suffix = $tag === '' ? '' : "-{$tag}";
                return "uoj-db-backup-{$time}{$tag_suffix}.sql";
        }

        private static function withLock(callable $func) {
                self::ensureBackupDir();
                $ret = FS::lock_file(self::backupDir() . self::LOCK_FILE_NAME, LOCK_EX, $func);
                if ($ret === false) {
                        throw new Exception('当前已有数据库备份或恢复任务在执行，请稍后再试');
                }
                return $ret;
        }

        private static function createBackupUnlocked(?string $target_path = null): array {
                self::ensureBackupDir();
                if ($target_path === null) {
                        $target_path = self::backupDir() . '/' . self::buildBackupFileName();
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

                exec(self::buildDumpCommand($target_path, $stderr_path), $output, $ret);
                $stderr = self::consumeCommandError($stderr_path);
                if ($ret !== 0 || !is_file($target_path) || filesize($target_path) === 0) {
                        @unlink($target_path);
                        $msg = $stderr === '' ? 'mysqldump 执行失败' : "mysqldump 执行失败：\n{$stderr}";
                        throw new Exception($msg);
                }

                return [
                        'path' => $target_path,
                        'download_name' => basename($target_path),
                        'size' => filesize($target_path)
                ];
        }

        private static function restoreFromFileUnlocked(string $sql_path, bool $create_safety_backup): array {
                $sql_path = self::normalizePath($sql_path);
                if (!is_file($sql_path)) {
                        throw new Exception('待恢复的 SQL 文件不存在');
                }

                $safety_backup = null;
                if ($create_safety_backup) {
                        $safety_backup = self::createBackupUnlocked(self::backupDir() . '/' . self::buildBackupFileName('pre-restore'));
                }

                $stderr_path = "{$sql_path}.restore.stderr";
                @unlink($stderr_path);
                exec(self::buildRestoreCommand($sql_path, $stderr_path), $output, $ret);
                $stderr = self::consumeCommandError($stderr_path);
                if ($ret !== 0) {
                        $msg = $stderr === '' ? 'mysql 恢复执行失败' : "mysql 恢复执行失败：\n{$stderr}";
                        throw new Exception($msg);
                }

                return [
                        'source_path' => $sql_path,
                        'source_name' => basename($sql_path),
                        'safety_backup' => $safety_backup
                ];
        }

        private static function buildDumpCommand(string $target_path, string $stderr_path): string {
                $cfg = UOJConfig::$data['database'];
                $cmd = self::buildMysqlCliPrefix('mysqldump');
                $cmd .= ' --single-transaction --quick --skip-lock-tables';
                $cmd .= ' --hex-blob --routines --events --triggers --add-drop-table';
                $cmd .= ' --default-character-set=utf8mb4';
                $cmd .= ' --databases ' . escapeshellarg($cfg['database']);
                $cmd .= ' > ' . escapeshellarg($target_path);
                $cmd .= ' 2> ' . escapeshellarg($stderr_path);
                return $cmd;
        }

        private static function buildRestoreCommand(string $sql_path, string $stderr_path): string {
                $cmd = self::buildMysqlCliPrefix('mysql');
                $cmd .= ' --default-character-set=utf8mb4';
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
