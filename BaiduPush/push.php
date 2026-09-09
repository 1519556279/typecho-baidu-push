<?php
/**
 * BaiduPush 触发入口：供服务器计划任务 curl 调用，或插件内部 fire-and-forget 调用。
 *
 * 用法：
 *   curl "https://lioip.cn/usr/plugins/BaiduPush/push.php?key=你的密钥&mode=incremental"
 *
 * mode: incremental（增量，默认）| full（全量）| dry（干跑）| single（推单篇，需 cid 参数）
 * key:  插件配置里填的「触发密钥」；不填则此入口拒绝外部访问
 */
require_once dirname(__DIR__, 3) . '/config.inc.php';
require_once __DIR__ . '/Plugin.php';

header('Content-Type: application/json; charset=UTF-8');

$cfg = \Typecho\Widget::widget('Widget_Options')->plugin('BaiduPush');
$key = isset($cfg->bp_key) ? trim((string) $cfg->bp_key) : '';
$internal = \TypechoPlugin\BaiduPush\Plugin::state('internal_key', '');

$givenKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$ok = ($key !== '' && hash_equals($key, $givenKey))
    || ($internal !== '' && hash_equals($internal, $givenKey));
if (!$ok) {
    http_response_code(403);
    $msg = ($key === '' && $internal === '') ? '未配置触发密钥（后台插件设置填写，或重启用插件自动生成内部密钥）' : '密钥错误';
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'incremental';
if (!in_array($mode, ['incremental', 'full', 'dry', 'single'], true)) {
    $mode = 'incremental';
}

if ($mode === 'single') {
    $cid = isset($_GET['cid']) ? (int) $_GET['cid'] : 0;
    if ($cid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'single 模式需要 cid 参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = \TypechoPlugin\BaiduPush\Plugin::pushSingle($cid);
} elseif ($mode === 'dry') {
    $result = \TypechoPlugin\BaiduPush\Plugin::push('incremental', true);
} else {
    $result = \TypechoPlugin\BaiduPush\Plugin::push($mode, false);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
