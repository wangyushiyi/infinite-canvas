<?php
/**
 * 部署用：PHP 语法检查（由 deploy_comfy_relay.py 上传到服务器后执行）。
 * 用法: php lint.php <文件路径>
 */
declare(strict_types=1);

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "usage: php lint.php <file>\n");
    exit(2);
}
$out = [];
$code = 0;
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
echo implode("\n", $out), "\n";
exit($code === 0 ? 0 : 1);
