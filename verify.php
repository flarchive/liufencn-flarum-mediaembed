<?php
/*
 * liufencn/flarum-mediaembed 全站点验证台（内存缓存，不碰线上）
 * 用法: docker exec -w <flarum根目录> php php <本文件>
 * 可通过环境变量 FLARUM_ROOT 指定 Flarum 安装根目录，默认为常见容器路径
 */

$root = getenv('FLARUM_ROOT') ?: '/var/www/flarum';
$site = require $root . '/site.php';
$app  = $site->bootApp();
$container = $app->getContainer();

// 重绑到内存缓存，避开线上已编译的 Renderer
$container->singleton('flarum.formatter', function ($container) {
    return new Flarum\Formatter\Formatter(
        new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore),
        $container->make(Flarum\Foundation\Paths::class)->storage . '/formatter',
        $container->make(Flarum\Foundation\Config::class)
    );
});
$formatter = $container->make('flarum.formatter');

// 把待测扩展的 extenders 应用上来
foreach (require $root . '/backups/_debugscripts/extend_under_test.php' as $extender) {
    $extender->extend($container);
}

$cases = [
    // ── B站 ──
    'BV+分享尾巴'   => 'https://www.bilibili.com/video/BV139p16VEfn/?share_source=copy_web&vd_source=ecf218fa25b04e59a31b7c34ed0dbcaa',
    'BV裸链'       => 'https://www.bilibili.com/video/BV1xx411c7mD',
    'BV带分P'      => 'https://www.bilibili.com/video/BV139p16VEfn/?p=3',
    'BV无www'      => 'https://bilibili.com/video/BV1xx411c7mD',
    'BV移动端m.'   => 'https://m.bilibili.com/video/BV1xx411c7mD',
    'av旧号'       => 'https://www.bilibili.com/video/av170001',
    'av带分P'      => 'https://www.bilibili.com/video/av170001?p=2',
    // ── AcFun ──
    'AcFun'        => 'https://www.acfun.cn/v/ac48913005',
    'AcFun无www'   => 'https://acfun.cn/v/ac12345678',
    // ── 腾讯视频 ──
    '腾讯cover'    => 'https://v.qq.com/x/cover/mzc00200xynff3g/d0026iv4j5c.html',
    '腾讯page'     => 'https://v.qq.com/x/page/p0970kx825l.html',
    '腾讯cover?vid' => 'https://v.qq.com/x/cover/abc.html?vid=xyz123',
    // ── 网易云音乐 ──
    '网易云单曲'    => 'https://music.163.com/song?id=33894312',
    '网易云单曲#'   => 'https://music.163.com/#/song?id=33894312',
    '网易云专辑'    => 'https://music.163.com/album?id=746325',
    '网易云歌单'    => 'https://music.163.com/playlist?id=1234567890',
    '网易云斜杠式'  => 'https://music.163.com/song/33894312',
    // ── 西瓜视频 ──
    '西瓜视频'      => 'https://www.ixigua.com/7123456789012345678',
    // ── 回归：s9e 内置站不应被破坏 ──
    'YouTube(内置)' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'Vimeo(内置)'  => 'https://vimeo.com/76979871',
    'Spotify(内置)' => 'https://open.spotify.com/track/0VjIjW4GlUZAMYd2vXMi3b',
    // ── 非媒体，不应误判 ──
    '腾讯新闻(不嵌)' => 'https://news.qq.com/rain/a/20261015A00ABC00.html',
    '百度网盘(不嵌)' => 'https://pan.baidu.com/s/1AwzSkRsjQXsowfmThcrcmA?pwd=rx6q',
    'GitHub(不嵌)'  => 'https://github.com/AniBullet/BsKeyTools',
    '伪造域名(不嵌)' => 'https://evil.com/bilibili.com/video/BV1xx411c7mD',
];

$expect = [
    'BV+分享尾巴' => 'bvid=BV139p16VEfn', 'BV裸链' => 'bvid=BV1xx411c7mD',
    'BV带分P' => 'p=3', 'BV无www' => 'bvid=BV1xx411c7mD', 'BV移动端m.' => 'bvid=BV1xx411c7mD',
    'av旧号' => 'aid=170001', 'av带分P' => 'p=2',
    'AcFun' => 'ac48913005', 'AcFun无www' => 'ac12345678',
    // 注意: x/cover/{cid}/{vid}.html 第二段才是视频 vid, 播放器要 vid
    '腾讯cover' => 'vid=d0026iv4j5c', '腾讯page' => 'vid=p0970kx825l',
    '腾讯cover?vid' => 'vid=xyz123',
    '网易云单曲' => 'type=2', '网易云单曲#' => 'type=2', '网易云专辑' => 'type=1',
    '网易云歌单' => 'type=0', '网易云斜杠式' => 'type=2',
    '西瓜视频' => 'ixigua.com/iframe/7123456789012345678',
    'YouTube(内置)' => 'youtube-nocookie.com', 'Vimeo(内置)' => 'player.vimeo.com',
    'Spotify(内置)' => 'spotify',
    '腾讯新闻(不嵌)' => null, '百度网盘(不嵌)' => null, 'GitHub(不嵌)' => null,
    '伪造域名(不嵌)' => null,
];

$pass = $fail = 0;
printf("%-16s %-8s %-8s %s\n", '用例', '期望', '实际', '结果');
echo str_repeat('-', 96), "\n";
foreach ($cases as $label => $url) {
    try {
        $xml = $formatter->parse($url);
        $html = trim($formatter->render($xml));
    } catch (Throwable $e) {
        printf("%-16s %-8s %-8s FAIL(%s)\n", $label, '异常', '异常', $e->getMessage());
        $fail++;
        continue;
    }
    $want = $expect[$label];
    $got = null;
    if (preg_match('/(?:src="([^"]+)"|<BILIBILI[^>]*>)/', $html, $m)) {
        $got = html_entity_decode($m[1] ?? $m[0]);
    }
    if ($want === null) {
        $ok = !str_contains($html, '<iframe');
    } else {
        $ok = $got !== null && str_contains($got, $want);
    }
    $ok ? $pass++ : $fail++;
    printf("%-16s %-8s %-8s %s\n", $label, $want === null ? '不嵌' : substr($want, 0, 8), $ok ? 'OK' : 'BAD', $ok ? '✓' : ('✗ got=' . substr((string) $got, 0, 70)));
}
echo str_repeat('-', 96), "\n";
echo "PASS $pass / FAIL $fail\n";
exit($fail ? 1 : 0);
