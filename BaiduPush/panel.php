<?php
/**
 * BaiduPush 管理面板（独立页面）
 * 功能：立即推送 / 全量推送 / 干跑预览 / 历史日志 / 状态与配额
 */
define('__TYPECHO_ADMIN__', true);
require dirname(__DIR__, 3) . '/config.inc.php';
require_once __TYPECHO_ROOT_DIR__ . '/usr/plugins/BaiduPush/Plugin.php';

$options = \Typecho\Widget::widget('Widget_Options');
if (!\TypechoPlugin\BaiduPush\Plugin::requireAdmin()) {
    header('Location: ' . rtrim($options->siteUrl, '/') . '/admin/login.php');
    exit;
}

$bp = '\TypechoPlugin\BaiduPush\Plugin';
$cfg = $options->plugin('BaiduPush');
$assets = rtrim($options->siteUrl, '/') . '/usr/plugins/BaiduPush/';

/* 处理动作 */
$action = isset($_GET['action']) ? $_GET['action'] : '';
$result = null;
$dryUrls = null;
$stats = null;
$failedList = null;

/* CSRF 防护：写操作校验 Referer 同源（防止恶意页面诱导管理员执行操作） */
if ($action !== '' && $action !== 'csv') {
    $siteHost = strtolower(parse_url($options->siteUrl, PHP_URL_HOST) ?: '');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $refHost = $referer !== '' ? strtolower(parse_url($referer, PHP_URL_HOST) ?: '') : '';
    if ($refHost !== '' && $refHost !== $siteHost) {
        exit('请求来源不合法');
    }
}

if ($action === 'push') {
    $result = $bp::push('incremental', false);
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
    /* 导出 CSV（防公式注入：=+-@ 前缀加单引号） */
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="baidu-push-log.csv"');
    echo "\xEF\xBB\xBF"; /* BOM，让 Excel 正确识别 UTF-8 */
    echo "时间,URL,状态,信息\n";
    $csvField = function ($v) {
        $v = str_replace('"', '""', (string) $v);
        if ($v !== '' && strspn($v, '=+-@') > 0) {
            $v = "'" . $v;
        }
        return '"' . $v . '"';
    };
    try {
        $rows = \Typecho\Db::get()->fetchAll(\Typecho\Db::get()->select('url', 'status', 'message', 'created')
            ->from('table.baidu_push_log')->order('id', \Typecho\Db::SORT_DESC)->limit(10000));
        foreach ($rows as $r) {
            echo date('Y-m-d H:i:s', (int) $r['created']) . ',' . $csvField($r['url']) . ',' . $csvField($r['status']) . ',' . $csvField($r['message']) . "\n";
        }
    } catch (\Throwable $e) {
    }
    exit;
}

/* 统计 */
$stats = $bp::stats();
$failedList = $stats['failed_list'] ?? [];

/* 最近日志 */
$logs = [];
try {
    $db = \Typecho\Db::get();
    $logs = $db->fetchAll($db->select('url', 'status', 'message', 'created')
        ->from('table.baidu_push_log')->order('id', \Typecho\Db::SORT_DESC)->limit(30));
} catch (\Throwable $e) {
}

/* 状态 */
$statePushed = $bp::state('pushed_urls', '[]');
$stateCount = count(json_decode($statePushed, true) ?: []);
$lastAuto = $bp::state('last_auto_push', '0');
$lastAutoStr = $lastAuto ? date('Y-m-d H:i:s', (int) $lastAuto) : '从未';

/* 待推送 URL 数量（增量视角） */
$allCollected = $bp::collectUrls($cfg->bp_site ?? 'https://lioip.cn');
$allUrls = array_merge($allCollected['posts'], $allCollected['static']);
$pushedSet = json_decode($statePushed, true) ?: [];
$pendingCount = count(array_diff($allUrls, $pushedSet));

/* 剩余配额（配额预警） */
$lastRemain = $bp::state('last_remain', '');

/* 近 7 天推送成功量（趋势图） */
$chart = [];
try {
    $db2 = \Typecho\Db::get();
    for ($i = 6; $i >= 0; $i--) {
        $dayStart = strtotime(date('Y-m-d')) - $i * 86400;
        $row = $db2->fetchRow($db2->select(['COUNT(id)' => 'c'])->from('table.baidu_push_log')
            ->where('created >= ? AND created < ? AND status = ?', $dayStart, $dayStart + 86400, 'success'));
        $chart[date('m-d', $dayStart)] = (int) $row['c'];
    }
} catch (\Throwable $e) {
    $chart = [];
}
$chartMax = max(1, ...array_values($chart));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>百度推送 - <?php $options->title(); ?></title>
<style>
:root { --ink:#1c2030; --ink2:#5b6272; --line:#e6e8f0; --accent:#5b5bd6; --bg:#f6f7fb; --card:#fff; --ok:#30a46c; --err:#e5484d; }
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; background:var(--bg); color:var(--ink); font-size:15px; line-height:1.6; }
.wrap { max-width:880px; margin:0 auto; padding:28px 20px 60px; }
h1 { font-size:22px; margin:0 0 4px; }
.sub { color:var(--ink2); font-size:13px; margin:0 0 20px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
.row { display:flex; flex-wrap:wrap; gap:12px; margin-top:14px; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border:none; border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; }
.btn-primary { background:var(--accent); color:#fff; }
.btn-primary:hover { opacity:.9; }
.btn-ghost { background:var(--bg); color:var(--ink2); border:1px solid var(--line); }
.btn-ghost:hover { border-color:var(--accent); color:var(--accent); }
.btn-warn { background:#fff; color:var(--err); border:1px solid var(--err); }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
.metric { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:14px; }
.metric b { display:block; font-size:20px; }
.metric span { color:var(--ink2); font-size:12px; }
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
.chart { display:flex; gap:10px; align-items:flex-end; height:110px; margin-top:12px; }
.chart .bar { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; height:100%; justify-content:flex-end; }
.chart .bar i { width:100%; background:linear-gradient(180deg,#5b5bd6,#8b5cf6); border-radius:5px 5px 0 0; display:block; }
.chart .bar span { font-size:11px; color:var(--ink2); }
.chart .bar b { font-size:12px; color:var(--ink); }
a.back { display:inline-block; margin-bottom:16px; color:var(--accent); font-size:14px; text-decoration:none; }
@media (max-width:640px){ .wrap{padding:20px 12px 50px;} .grid{grid-template-columns:1fr 1fr;} }
</style>
</head>
<body>
<div class="wrap">
<a class="back" href="<?php echo rtrim($options->siteUrl, '/') . '/admin/'; ?>">← 返回后台</a>
<h1>🔗 百度推送
  <button type="button" class="btn btn-ghost" id="bp-help-btn" style="font-size:13px;padding:6px 14px;vertical-align:middle;margin-left:8px;">❓ 使用说明</button>
</h1>
<p class="sub">自动收集全站文章/页面/分类/标签 URL，主动推送给百度加速收录。</p>

<?php if ($result): ?>
  <div class="msg msg-<?php echo $result['ok'] ? 'ok' : 'err'; ?>">
    <?php if (isset($result['msg'])): ?>
      <?php echo htmlspecialchars($result['msg']); ?>
    <?php else: ?>
      <?php if ($action === 'dry'): ?>
        干跑完成：共 <?php echo count($dryUrls); ?> 个 URL 待推送（未真正推送）
      <?php else: ?>
        推送完成：成功 <?php echo (int)$result['pushed']; ?> 条，失败 <?php echo (int)$result['failed']; ?> 条
        <?php if (isset($result['remain'])): ?>，剩余配额 <?php echo (int)$result['remain']; ?><?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <b>一键操作</b>
  <div class="row">
    <a class="btn btn-primary" href="panel.php?action=push">🚀 立即推送（增量）</a>
    <a class="btn btn-primary" href="panel.php?action=full">📦 全量推送</a>
    <a class="btn btn-ghost" href="panel.php?action=dry">👁 干跑预览</a>
    <a class="btn btn-ghost" href="panel.php?action=check">🔌 连通性自检</a>
    <a class="btn btn-ghost" href="panel.php?action=retry">🔁 重试失败</a>
    <a class="btn btn-ghost" href="panel.php?action=sitemap">🗺 生成 Sitemap</a>
    <a class="btn btn-ghost" href="panel.php?action=csv">📥 导出 CSV</a>
    <a class="btn btn-warn" href="panel.php?action=clearlog">🗑 清空日志</a>
  </div>
</div>

<div class="card">
  <b>当前状态</b>
  <div class="grid" style="margin-top:12px;">
    <div class="metric"><b><?php echo count($allUrls); ?></b><span>全站 URL 总数</span></div>
    <div class="metric"><b><?php echo $stateCount; ?></b><span>已推送（累计）</span></div>
    <div class="metric"><b><?php echo $pendingCount; ?></b><span>待推送（增量）</span></div>
    <div class="metric"><b><?php echo $lastAutoStr; ?></b><span>上次自动推送</span></div>
  </div>
  <p style="color:var(--ink2); font-size:13px; margin:12px 0 0;">
    站点：<code><?php echo htmlspecialchars($cfg->bp_site ?? ''); ?></code>
    · 模式：<code><?php echo htmlspecialchars($cfg->bp_mode ?? 'incremental'); ?></code>
    · 自动推送：<code><?php echo ($cfg->bp_auto ?? '1') === '1' ? '开启' : '关闭'; ?></code>
    <?php if ($lastRemain !== '' && (int) $lastRemain < 100): ?>
      · <b style="color:var(--err);">⚠ 剩余配额仅 <?php echo (int) $lastRemain; ?> 条</b>
    <?php elseif ($lastRemain !== ''): ?>
      · 剩余配额 <code><?php echo (int) $lastRemain; ?></code> 条
    <?php endif; ?>
  </p>
</div>

<div class="card">
  <b>近 7 天推送成功量</b>
  <div class="chart">
    <?php foreach ($chart as $day => $c): ?>
      <div class="bar" title="<?php echo $day; ?>：<?php echo $c; ?> 条">
        <i style="height:<?php echo max(4, round($c / $chartMax * 100)); ?>%;"></i>
        <span><?php echo $day; ?></span>
        <b><?php echo $c; ?></b>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <b>推送统计</b>
  <div class="grid" style="margin-top:12px;">
    <div class="metric"><b><?php echo (int)($stats['today'] ?? 0); ?></b><span>今日推送</span></div>
    <div class="metric"><b><?php echo (int)($stats['today_success'] ?? 0); ?></b><span>今日成功</span></div>
    <div class="metric"><b><?php echo (int)($stats['today_failed'] ?? 0); ?></b><span>今日失败</span></div>
    <div class="metric"><b><?php echo (int)($stats['total_success'] ?? 0); ?></b><span>累计成功</span></div>
  </div>
</div>

<?php if (!empty($failedList)): ?>
<div class="card">
  <b>失败 URL（可一键重试）</b>
  <div class="dry-list" style="margin-top:10px;">
    <table>
      <tr><th>URL</th><th>错误信息</th><th>时间</th></tr>
      <?php foreach ($failedList as $f): ?>
        <tr>
          <td class="url-cell"><?php echo htmlspecialchars($f['url']); ?></td>
          <td style="color:var(--err);"><?php echo htmlspecialchars($f['message']); ?></td>
          <td style="white-space:nowrap;"><?php echo date('m-d H:i', (int)$f['created']); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <b>robots.txt</b>
  <form method="post" action="panel.php?action=robots" style="margin-top:10px;">
    <textarea name="robots" rows="6" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font-family:monospace;font-size:13px;"><?php echo htmlspecialchars($bp::readRobots()); ?></textarea>
    <div class="row">
      <button type="submit" class="btn btn-primary">💾 保存 robots.txt</button>
    </div>
  </form>
</div>

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
  <b>最近推送日志</b>
  <div style="margin-top:10px; overflow-x:auto;">
    <table>
      <tr><th>时间</th><th>URL</th><th>状态</th><th>信息</th></tr>
      <?php if (empty($logs)): ?>
        <tr><td colspan="4" style="color:var(--ink2);">暂无日志</td></tr>
      <?php else: foreach ($logs as $l): ?>
        <tr>
          <td style="white-space:nowrap;"><?php echo date('m-d H:i', (int)$l['created']); ?></td>
          <td class="url-cell"><?php echo htmlspecialchars($l['url']); ?></td>
          <td class="status-<?php echo $l['status']; ?>"><?php echo $l['status']; ?></td>
          <td><?php echo htmlspecialchars($l['message']); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>
</div>

</div>

<!-- 使用说明弹窗 -->
<div id="bp-help-modal" style="display:none;position:fixed;inset:0;background:rgba(15,18,30,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;max-width:640px;width:92%;max-height:82vh;overflow-y:auto;padding:26px 28px;box-shadow:0 24px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <b style="font-size:17px;">📖 百度推送使用说明</b>
      <button type="button" id="bp-help-close" style="border:none;background:var(--bg);border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:15px;">✕</button>
    </div>
    <div style="font-size:14px;line-height:1.8;color:var(--ink);">
      <b>1️⃣ 获取推送 Token</b><br>
      登录 <a href="https://ziyuan.baidu.com" target="_blank">百度站长平台</a> → 添加并验证你的站点 → 左侧「资源提交 → 普通收录 → 推送接口」复制 <code>token</code>。<br><br>
      <b>2️⃣ 填写配置</b><br>
      后台「插件 → BaiduPush → 设置」填入 Token 与站点地址（如 <code>https://example.com</code>，不带末尾斜杠），保存。<br><br>
      <b>3️⃣ 推送方式</b><br>
      · 立即推送（增量）：只推没推过的 URL，推荐日常使用<br>
      · 全量推送：每次都推全站 URL（消耗配额，慎用）<br>
      · 发布新文章自动推该条；开启「定时自动推送」后每天自动推一次（依赖站点有访问）<br>
      · 服务器计划任务：<code>curl "https://你的站点/usr/plugins/BaiduPush/push.php?key=触发密钥&amp;mode=incremental"</code><br><br>
      <b>4️⃣ 配额说明</b><br>
      百度普通收录接口有每日配额（新站通常较少）。配额用完会跳过并<strong>次日自动重推</strong>，不用手动补。面板上方会显示剩余配额与近 7 天推送趋势。<br><br>
      <b>5️⃣ 站点验证</b><br>
      如果推送返回「站点与 token 不匹配」或持续失败，请确认站点已在站长平台完成验证、token 与站点对应。
    </div>
  </div>
</div>
<script>
(function () {
    var m = document.getElementById('bp-help-modal');
    function open() { m.style.display = 'flex'; }
    function close() { m.style.display = 'none'; }
    document.getElementById('bp-help-btn').addEventListener('click', open);
    document.getElementById('bp-help-close').addEventListener('click', close);
    m.addEventListener('click', function (e) { if (e.target === m) close(); });
})();
</script>
</body>
</html>
