<?php

namespace TypechoPlugin\StellarPush;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * StellarPush —— 多引擎搜索引擎推送插件
 * 自动收集全站已发布内容 URL，主动推送给百度 / Bing(IndexNow) 等多引擎加速收录。
 * 支持：定时自动推送 / 手动全量 / 增量 / 失败重试 / 历史日志 / 干跑模式 / 多引擎可视化。
 *
 * @package StellarPush
 * @author 咔咔
 * @version 2.0.0
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        self::installTable();
        /* 首次激活自动生成内部触发密钥：bp_key 未配置时插件自触发（定时/发布推送）不被 403 拒绝 */
        if (self::state('internal_key', '') === '') {
            try {
                self::setState('internal_key', bin2hex(random_bytes(16)));
            } catch (\Throwable $e) {
                self::setState('internal_key', md5(uniqid('bp', true)));
            }
        }
        /* 首次激活自动生成 IndexNow 密钥（Bing/Yandex 等多引擎推送，零配置） */
        if (self::state('indexnow_key', '') === '') {
            try {
                self::setState('indexnow_key', bin2hex(random_bytes(16)));
            } catch (\Throwable $e) {
                self::setState('indexnow_key', md5(uniqid('idx', true)));
            }
        }
        /* 前台渲染钩子：触发每日定时自动推送 */
        \Typecho\Plugin::factory('Widget_Archive')->beforeRender = __CLASS__ . '::onRender';
        /* 发布/更新钩子：新文章发布即实时推送（fire-and-forget） */
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = __CLASS__ . '::onPublish';
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishSave = __CLASS__ . '::onUpdate';
        /* 后台顶栏入口按钮 */
        \Typecho\Plugin::factory('admin/header.php')->header = __CLASS__ . '::adminHeader';
        \Typecho\Plugin::factory('admin/footer.php')->end = __CLASS__ . '::adminFooter';
        return 'StellarPush 已启用：多引擎推送（百度/IndexNow），后台顶栏出现「推送」入口；新文章发布即推送';
    }

    public static function deactivate()
    {
    }

    /* 后台顶栏注入入口按钮（样式内联，无需额外 CSS 文件） */
    public static function adminHeader(string $header): string
    {
        return $header;
    }

    /* 后台顶栏注入入口按钮（JS 动态插入，兼容 StellarAdmin 改造后的顶栏；未登录不注入，避免登录页出现死按钮） */
    public static function adminFooter(): void
    {
        foreach ($_COOKIE as $k => $v) {
            if (substr($k, -13) === '__typecho_uid') {
                $siteUrl = rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/');
                $panelUrl = $siteUrl . '/usr/plugins/StellarPush/panel.php';
        $js = <<<JS
<script>
(function () {
    if (document.getElementById('bp-top-btn')) return;
    var btn = document.createElement('a');
    btn.id = 'bp-top-btn';
    btn.href = '{$panelUrl}';
    btn.title = '搜索引擎推送';
    btn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:linear-gradient(135deg,#5b5bd6,#8b5cf6);color:#fff;font-size:13px;font-weight:600;text-decoration:none;margin-right:8px;';
    btn.innerHTML = '🚀 推送';
    var bar = document.querySelector('.sa-topbar-right') || document.querySelector('.typecho-list-table') || document.body;
    bar.insertBefore(btn, bar.firstChild);
    /* 覆盖 Typecho common-js 的 target="_blank"，当前标签页打开（同 AI 大屏） */
    setTimeout(function () { var x = document.getElementById('bp-top-btn'); if (x) x.removeAttribute('target'); }, 100);
})();
</script>
JS;
        echo $js;
                return;
            }
        }
    }

    /* 建表（幂等）：推送日志表 + 推送状态表 */
    public static function installTable()
    {
        try {
            $db = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $adapter = $db->getAdapterName();

            $logTable = $prefix . 'baidu_push_log';
            if ($adapter === 'Pdo_SQLite') {
                $db->query("CREATE TABLE IF NOT EXISTS {$logTable} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    url TEXT, status TEXT DEFAULT 'success', message TEXT,
                    engine VARCHAR(20) DEFAULT 'baidu', created INTEGER)");
                $db->query("CREATE INDEX IF NOT EXISTS {$logTable}_created ON {$logTable} (created)");
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS {$logTable} (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    url TEXT, status VARCHAR(20) DEFAULT 'success', message TEXT,
                    engine VARCHAR(20) DEFAULT 'baidu', created INT)");
                try {
                    $db->query("CREATE INDEX {$logTable}_created ON {$logTable} (created)");
                } catch (\Throwable $e) {
                    /* 索引已存在 */
                }
            }
            /* 兼容旧表：补齐 engine 列（已存在则报错忽略） */
            try {
                $db->query("ALTER TABLE {$logTable} ADD COLUMN engine VARCHAR(20) DEFAULT 'baidu'");
            } catch (\Throwable $e) {
            }

            $stateTable = $prefix . 'baidu_push_state';
            if ($adapter === 'Pdo_SQLite') {
                $db->query("CREATE TABLE IF NOT EXISTS {$stateTable} (
                    k TEXT PRIMARY KEY, v TEXT)");
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS {$stateTable} (
                    k VARCHAR(191) PRIMARY KEY, v TEXT)");
            }
        } catch (\Throwable $e) {
            /* 建表失败不阻塞启用 */
        }
    }

    public static function config(Form $form)
    {
        $token = new \Typecho\Widget\Helper\Form\Element\Text(
            'bp_token', null, '',
            _t('百度推送 Token'),
            _t('百度站长平台 → 资源提交 → 普通收录 → 推送接口 里的 token。请务必在后台填写，切勿把真实 token 提交到公开仓库')
        );
        $form->addInput($token);

        $site = new \Typecho\Widget\Helper\Form\Element\Text(
            'bp_site', null, rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/'),
            _t('站点地址'),
            _t('含协议，末尾不带斜杠，如 https://lioip.cn')
        );
        $form->addInput($site);

        $mode = new \Typecho\Widget\Helper\Form\Element\Select(
            'bp_mode',
            ['incremental' => '增量（只推新增，推荐）', 'full' => '全量（每次推全部）'],
            'incremental',
            _t('推送模式'),
            _t('增量模式记录已推送 URL，避免重复推送浪费配额')
        );
        $form->addInput($mode);

        $auto = new \Typecho\Widget\Helper\Form\Element\Select(
            'bp_auto',
            ['1' => '开启（每天自动推送一次）', '0' => '关闭（仅手动推送）'],
            '1',
            _t('定时自动推送'),
            _t('开启后依赖站点访问触发，每天最多自动推送一次；服务器也可用计划任务 curl 触发 push.php')
        );
        $form->addInput($auto);

        $key = new \Typecho\Widget\Helper\Form\Element\Text(
            'bp_key', null, '',
            _t('触发密钥（可选）'),
            _t('用于外部计划任务 curl 触发 push.php 时的鉴权，留空则 push.php 拒绝外部访问')
        );
        $form->addInput($key);
    }

    public static function personalConfig(Form $form)
    {
    }

    /* 读取配置项 */
    public static function opt($key, $default = '')
    {
        try {
            $cfg = \Typecho\Widget::widget('Widget_Options')->plugin('StellarPush');
        } catch (\Throwable $e) {
            return $default;
        }
        $val = isset($cfg->{$key}) ? $cfg->{$key} : null;
        return ($val === null || $val === '') ? $default : $val;
    }

    /* 探测 Typecho Cookie 前缀（兼容 http/https 混合、siteUrl 与 rootUrl 不一致） */
    public static function detectCookiePrefix(): void
    {
        try {
            foreach ($_COOKIE as $k => $v) {
                if (substr($k, -13) === '__typecho_uid') {
                    $prefix = substr($k, 0, -13);
                    $ref = new \ReflectionProperty(\Typecho\Cookie::class, 'prefix');
                    $ref->setAccessible(true);
                    $ref->setValue(null, $prefix);
                    return;
                }
            }
        } catch (\Throwable $e) {
        }
        \Typecho\Cookie::setPrefix(rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/'));
    }

    /* 手动鉴权兜底：直接比对 cookie uid/authCode 与用户记录 */
    public static function manualLogin(): bool
    {
        $uid = null;
        $auth = null;
        foreach ($_COOKIE as $k => $v) {
            if (substr($k, -13) === '__typecho_uid') {
                $uid = $v;
            } elseif (substr($k, -19) === '__typecho_authCode') {
                $auth = $v;
            }
        }
        if ($uid === null || $auth === null) {
            return false;
        }
        try {
            $db = \Typecho\Db::get();
            $user = $db->fetchRow($db->select('uid, authCode, group')
                ->from('table.users')->where('uid = ?', (int) $uid));
            if ($user && hash_equals((string) $user['authCode'], (string) $auth)
                && $user['group'] === 'administrator') {
                return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    /* 统一管理员鉴权 */
    public static function requireAdmin(): bool
    {
        self::detectCookiePrefix();
        $user = \Typecho\Widget::widget('Widget_User');
        if ($user->hasLogin() && $user->pass('administrator', true)) {
            return true;
        }
        return self::manualLogin();
    }

    /* 前台渲染钩子：触发定时自动推送（每天最多一次，异步 fire-and-forget） */
    public static function onRender($archive)
    {
        if (self::opt('bp_auto', '1') !== '1') {
            return;
        }
        /* 未配置 token 不触发（避免无意义请求与错误状态标记） */
        if (trim(self::opt('bp_token', '')) === '') {
            return;
        }
        $last = (int) self::state('last_auto_push', '0');
        if (time() - $last < 86400) {
            return;
        }
        self::state('last_auto_push', (string) time());
        /* fire-and-forget：不阻塞页面，不等待结果 */
        self::firePush('incremental');
    }

    /* 发布钩子：新文章发布即实时推送该条 URL（不阻塞发布流程） */
    public static function onPublish($contents, $widget)
    {
        $cid = isset($contents['cid']) ? (int) $contents['cid'] : (int) ($widget->cid ?? 0);
        if ($cid > 0) {
            self::firePushSingle($cid);
        }
    }

    /* 更新钩子：文章更新后重推（让百度抓最新版） */
    public static function onUpdate($contents, $widget)
    {
        $cid = isset($contents['cid']) ? (int) $contents['cid'] : (int) ($widget->cid ?? 0);
        if ($cid > 0) {
            self::firePushSingle($cid);
        }
    }

    /* fire-and-forget 推送单篇文章 URL */
    public static function firePushSingle($cid)
    {
        if (trim(self::opt('bp_token', '')) === '') {
            return;
        }
        $siteUrl = rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/');
        $url = $siteUrl . '/usr/plugins/StellarPush/push.php?mode=single&cid=' . $cid . '&key=' . urlencode(self::internalKey());
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int) ($parts['port'] ?? 0);
        if ($port === 443 || $port === 0) {
            $port = 80;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
        if ($fp) {
            fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
            fclose($fp);
        }
    }

    /* ================= 核心推送逻辑 ================= */

    /* 收集全站 URL（按优先级分组：文章优先，固定页次之） */
    public static function collectUrls($site)
    {
        $db = \Typecho\Db::get();
        $site = rtrim($site, '/');
        $posts = [];
        $static = [];

        $rows = $db->fetchAll($db->select('cid')->from('table.contents')
            ->where('type = ? AND status = ?', 'post', 'publish')
            ->order('cid', \Typecho\Db::SORT_DESC));
        foreach ($rows as $p) {
            $posts[] = $site . '/archives/' . $p['cid'] . '/';
        }

        /* 固定页（推一次即可，不随配额反复推） */
        $static[] = $site . '/';
        $pages = $db->fetchAll($db->select('slug')->from('table.contents')
            ->where('type = ? AND status = ?', 'page', 'publish'));
        foreach ($pages as $pg) {
            $static[] = $site . '/' . $pg['slug'] . '.html';
        }
        $cats = $db->fetchAll($db->select('slug')->from('table.metas')->where('type = ?', 'category'));
        foreach ($cats as $c) {
            $static[] = $site . '/category/' . $c['slug'] . '/';
        }
        $tags = $db->fetchAll($db->select('slug')->from('table.metas')->where('type = ?', 'tag'));
        foreach ($tags as $t) {
            $static[] = $site . '/tag/' . $t['slug'] . '/';
        }

        return [
            'posts' => array_values(array_unique($posts)),
            'static' => array_values(array_unique($static)),
        ];
    }

    /* 内部触发密钥（插件自触发用；bp_key 未配置时 push.php 也接受它） */
    public static function internalKey(): string
    {
        $k = self::state('internal_key', '');
        if ($k === '') {
            $k = bin2hex(random_bytes(16));
            try {
                self::setState('internal_key', $k);
            } catch (\Throwable $e) {
            }
        }
        return $k;
    }

    /* 状态表读写（增量推送已推送 URL 集合、上次自动推送时间） */
    public static function state($key, $default = '')
    {
        $db = \Typecho\Db::get();
        $table = 'table.baidu_push_state';
        $row = $db->fetchRow($db->select('v')->from($table)->where('k = ?', $key));
        if ($row) {
            return (string) $row['v'];
        }
        return $default;
    }

    public static function setState($key, $value)
    {
        $db = \Typecho\Db::get();
        $table = 'table.baidu_push_state';
        $exists = $db->fetchRow($db->select('k')->from($table)->where('k = ?', $key));
        if ($exists) {
            $db->query($db->update($table)->rows(['v' => $value])->where('k = ?', $key));
        } else {
            $db->query($db->insert($table)->rows(['k' => $key, 'v' => $value]));
        }
    }

    /* 记录一条推送日志（engine: baidu / indexnow / 360 / toutiao） */
    public static function log($url, $status, $message = '', $engine = 'baidu')
    {
        try {
            $db = \Typecho\Db::get();
            $db->query($db->insert('table.baidu_push_log')->rows([
                'url' => $url, 'status' => $status, 'message' => $message, 'engine' => $engine, 'created' => time(),
            ]));
        } catch (\Throwable $e) {
        }
    }

    /* 执行推送：返回 ['ok'=>bool, 'pushed'=>int, 'failed'=>int, 'remain'=>int, 'detail'=>array] */
    public static function push($mode = 'incremental', $dryRun = false)
    {
        $token = trim(self::opt('bp_token', ''));
        $site = rtrim(trim(self::opt('bp_site', '')), '/');
        if ($token === '' || $site === '') {
            return ['ok' => false, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => [['url' => '', 'error' => '未配置 token 或站点地址']]];
        }

        $collected = self::collectUrls($site);
        $all = array_merge($collected['posts'], $collected['static']);

        if ($mode === 'incremental') {
            /* 增量：文章优先推送未推过的；固定页推一次即标记，之后永不重复推 */
            $pushedSet = json_decode(self::state('pushed_urls', '[]'), true);
            if (!is_array($pushedSet)) {
                $pushedSet = [];
            }
            /* 待推 = 全部未推过的 URL（文章 + 未推的固定页） */
            $urls = array_values(array_diff($all, $pushedSet));
            /* 文章优先级：新文章在前 */
            usort($urls, function ($a, $b) use ($collected) {
                $ai = array_search($a, $collected['posts'], true);
                $bi = array_search($b, $collected['posts'], true);
                if ($ai !== false && $bi !== false) return $ai - $bi;
                if ($ai !== false) return -1;
                if ($bi !== false) return 1;
                return 0;
            });
        } else {
            /* 全量：推全部 */
            $urls = $all;
        }

        if (empty($urls)) {
            return ['ok' => true, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => []];
        }

        if ($dryRun) {
            return ['ok' => true, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => array_map(function ($u) {
                return ['url' => $u, 'error' => ''];
            }, $urls)];
        }

        /* 运行锁：防止并发重复推送 */
        if (!self::acquireLock()) {
            return ['ok' => false, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => [['url' => '', 'error' => '上一次推送仍在进行中，请稍后再试']]];
        }

        try {
            $result = self::pushUrls($urls, $site, $token, $mode);
        } finally {
            self::releaseLock();
        }

        /* 推送后自动更新 sitemap.xml + 维护数据（有成功推送才做） */
        if ($result['pushed'] > 0) {
            self::generateSitemap();
            self::maintain($collected, $mode);
        }

        return $result;
    }

    /* 维护：清理已失效的已推 URL 记录、90 天前的日志 */
    public static function maintain($collected, $mode)
    {
        if ($mode !== 'full') {
            $pushedSet = json_decode(self::state('pushed_urls', '[]'), true);
            if (is_array($pushedSet) && !empty($pushedSet)) {
                $all = array_merge($collected['posts'], $collected['static']);
                $pushedSet = array_values(array_intersect($pushedSet, $all));
                self::setState('pushed_urls', json_encode($pushedSet));
            }
        }
        self::cleanLog();
    }

    /* 删除 90 天前的推送日志 */
    public static function cleanLog()
    {
        try {
            $db = \Typecho\Db::get();
            $db->query($db->delete('table.baidu_push_log')->where('created < ?', time() - 90 * 86400));
        } catch (\Throwable $e) {
        }
    }

    /* 推送单篇文章（发布/更新钩子用） */
    public static function pushSingle($cid)
    {
        $token = trim(self::opt('bp_token', ''));
        $site = rtrim(trim(self::opt('bp_site', '')), '/');
        if ($token === '' || $site === '') {
            return ['ok' => false, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => [['url' => '', 'error' => '未配置 token 或站点地址']]];
        }
        $url = $site . '/archives/' . (int) $cid . '/';
        /* 单篇推送也走锁，防止连发并发 */
        if (!self::acquireLock()) {
            return ['ok' => false, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => [['url' => $url, 'error' => '上一次推送仍在进行中']]];
        }
        try {
            return self::pushUrls([$url], $site, $token, 'single');
        } finally {
            self::releaseLock();
        }
    }

    /* 核心：推一组 URL 到百度 API（配额自适应：先单条探测剩余配额，再按配额截断批量，超额提交会被百度整批拒绝） */
    private static function pushUrls($urls, $site, $token, $mode)
    {
        /* 百度 API 的 site 参数要求「不带协议」的裸域名 */
        $apiSite = preg_replace('#^https?://#i', '', $site);
        $api = 'http://data.zz.baidu.com/urls?site=' . urlencode($apiSite) . '&token=' . urlencode($token);
        $detail = [];
        $successUrls = [];
        $totalPushed = 0;
        $remain = 0;
        $failed = 0;

        if (empty($urls)) {
            return ['ok' => true, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => []];
        }

        /* 单条探测：拿到真实剩余配额（不超额，也确认 token/网络可用） */
        $first = array_shift($urls);
        $probe = self::postBatch([$first], $api, $detail, $failed);
        if ($probe === null || isset($probe['quota'])) {
            if ($probe === null) {
                $detail[] = ['url' => $first, 'error' => '请求失败，明日自动重推'];
            } else {
                $detail[] = ['url' => $first, 'error' => '配额已用完（跳过，明日自动重推）'];
            }
            foreach ($urls as $u) {
                $detail[] = ['url' => $u, 'error' => '配额已用完（跳过，明日自动重推）'];
            }
            try {
                self::setState('last_remain', '0');
            } catch (\Throwable $e) {
            }
            return ['ok' => $failed === 0, 'pushed' => $totalPushed, 'failed' => $failed, 'remain' => 0, 'detail' => $detail];
        }

        /* 探测条成功/未确认 */
        $probeSuccess = (int) ($probe['success'] ?? 0);
        if ($probeSuccess > 0) {
            $successUrls[] = $first;
            self::log($first, 'success', '');
        } else {
            $detail[] = ['url' => $first, 'error' => '未确认成功（配额内剩余，明日重推）'];
        }
        $totalPushed += $probeSuccess;
        $remain = (int) ($probe['remain'] ?? 0);

        /* 剩余 URL 按配额截断提交，超出部分明日自动重推 */
        if ($remain > 0 && !empty($urls)) {
            $batch = array_slice($urls, 0, $remain);
            $left = array_slice($urls, $remain);
            $resp = self::postBatch($batch, $api, $detail, $failed);
            if ($resp !== null && !isset($resp['quota'])) {
                $successCount = (int) ($resp['success'] ?? 0);
                $totalPushed += $successCount;
                $remain = (int) ($resp['remain'] ?? $remain);
                $notSameSite = isset($resp['not_same_site']) && is_array($resp['not_same_site']) ? $resp['not_same_site'] : [];
                $logged = 0;
                foreach ($batch as $u) {
                    if (in_array($u, $notSameSite, true)) {
                        $detail[] = ['url' => $u, 'error' => '站点与 token 不匹配'];
                        continue;
                    }
                    if ($logged < $successCount) {
                        $successUrls[] = $u;
                        self::log($u, 'success', '');
                        $logged++;
                    } else {
                        $detail[] = ['url' => $u, 'error' => '未确认成功（配额内剩余，明日重推）'];
                    }
                }
            }
            foreach ($left as $u) {
                $detail[] = ['url' => $u, 'error' => '超出今日配额（明日自动重推）'];
            }
        } elseif (!empty($urls)) {
            foreach ($urls as $u) {
                $detail[] = ['url' => $u, 'error' => '超出今日配额（明日自动重推）'];
            }
        }

        /* 增量/单篇模式：记录成功推送的 URL */
        if ($mode !== 'full' && !empty($successUrls)) {
            $pushedSet = json_decode(self::state('pushed_urls', '[]'), true);
            if (!is_array($pushedSet)) {
                $pushedSet = [];
            }
            $pushedSet = array_values(array_unique(array_merge($pushedSet, $successUrls)));
            self::setState('pushed_urls', json_encode($pushedSet));
        }

        /* 同步推送给 IndexNow（Bing/Yandex 等多引擎） */
        if (!empty($successUrls)) {
            self::indexNow($successUrls);
        }

        /* 记录剩余配额（面板配额预警用） */
        try {
            self::setState('last_remain', (string) $remain);
        } catch (\Throwable $e) {
        }

        return [
            'ok' => $failed === 0,
            'pushed' => $totalPushed,
            'failed' => $failed,
            'remain' => $remain,
            'detail' => $detail,
        ];
    }

    /* 提交一批 URL（含 3 次重试）；返回响应数组 / null(网络失败) / ['quota'=>true](配额用尽) */
    private static function postBatch($batch, $api, &$detail, &$failed)
    {
        $body = implode("\n", $batch);
        $resp = false;
        $lastErr = '';
        for ($try = 1; $try <= 3; $try++) {
            $ch = curl_init($api);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $lastErr = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code == 200 && $resp !== false) {
                break;
            }
            if ($try < 3) {
                sleep(2 * $try);
            }
        }
        if ($resp === false) {
            foreach ($batch as $u) {
                $failed++;
                $detail[] = ['url' => $u, 'error' => '请求失败：' . $lastErr];
                self::log($u, 'failed', $lastErr);
            }
            return null;
        }
        $data = json_decode($resp, true);
        if (is_array($data) && isset($data['success'])) {
            return $data;
        }
        $msg = isset($data['message']) ? $data['message'] : substr($resp, 0, 200);
        if ((strpos($msg, 'over quota') !== false) || (strpos($msg, '超过') !== false)) {
            return ['quota' => true];
        }
        foreach ($batch as $u) {
            $failed++;
            $detail[] = ['url' => $u, 'error' => $msg];
            self::log($u, 'failed', $msg);
        }
        return null;
    }

    /* 判断 detail 数组里是否已有该 URL */
    private static function detailHas($detail, $url)
    {
        foreach ($detail as $d) {
            if (isset($d['url']) && $d['url'] === $url) {
                return true;
            }
        }
        return false;
    }

    /* IndexNow 多引擎推送（Bing/Yandex/Naver/Seznam 等，零配置自动启用） */
    public static function indexNow($urls)
    {
        $urls = array_values(array_unique((array) $urls));
        if (empty($urls)) {
            return 0;
        }
        $site = rtrim(trim(self::opt('bp_site', '')), '/');
        $host = parse_url($site, PHP_URL_HOST);
        if (!$host) {
            return 0;
        }
        $key = self::state('indexnow_key', '');
        if ($key === '') {
            /* 惰性生成密钥（首次推送时自动生成，无需重新激活插件） */
            try {
                $key = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                $key = md5(uniqid('idx', true));
            }
            self::setState('indexnow_key', $key);
        }
        /* 托管密钥验证文件：{key}.txt 放到站点根目录 */
        $keyFile = __TYPECHO_ROOT_DIR__ . '/' . $key . '.txt';
        if (!is_file($keyFile)) {
            @file_put_contents($keyFile, $key);
        }
        $body = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => $site . '/' . $key . '.txt',
            'urlList' => array_slice($urls, 0, 10000),
        ]);
        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            /* Windows PHP 未配 cacert.pem 会导致 https 证书验证失败，跳过验证 */
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        /* 200 / 202 都表示已接收；记录成功日志（engine=indexnow） */
        if ($code == 200 || $code == 202) {
            foreach ($urls as $u) {
                self::log($u, 'success', '', 'indexnow');
            }
            return count($urls);
        }
        return 0;
    }

    /* ================= 新增功能 ================= */

    /* 连通性自检：提交站点首页探测 token/网络/配额 */
    public static function checkConnection()
    {
        $token = trim(self::opt('bp_token', ''));
        $site = rtrim(trim(self::opt('bp_site', '')), '/');
        if ($token === '' || $site === '') {
            return ['ok' => false, 'error' => '未配置 token 或站点地址'];
        }
        $apiSite = preg_replace('#^https?://#i', '', $site);
        $api = 'http://data.zz.baidu.com/urls?site=' . urlencode($apiSite) . '&token=' . urlencode($token);

        /* 用站点首页做一次探测：若首页已推过则 success=0 不消耗配额，未推过会真实消耗 1 条 */
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $site . '/',
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'error' => '网络请求失败：' . $err];
        }
        $data = json_decode($resp, true);
        if ($code == 200 && is_array($data) && isset($data['success'])) {
            return [
                'ok' => true,
                'remain' => (int) ($data['remain'] ?? 0),
                'message' => '连接正常，今日剩余配额 ' . (int) ($data['remain'] ?? 0) . ' 条',
            ];
        }
        $msg = isset($data['message']) ? $data['message'] : ('HTTP ' . $code . ': ' . substr($resp, 0, 200));
        return ['ok' => false, 'error' => $msg];
    }

    /* 统计：今日/累计推送量、成功率 */
    public static function stats()
    {
        try {
            $db = \Typecho\Db::get();
            $table = 'table.baidu_push_log';
            $todayStart = strtotime('today');
            $engines = ['baidu', 'indexnow'];
            $byEngine = [];
            foreach ($engines as $e) {
                $t = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('engine = ? AND created >= ?', $e, $todayStart));
                $ts = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('engine = ? AND created >= ? AND status = ?', $e, $todayStart, 'success'));
                $tf = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('engine = ? AND created >= ? AND status = ?', $e, $todayStart, 'failed'));
                $al = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('engine = ?', $e));
                $as = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('engine = ? AND status = ?', $e, 'success'));
                $byEngine[$e] = [
                    'today' => (int) $t['c'], 'today_success' => (int) $ts['c'], 'today_failed' => (int) $tf['c'],
                    'total' => (int) $al['c'], 'total_success' => (int) $as['c'],
                ];
            }
            /* 总体（所有引擎） */
            $gToday = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('created >= ?', $todayStart));
            $gTodaySuccess = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('created >= ? AND status = ?', $todayStart, 'success'));
            $gTodayFailed = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('created >= ? AND status = ?', $todayStart, 'failed'));
            $gTotal = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table));
            $gTotalSuccess = $db->fetchRow($db->select(['COUNT(id)' => 'c'])->from($table)->where('status = ?', 'success'));
            /* 近 7 天趋势（按天+引擎，成功数） */
            $trend = [];
            $buckets = [];
            $trendRows = $db->fetchAll($db->select('engine', 'created')->from($table)->where('created >= ? AND status = ?', $todayStart - 6 * 86400, 'success'));
            foreach ($trendRows as $r) {
                $d = date('m-d', (int) $r['created']);
                $e = $r['engine'] ?: 'baidu';
                if (!isset($buckets[$d])) $buckets[$d] = ['baidu' => 0, 'indexnow' => 0, 'other' => 0];
                if (!isset($buckets[$d][$e])) $buckets[$d][$e] = 0;
                $buckets[$d][$e]++;
            }
            for ($i = 6; $i >= 0; $i--) {
                $d = date('m-d', $todayStart - $i * 86400);
                $trend[$d] = isset($buckets[$d]) ? $buckets[$d] : ['baidu' => 0, 'indexnow' => 0, 'other' => 0];
            }
            /* 失败明细（含引擎名） */
            $failedRows = $db->fetchAll($db->select('url', 'engine', 'message', 'created')->from($table)->where('status = ?', 'failed')->order('id', \Typecho\Db::SORT_DESC)->limit(50));

            return [
                'today' => (int) $gToday['c'],
                'today_success' => (int) $gTodaySuccess['c'],
                'today_failed' => (int) $gTodayFailed['c'],
                'total' => (int) $gTotal['c'],
                'total_success' => (int) $gTotalSuccess['c'],
                'by_engine' => $byEngine,
                'trend' => $trend,
                'failed_list' => $failedRows,
            ];
        } catch (\Throwable $e) {
            return ['today' => 0, 'today_success' => 0, 'today_failed' => 0, 'total' => 0, 'total_success' => 0, 'by_engine' => [], 'trend' => [], 'failed_list' => []];
        }
    }

    /* 重试失败的 URL */
    public static function retryFailed()
    {
        try {
            $db = \Typecho\Db::get();
            $rows = $db->fetchAll($db->select('url')->from('table.baidu_push_log')->where('status = ?', 'failed'));
            $urls = array_values(array_unique(array_column($rows, 'url')));
        } catch (\Throwable $e) {
            $urls = [];
        }
        if (empty($urls)) {
            return ['ok' => true, 'pushed' => 0, 'failed' => 0, 'remain' => 0, 'detail' => []];
        }
        $token = trim(self::opt('bp_token', ''));
        $site = rtrim(trim(self::opt('bp_site', '')), '/');
        $result = self::pushUrls($urls, $site, $token, 'single');
        if ($result['pushed'] > 0) {
            self::generateSitemap();
            self::cleanLog();
        }
        return $result;
    }

    /* 生成 sitemap.xml 到站点根目录 */
    public static function generateSitemap()
    {
        $site = rtrim(trim(self::opt('bp_site', '')), '/');
        if ($site === '') {
            return ['ok' => false, 'error' => '未配置站点地址'];
        }
        $collected = self::collectUrls($site);
        $all = array_merge($collected['posts'], $collected['static']);

        $db = \Typecho\Db::get();
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($all as $url) {
            $lastmod = date('Y-m-d');
            /* 文章取实际修改时间 */
            if (preg_match('#/archives/(\d+)/#', $url, $m)) {
                $row = $db->fetchRow($db->select('modified')->from('table.contents')->where('cid = ?', (int) $m[1]));
                if ($row && $row['modified']) {
                    $lastmod = date('Y-m-d', (int) $row['modified']);
                }
            }
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url) . "</loc>\n";
            $xml .= "    <lastmod>" . $lastmod . "</lastmod>\n";
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";

        $file = __TYPECHO_ROOT_DIR__ . '/sitemap.xml';
        $ok = @file_put_contents($file, $xml);
        return $ok === false
            ? ['ok' => false, 'error' => '写入 sitemap.xml 失败（目录无写权限）']
            : ['ok' => true, 'count' => count($all), 'file' => $file];
    }

    /* 读取/写入 robots.txt */
    public static function readRobots()
    {
        $file = __TYPECHO_ROOT_DIR__ . '/robots.txt';
        if (is_file($file)) {
            return file_get_contents($file);
        }
        return "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /action/\n";
    }

    public static function writeRobots($content)
    {
        $file = __TYPECHO_ROOT_DIR__ . '/robots.txt';
        $ok = @file_put_contents($file, $content);
        return $ok === false ? false : true;
    }

    /* 运行状态锁：防止并发重复推送 */
    private static function acquireLock()
    {
        $lock = (int) self::state('running_lock', '0');
        /* 锁超过 5 分钟视为过期 */
        if ($lock > 0 && (time() - $lock) < 300) {
            return false;
        }
        self::setState('running_lock', (string) time());
        return true;
    }

    private static function releaseLock()
    {
        self::setState('running_lock', '0');
    }


    /* fire-and-forget 触发推送（不阻塞页面） */
    public static function firePush($mode)
    {
        if (trim(self::opt('bp_token', '')) === '') {
            return;
        }
        $siteUrl = rtrim(\Typecho\Widget::widget('Widget_Options')->siteUrl, '/');
        $url = $siteUrl . '/usr/plugins/StellarPush/push.php?mode=' . $mode . '&key=' . urlencode(self::internalKey());
        $parts = parse_url($url);
        $host = $parts['host'] ?? '127.0.0.1';
        /* 回环直连一律走 HTTP：HTTPS 站 443 只监听 TLS，明文连不上；显式非 443 端口（本地测试）保留 */
        $port = (int) ($parts['port'] ?? 0);
        if ($port === 443 || $port === 0) {
            $port = 80;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
        if ($fp) {
            fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
            fclose($fp);
        }
    }
}
