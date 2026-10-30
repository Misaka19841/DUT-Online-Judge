<?php

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

require __DIR__ . '/autoload.php';
require __DIR__ . '/models/UOJConfig.php';

function dbCliHelp() {
        echo "DOJ Database CLI\n";
        echo "php db_cli.php backup [output.sql]\n";
        echo "php db_cli.php restore <input.sql>\n";
        echo "php db_cli.php list\n";
}

if ($argc <= 1) {
        dbCliHelp();
        exit(0);
}

$cmd = $argv[1];

try {
        if ($cmd === 'backup') {
                $target = $argc >= 3 ? $argv[2] : null;
                $res = UOJDatabaseBackup::createBackup($target);
                echo $res['path'] . "\n";
                exit(0);
        }
        if ($cmd === 'restore') {
                if ($argc < 3) {
                        dbCliHelp();
                        exit(1);
                }
                $res = UOJDatabaseBackup::restoreFromFile($argv[2], false);
                echo "restored from " . $res['source_path'] . "\n";
                exit(0);
        }
        if ($cmd === 'list') {
                foreach (UOJDatabaseBackup::listBackups() as $item) {
                        echo $item['name'] . "\t" . $item['size'] . "\t" . date('Y-m-d H:i:s', $item['mtime']) . "\n";
                }
                exit(0);
        }
        dbCliHelp();
        exit(1);
} catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
}
