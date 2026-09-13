<?php
/**
 * StellarPush 多引擎推送管理面板（独立页面）
 * 功能：引擎卡片 + 全部/单独推送 + 可视化统计（KPI/柱状图/折线图）+ 作者信息 + 使用说明
 */
define('__TYPECHO_ADMIN__', true);
require dirname(__DIR__, 3) . '/config.inc.php';
require_once __DIR__ . '/Plugin.php';

$options = \Typecho\Widget::widget('Widget_Options');
$bp = '\TypechoPlugin\StellarPush\Plugin';
if (!$bp::requireAdmin()) {
    header('Location: ' . rtrim($options->siteUrl, '/') . '/admin/login.php');
    exit;
}

try {
    $cfg = $options->plugin('StellarPush');
} catch (\Throwable $e) {
    $cfg = new \stdClass();
}
$bp_site = rtrim(trim($bp::opt('bp_site', $options->siteUrl)), '/');

/* 处理动作 */
$action = isset($_GET['action']) ? $_GET['action'] : '';
$engine = isset($_GET['engine']) ? $_GET['engine'] : '';
$result = null;
$dryUrls = null;

/* CSRF 防护：写操作校验 Referer 同源 */
if ($action !== '' && $action !== 'csv') {
    $siteHost = strtolower(parse_url($options->siteUrl, PHP_URL_HOST) ?: '');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $refHost = $referer !== '' ? strtolower(parse_url($referer, PHP_URL_HOST) ?: '') : '';
    if ($refHost !== '' && $refHost !== $siteHost) {
        exit('请求来源不合法');
    }
}

if ($action === 'push' && $engine === 'baidu') {
    $result = $bp::push('incremental', false);
} elseif ($action === 'push' && $engine === 'indexnow') {
    $collected = $bp::collectUrls($bp_site);
    $all = array_merge($collected['posts'], $collected['static']);
    $n = $bp::indexNow($all);
    $result = ['ok' => $n > 0, 'pushed' => $n, 'failed' => 0, 'remain' => 0, 'detail' => []];
} elseif ($action === 'all') {
    $r1 = $bp::push('incremental', false);
    $collected = $bp::collectUrls($bp_site);
    $all = array_merge($collected['posts'], $collected['static']);
    $n2 = $bp::indexNow($all);
    $result = ['ok' => $r1['ok'] || $n2 > 0, 'pushed' => $r1['pushed'] + $n2, 'failed' => $r1['failed'], 'remain' => $r1['remain'], 'detail' => []];
} elseif ($action === 'full') {
    $result = $bp::push('full', false);
} elseif ($action === 'dry') {
    $result = $bp::push('incremental', true);
    $dryUrls = $result['detail'] ?? [];
} elseif ($action === 'check') {
    $result = $bp::checkConnection();
} elseif ($action === 'retry') {
    $result = $bp::retryFailed();
} elseif ($action === 'sitemap') {
    $result = $bp::generateSitemap();
} elseif ($action === 'robots') {
    $content = isset($_POST['robots']) ? $_POST['robots'] : '';
    if ($content !== '') {
        $ok = $bp::writeRobots($content);
        $result = ['ok' => $ok, 'msg' => $ok ? 'robots.txt 已保存' : 'robots.txt 保存失败（无写权限）'];
    }
} elseif ($action === 'clearlog') {
    try {
        \Typecho\Db::get()->query("DELETE FROM " . \Typecho\Db::get()->getPrefix() . "baidu_push_log");
    } catch (\Throwable $e) {
    }
    $result = ['ok' => true, 'msg' => '日志已清空'];
} elseif ($action === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="push-log.csv"');
    echo "\xEF\xBB\xBF";
    echo "时间,引擎,URL,状态,信息\n";
    try {
        $rows = \Typecho\Db::get()->fetchAll(\Typecho\Db::get()->select('engine', 'url', 'status', 'message', 'created')
            ->from('table.baidu_push_log')->order('id', \Typecho\Db::SORT_DESC)->limit(10000));
        foreach ($rows as $r) {
            echo date('Y-m-d H:i:s', (int) $r['created']) . ',' . ($r['engine'] ?? 'baidu') . ',' . str_replace('"', '""', (string) $r['url']) . ',' . ($r['status'] ?? '') . ',' . str_replace('"', '""', (string) $r['message']) . "\n";
        }
    } catch (\Throwable $e) {
    }
    exit;
}

/* 统计 */
$stats = $bp::stats();
$byEngine = $stats['by_engine'] ?? [];
$trend = $stats['trend'] ?? [];
$failedList = $stats['failed_list'] ?? [];

/* 引擎状态定义 */
$engines = [
    'baidu' => ['name' => '百度', 'icon' => '🔍', 'color' => '#5b5bd6', 'desc' => '百度站长平台 API 主动推送', 'configured' => trim($bp::opt('bp_token', '')) !== ''],
    'indexnow' => ['name' => 'Bing / IndexNow', 'icon' => '🅱', 'color' => '#0ea5e9', 'desc' => 'Bing/Yandex 等一键推送（零配置）', 'configured' => true],
    '360' => ['name' => '360 搜索', 'icon' => '🛡', 'color' => '#f59e0b', 'desc' => '待接入（站长平台 token）', 'configured' => false],
    'toutiao' => ['name' => '头条搜索', 'icon' => '📰', 'color' => '#ef4444', 'desc' => '待接入（站长平台 token）', 'configured' => false],
];

/* 最近日志 */
$logs = [];
try {
    $db = \Typecho\Db::get();
    $logs = $db->fetchAll($db->select('engine', 'url', 'status', 'message', 'created')
        ->from('table.baidu_push_log')->order('id', \Typecho\Db::SORT_DESC)->limit(30));
} catch (\Throwable $e) {
}

/* 状态 */
$statePushed = $bp::state('pushed_urls', '[]');
$stateCount = count(json_decode($statePushed, true) ?: []);
$lastAuto = $bp::state('last_auto_push', '0');
$lastAutoStr = $lastAuto ? date('Y-m-d H:i:s', (int) $lastAuto) : '从未';
$lastRemain = $bp::state('last_remain', '');
$indexnowKey = $bp::state('indexnow_key', '');

/* 待推送 URL 数量 */
$allCollected = $bp::collectUrls($bp_site ?: 'https://lioip.cn');
$allUrls = array_merge($allCollected['posts'], $allCollected['static']);
$pushedSet = json_decode($statePushed, true) ?: [];
$pendingCount = count(array_diff($allUrls, $pushedSet));

/* 柱状图高度 */
$chartMax = 1;
foreach ($byEngine as $e) {
    $chartMax = max($chartMax, $e['today'] ?? 0);
}
/* 折线图数据 */
$trendMax = 1;
foreach ($trend as $t) {
    $trendMax = max($trendMax, ($t['baidu'] ?? 0) + ($t['indexnow'] ?? 0) + ($t['other'] ?? 0));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🚀 StellarPush 多引擎推送 - <?php $options->title(); ?></title>
<style>
:root { --ink:#1c2030; --ink2:#5b6272; --ink3:#8a92a8; --line:#e6e8f0; --accent:#5b5bd6; --bg:#f6f7fb; --card:#fff; --ok:#30a46c; --err:#e5484d; --warn:#f59e0b; }
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; background:var(--bg); color:var(--ink); font-size:15px; line-height:1.6; }
.wrap { max-width:960px; margin:0 auto; padding:28px 20px 60px; }
h1 { font-size:22px; margin:0 0 4px; }
.sub { color:var(--ink2); font-size:13px; margin:0 0 20px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
.row { display:flex; flex-wrap:wrap; gap:12px; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; }
.btn-primary { background:var(--accent); color:#fff; }
.btn-primary:hover { opacity:.9; }
.btn-big { padding:12px 26px; font-size:15px; }
.btn-ghost { background:var(--bg); color:var(--ink2); border:1px solid var(--line); }
.btn-ghost:hover { border-color:var(--accent); color:var(--accent); }
.btn-warn { background:#fff; color:var(--err); border:1px solid var(--err); }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
.metric { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:14px; }
.metric b { display:block; font-size:20px; }
.metric span { color:var(--ink2); font-size:12px; }
.engines { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
.engine { border:1px solid var(--line); border-radius:12px; padding:16px; background:var(--card); }
.engine-head { display:flex; align-items:center; gap:8px; margin-bottom:8px; flex-wrap:wrap; }
.engine-icon { width:34px; height:34px; border-radius:9px; display:grid; place-items:center; color:#fff; font-size:16px; }
.engine-name { font-weight:700; font-size:14px; }
.engine-desc { color:var(--ink2); font-size:12px; margin-bottom:8px; }
.engine-stats { display:flex; gap:14px; font-size:12px; color:var(--ink2); margin-bottom:10px; }
.engine-stats b { font-size:15px; }
.badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-ok { background:rgba(48,164,108,.12); color:var(--ok); }
.badge-wait { background:rgba(245,158,11,.12); color:var(--warn); }
.msg { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; }
.msg-ok { background:rgba(48,164,108,.12); color:var(--ok); border:1px solid rgba(48,164,108,.3); }
.msg-err { background:rgba(229,72,77,.1); color:var(--err); border:1px solid rgba(229,72,77,.3); }
table { width:100%; border-collapse:collapse; font-size:13px; }
th,td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); }
th { color:var(--ink2); font-weight:600; }
.status-success { color:var(--ok); }
.status-failed { color:var(--err); }
.url-cell { word-break:break-all; }
.dry-list { max-height:300px; overflow-y:auto; }
.chart { display:flex; gap:10px; align-items:flex-end; height:120px; margin-top:12px; }
.chart .col { flex:1; display:flex; align-items:flex-end; gap:4px; height:100%; }
.chart .col .mini { flex:1; border-radius:4px 4px 0 0; min-height:2px; }
.chart .col-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; height:100%; justify-content:flex-end; }
.chart .col-wrap .total { font-size:12px; color:var(--ink); font-weight:600; }
.chart .col-wrap span { font-size:10px; color:var(--ink3); }
.chart .col { width:100%; }
.line-chart { margin-top:12px; }
.line-chart svg { width:100%; height:120px; }
.legend { display:flex; gap:12px; font-size:11px; color:var(--ink3); margin-top:8px; flex-wrap:wrap; }
.legend i { display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:4px; vertical-align:-1px; }
a.back { display:inline-block; margin-bottom:16px; color:var(--accent); font-size:14px; text-decoration:none; }
details summary { cursor:pointer; font-weight:600; color:var(--ink2); }
details[open] summary { margin-bottom:10px; }
.topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.hint { color:var(--ink2); font-size:12px; margin-top:8px; }
code { background:var(--bg); padding:1px 6px; border-radius:4px; font-size:12px; }
.helper { position:absolute; right:0; top:100%; width:320px; background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; z-index:10; box-shadow:0 12px 32px rgba(0,0,0,.12); font-size:13px; color:var(--ink2); }
@media (max-width:640px){ .wrap{padding:20px 12px 50px;} .engines{grid-template-columns:1fr;} .helper{width:260px;} }
</style>
</head>
<body>
<div class="wrap">
<a class="back" href="<?php echo rtrim($options->siteUrl, '/') . '/admin/'; ?>">← 返回后台</a>
<div class="topbar">
  <div>
    <h1>🚀 StellarPush 多引擎推送</h1>
    <p class="sub">自动收集全站 URL，主动推送百度 / Bing(IndexNow) 等多引擎加速收录。</p>
  </div>
  <div class="row" style="gap:8px;">
    <a class="btn btn-ghost" href="https://github.com/1519556279" target="_blank" rel="noopener" title="作者咔咔的 GitHub">👤 作者 咔咔</a>
    <details style="position:relative;">
      <summary class="btn btn-ghost">📖 使用说明</summary>
      <div class="helper">
        <b style="color:var(--ink);">接入流程</b>
        <ul style="margin:8px 0;padding-left:18px;">
          <li><b>百度</b>：站长平台 ziyuan.baidu.com → 普通收录 → 推送接口，复制 token 填到插件设置。</li>
          <li><b>IndexNow</b>（Bing/Yandex 等）：<b>零配置自动启用</b>，插件自动生成密钥并托管 key.txt。</li>
          <li><b>360 / 头条</b>：暂未接入，后续版本开放。</li>
        </ul>
        <b style="color:var(--ink);">使用</b>
        <ul style="margin:8px 0;padding-left:18px;">
          <li>「🚀 全部推送」= 同时推百度 + IndexNow。</li>
          <li>每张引擎卡有「单独推送」按钮，可单独测某个引擎。</li>
          <li>增量模式只推未推过的 URL，避免浪费百度配额。</li>
        </ul>
      </div>
    </details>
  </div>
</div>

<?php if ($result): ?>
  <div class="msg msg-<?php echo $result['ok'] ? 'ok' : 'err'; ?>">
    <?php if (isset($result['msg'])): ?>
      <?php echo htmlspecialchars($result['msg']); ?>
    <?php elseif ($action === 'dry'): ?>
      干跑完成：共 <?php echo count($dryUrls ?: []); ?> 个 URL 待推送（未真正推送）
    <?php elseif ($action === 'all'): ?>
      全部推送完成：<b>成功 <?php echo (int)$result['pushed']; ?></b> 条，失败 <?php echo (int)$result['failed']; ?> 条（百度 + IndexNow）
    <?php else: ?>
      推送完成：成功 <?php echo (int)$result['pushed']; ?> 条，失败 <?php echo (int)$result['failed']; ?> 条
      <?php if (isset($result['remain'])): ?>，剩余配额 <?php echo (int)$result['remain']; ?><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="row" style="align-items:center;justify-content:space-between;">
    <b>一键操作</b>
    <span class="hint" style="margin:0;">IndexNow 密钥：<code><?php echo htmlspecialchars($indexnowKey ?: '未生成'); ?></code></span>
  </div>
  <div class="row" style="margin-top:14px;">
    <a class="btn btn-primary btn-big" href="panel.php?action=all">🚀 全部推送</a>
    <a class="btn btn-ghost" href="panel.php?action=push&engine=baidu">🔍 仅推百度</a>
    <a class="btn btn-ghost" href="panel.php?action=push&engine=indexnow">🅱 仅推 IndexNow</a>
    <a class="btn btn-ghost" href="panel.php?action=full">📦 全量</a>
    <a class="btn btn-ghost" href="panel.php?action=dry">👁 干跑</a>
    <a class="btn btn-ghost" href="panel.php?action=check">🔌 自检</a>
    <a class="btn btn-ghost" href="panel.php?action=retry">🔁 重试失败</a>
    <a class="btn btn-ghost" href="panel.php?action=sitemap">🗺 Sitemap</a>
    <a class="btn btn-ghost" href="panel.php?action=csv">📥 CSV</a>
    <a class="btn btn-warn" href="panel.php?action=clearlog">🗑 清日志</a>
  </div>
</div>

<div class="card">
  <b>今日概览</b>
  <div class="grid" style="margin-top:12px;">
    <div class="metric"><b style="color:var(--accent);"><?php echo (int)$stats['today']; ?></b><span>今日推送</span></div>
    <div class="metric"><b style="color:var(--ok);"><?php echo (int)$stats['today_success']; ?></b><span>今日成功</span></div>
    <div class="metric"><b style="color:var(--err);"><?php echo (int)$stats['today_failed']; ?></b><span>今日失败</span></div>
    <div class="metric"><b><?php echo $stats['today'] > 0 ? round($stats['today_success'] / $stats['today'] * 100) : 0; ?>%</b><span>成功率</span></div>
    <div class="metric"><b><?php echo $pendingCount; ?></b><span>待推送（百度增量）</span></div>
    <div class="metric"><b><?php echo $lastAutoStr; ?></b><span>上次自动推送</span></div>
  </div>
  <div class="hint">
    站点：<code><?php echo htmlspecialchars($bp_site); ?></code>
    · 模式：<code><?php echo htmlspecialchars($bp::opt('bp_mode', 'incremental')); ?></code>
    <?php if ($lastRemain !== '' && (int)$lastRemain < 100): ?>
      · <b style="color:var(--err);">⚠ 百度剩余配额仅 <?php echo (int)$lastRemain; ?> 条</b>
    <?php elseif ($lastRemain !== ''): ?>
      · 百度剩余配额 <code><?php echo (int)$lastRemain; ?></code> 条
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <b>引擎状态</b>
  <div class="engines" style="margin-top:12px;">
    <?php foreach ($engines as $key => $e): $st = $byEngine[$key] ?? ['today'=>0,'today_success'=>0,'today_failed'=>0,'total'=>0]; ?>
      <div class="engine">
        <div class="engine-head">
          <div class="engine-icon" style="background:<?php echo $e['color']; ?>;"><?php echo $e['icon']; ?></div>
          <div class="engine-name"><?php echo $e['name']; ?></div>
          <?php if ($e['configured']): ?><span class="badge badge-ok">已就绪</span><?php else: ?><span class="badge badge-wait">未配置</span><?php endif; ?>
        </div>
        <div class="engine-desc"><?php echo $e['desc']; ?></div>
        <div class="engine-stats">
          <span>今日 <b style="color:var(--ok);"><?php echo (int)$st['today_success']; ?></b></span>
          <span>失败 <b style="color:var(--err);"><?php echo (int)$st['today_failed']; ?></b></span>
          <span>累计 <b><?php echo (int)$st['total']; ?></b></span>
        </div>
        <?php if ($e['configured']): ?>
          <a class="btn btn-ghost" style="padding:5px 14px;font-size:13px;" href="panel.php?action=push&engine=<?php echo $key; ?>">▶ 单独推送</a>
        <?php else: ?>
          <span class="hint">待接入，无操作</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <b>今日各引擎推送量（成功/失败）</b>
  <div class="chart">
    <?php foreach ($engines as $key => $e): $st = $byEngine[$key] ?? ['today_success'=>0,'today_failed'=>0]; ?>
      <div class="col-wrap">
        <div class="total"><?php echo (int)$st['today_success'] + (int)$st['today_failed']; ?></div>
        <div class="col">
          <div class="mini" style="background:var(--err);height:<?php echo max(2, round(($st['today_failed'] ?? 0) / $chartMax * 100)); ?>%;" title="失败"></div>
          <div class="mini" style="background:var(--ok);height:<?php echo max(2, round(($st['today_success'] ?? 0) / $chartMax * 100)); ?>%;" title="成功"></div>
        </div>
        <span><?php echo $e['name']; ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="legend"><span><i style="background:var(--ok);"></i>成功</span><span><i style="background:var(--err);"></i>失败</span></div>
</div>

<div class="card">
  <b>近 7 天推送成功趋势</b>
  <div class="line-chart">
    <svg viewBox="0 0 340 110" preserveAspectRatio="none">
      <?php
      $days = array_keys($trend);
      $pts = [];
      foreach ($days as $i => $d) {
          $v = ($trend[$d]['baidu'] ?? 0) + ($trend[$d]['indexnow'] ?? 0) + ($trend[$d]['other'] ?? 0);
          $x = 14 + $i * (312 / max(1, count($days) - 1));
          $y = 103 - ($trendMax > 0 ? ($v / $trendMax * 88) : 0);
          $pts[] = round($x, 2) . ',' . round($y, 2);
      }
      $poly = implode(' ', $pts);
      ?>
      <line x1="10" y1="103" x2="330" y2="103" stroke="#e6e8f0" stroke-width="1"/>
      <?php if (count($pts) > 1): ?>
      <polyline points="<?php echo $poly; ?>" fill="none" stroke="#5b5bd6" stroke-width="2" stroke-linejoin="round"/>
      <?php foreach ($pts as $i => $p): ?>
        <?php $xy = explode(',', $p); ?>
        <circle cx="<?php echo $xy[0]; ?>" cy="<?php echo $xy[1]; ?>" r="3" fill="#5b5bd6"/>
      <?php endforeach; endif; ?>
    </svg>
  </div>
  <div class="legend"><?php foreach ($days as $d): ?><span><?php echo $d; ?></span><?php endforeach; ?></div>
</div>

<?php if (!empty($failedList)): ?>
<div class="card">
  <details>
    <summary>❌ 失败 URL（<?php echo count($failedList); ?> 条，可一键重试）</summary>
    <div class="dry-list">
      <table>
        <tr><th>引擎</th><th>URL</th><th>错误</th><th>时间</th></tr>
        <?php foreach ($failedList as $f): ?>
          <tr>
            <td><?php echo htmlspecialchars($f['engine'] ?? 'baidu'); ?></td>
            <td class="url-cell"><?php echo htmlspecialchars($f['url']); ?></td>
            <td style="color:var(--err);"><?php echo htmlspecialchars($f['message']); ?></td>
            <td style="white-space:nowrap;"><?php echo date('m-d H:i', (int)$f['created']); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </details>
</div>
<?php endif; ?>

<?php if ($dryUrls): ?>
<div class="card">
  <b>干跑预览：待推送 URL</b>
  <div class="dry-list" style="margin-top:10px;">
    <table>
      <?php foreach ($dryUrls as $d): ?>
        <tr><td class="url-cell"><?php echo htmlspecialchars($d['url']); ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <details>
    <summary>📄 最近推送日志</summary>
    <div style="margin-top:10px; overflow-x:auto;">
      <table>
        <tr><th>时间</th><th>引擎</th><th>URL</th><th>状态</th><th>信息</th></tr>
        <?php if (empty($logs)): ?>
          <tr><td colspan="5" style="color:var(--ink2);">暂无日志</td></tr>
        <?php else: foreach ($logs as $l): ?>
          <tr>
            <td style="white-space:nowrap;"><?php echo date('m-d H:i', (int)$l['created']); ?></td>
            <td><?php echo htmlspecialchars($l['engine'] ?? 'baidu'); ?></td>
            <td class="url-cell"><?php echo htmlspecialchars($l['url']); ?></td>
            <td class="status-<?php echo $l['status']; ?>"><?php echo $l['status']; ?></td>
            <td><?php echo htmlspecialchars($l['message']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </table>
    </div>
  </details>
</div>

<div class="card">
  <details>
    <summary>🤖 robots.txt</summary>
    <form method="post" action="panel.php?action=robots" style="margin-top:10px;">
      <textarea name="robots" rows="6" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font-family:monospace;font-size:13px;"><?php echo htmlspecialchars($bp::readRobots()); ?></textarea>
      <div class="row" style="margin-top:10px;"><button type="submit" class="btn btn-primary">💾 保存 robots.txt</button></div>
    </form>
  </details>
</div>

<p class="hint" style="text-align:center;">StellarPush v2.0 多引擎推送 · by 咔咔 · 数据保留 90 天</p>
</div>
</body>
</html>