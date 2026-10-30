<?php

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

require __DIR__ . '/autoload.php';
require __DIR__ . '/models/UOJConfig.php';

function dbCliHelp() {
        echo "DOJ Database CLI\n";
        echo "php db_cli.php backup <main|acm_game> [output.sql]\n";
        echo "php db_cli.php restore <main|acm_game> <input.sql>\n";
        echo "php db_cli.php list [main|acm_game|all]\n";
}

if ($argc <= 1) {
        dbCliHelp();
        exit(0);
}

$cmd = $argv[1];

try {
        if ($cmd === 'backup') {
                if ($argc < 3) {
                        dbCliHelp();
                        exit(1);
                }
                $target = $argv[2];
                $output = $argc >= 4 ? $argv[3] : null;
                $res = UOJDatabaseBackup::createBackup($target, $output);
                echo $res['path'] . "\n";
                exit(0);
        }
        if ($cmd === 'restore') {
                if ($argc < 4) {
                        dbCliHelp();
                        exit(1);
                }
                $res = UOJDatabaseBackup::restoreFromFile($argv[2], $argv[3], false);
                echo "restored {$res['target']} from " . $res['source_path'] . "\n";
                exit(0);
        }
        if ($cmd === 'list') {
                $target = $argc >= 3 ? $argv[2] : 'all';
                $targets = $target === 'all'
                        ? array_keys(UOJDatabaseBackup::getTargets())
                        : [$target];
                foreach ($targets as $db_target) {
                        foreach (UOJDatabaseBackup::listBackups($db_target) as $item) {
                                echo $item['target'] . "\t" . $item['name'] . "\t" . $item['size'] . "\t" . date('Y-m-d H:i:s', $item['mtime']) . "\n";
                        }
                }
                exit(0);
        }
        dbCliHelp();
        exit(1);
} catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
}
