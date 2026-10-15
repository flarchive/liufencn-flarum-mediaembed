<?php

/*
 * This file is part of liufencn/flarum-mediaembed.
 *
 * 中文媒体站点嵌入
 * ---------------
 * 把国内常用的视频 / 音乐链接自动转成内嵌播放器。
 */

use Flarum\Extend;
use s9e\TextFormatter\Configurator;

return [
    (new Extend\Formatter())
        ->configure(function (Configurator $configurator) {
            /*
             * 背景
             * ----
             * Flarum 2.x 的富文本由 s9e/TextFormatter 渲染。「链接变播放器」靠它的
             * MediaEmbed 插件：解析时把裸 URL 包成 MEDIA 标签 → 按 host 查站点表 →
             * 命中就换成 <BILIBILI> / <YOUTUBE> 之类标签。
             *
             * s9e 内置站点表（CachedDefinitionCollection）目前有 136 个站点，
             * 已经覆盖 YouTube / Vimeo / Dailymotion / SoundCloud / Spotify /
             * TikTok / Twitch / Facebook / Twitter 等国外主流站，以及优酷。
             *
             * 但**国内站一个都没有**：bilibili / acfun / v.qq.com / music.163.com /
             * ixigua 全缺。所以国内视频、音乐链接只会退化成普通 <a> 超链接。
             *
             * fof/formatting 负责把 MediaEmbed 装上（settings 的
             * `fof-formatting.plugin.mediaembed`），但它只额外注册了 youtube 一个站点，
             * 不补国内站。本扩展就是补这一块。
             *
             * 依赖兜底
             * --------
             * 正常情况 MediaEmbed 已被 fof/formatting 装好，直接 add 即可。
             * 万一 fof/formatting 被停用/卸载，这里自己把插件 load 进来，
             * 保证本扩展独立可用。
             */
            if (!isset($configurator->MediaEmbed)) {
                $configurator->plugins->load('MediaEmbed', [
                    'createMediaBBCode' => isset($configurator->BBCodes),
                ]);
            }

            /*
             * ── 1. B 站视频 ────────────────────────────────────────────
             *
             * 支持：
             *   https://www.bilibili.com/video/BV139p16VEfn            新版 BV 号
             *   https://www.bilibili.com/video/av170001                旧版 av 号
             *   https://www.bilibili.com/video/BV139p16VEfn/?p=3       带分P
             *   带 share_source / vd_source / t= 等尾巴的分享链接（正则只取号）
             *   无 www / m. 子域（MediaEmbed 按 host 逐级剥子域回退匹配）
             *
             * ★ 属性名为什么叫 bvid 而不是 bv：
             *   站内历史数据里存着早年别的扩展写的 <BILIBILI bvid="1iM4y1y7oA">，
             *   属性名是 bvid 且值是「裸号」（没有 BV 前缀）。s9e 的标签属性由站点
             *   定义决定，一旦注册 BILIBILI 标签，这些历史帖就会套用这里的模板；
             *   若模板只认 bv，它们会渲染出空播放器，比不显示更糟。
             *   所以属性名与历史数据同名，并在模板里做前缀兼容。
             *
             * ★ 不用 s9e 原生 choose，而是手写 xsl:choose：
             *   choose 生成器（TemplateGenerators/Choose.php）的 needsWrapper()
             *   返回 false，产出的 iframe 拿不到响应式外层；这里手写 XSL 可以保留
             *   s9e 的响应式包裹（span + padding-bottom 比例），且能优雅处理
             *   「bvid 带/不带 BV 前缀」与「av 号」三种情况。
             *
             * 不做 b23.tv 短链：它是 302 跳转，MediaEmbed 要发 HTTP 请求才解析得出
             * 真实地址，会让发帖渲染依赖外网、变慢且不稳。
             */
            $qs   = 'autoplay=0&amp;danmaku=0&amp;high_quality=1';
            $page = '<xsl:if test="@page">&amp;p=<xsl:value-of select="@page"/></xsl:if>';
            $bvid = '<xsl:choose>'
                . '<xsl:when test="substring(@bvid,1,2)=\'BV\'"><xsl:value-of select="@bvid"/></xsl:when>'
                . '<xsl:otherwise>BV<xsl:value-of select="@bvid"/></xsl:otherwise>'
                . '</xsl:choose>';

            $configurator->MediaEmbed->add('bilibili', [
                'name'    => 'Bilibili',
                'host'    => ['bilibili.com'],
                'example' => 'https://www.bilibili.com/video/BV1xx411c7mD',
                'extract' => [
                    // 新版 BV 号（带前缀，与历史属性名同名）
                    '!bilibili\.com/video/(?\'bvid\'BV[0-9A-Za-z]+)!i',
                    // 旧版 av 号
                    '!bilibili\.com/video/av(?\'aid\'\d+)!i',
                    // 分P（可选；URL 里没有就不生成该属性）
                    '![?&]p=(?\'page\'\d+)!',
                ],
                'iframe'  => [
                    'src'    => '<xsl:choose>'
                        . '<xsl:when test="@bvid">//player.bilibili.com/player.html?bvid=' . $bvid . '&amp;' . $qs . $page . '</xsl:when>'
                        . '<xsl:otherwise>//player.bilibili.com/player.html?aid=<xsl:value-of select="@aid"/>&amp;' . $qs . $page . '</xsl:otherwise>'
                        . '</xsl:choose>',
                    'height' => 360,
                    'width'  => 640,
                ],
            ]);

            /*
             * ── 2. AcFun 视频 ─────────────────────────────────────────
             * https://www.acfun.cn/v/ac48913005
             * 播放器地址 2026-10-15 用真实视频 ac48913005 实测返回 200（65KB 播放器页）。
             */
            $configurator->MediaEmbed->add('acfun', [
                'name'    => 'AcFun',
                'host'    => ['acfun.cn'],
                'example' => 'https://www.acfun.cn/v/ac48913005',
                'extract' => [
                    '!acfun\.cn/v/ac(?\'aid\'\d+)!i',
                ],
                'iframe'  => [
                    'src'    => '//www.acfun.cn/player/ac{@aid}',
                    'height' => 360,
                    'width'  => 640,
                ],
            ]);

            /*
             * ── 3. 腾讯视频 ───────────────────────────────────────────
             * 常见形态：
             *   https://v.qq.com/x/cover/mzc00200xynff3g/d0026iv4j5c.html
             *   https://v.qq.com/x/page/p0970kx825l.html
             *   https://v.qq.com/x/cover/xxx.html?vid=yyy
             *
             * ★ host 只写 v.qq.com，不写 qq.com：
             *   qq.com 是腾讯全域（新闻/邮箱/官网都在其下），写宽了会把
             *   news.qq.com 之类非视频页也误判成播放器。MediaEmbed 的
             *   getSiteIdFromUrl() 是逐级剥子域回退，指定 v.qq.com 即可覆盖
             *   www.v.qq.com 等变体，又不会误伤别的子域。
             *
             * 播放器地址 2026-10-15 实测返回 200，且返回内容是真播放器页
             * （含 #video_container / .mod_player）。
             */
            $configurator->MediaEmbed->add('qqvideo', [
                'name'    => 'Tencent Video',
                'host'    => ['v.qq.com'],
                'example' => 'https://v.qq.com/x/cover/mzc00200xynff3g/d0026iv4j5c.html',
                'extract' => [
                    '!/x/cover/[^/]+/(?\'vid\'\w+)\.html!',
                    '!/x/cover/[^/]+\.html\?vid=(?\'vid\'\w+)!',
                    '!/cover/[^/]+/[^/]+/(?\'vid\'\w+)\.html!',
                    '!/cover/[^/]+/[^/]+\.html\?vid=(?\'vid\'\w+)!',
                    '!/x/page/(?\'vid\'\w+)\.html!',
                    '!/page/[^/]+/[^/]+/[^/]+/(?\'vid\'\w+)\.html!',
                ],
                'iframe'  => [
                    'src'    => '//v.qq.com/txp/iframe/player.html?vid={@vid}&tiny=0&auto=0',
                    'width'  => 760,
                    'height' => 450,
                ],
            ]);

            /*
             * ── 4. 网易云音乐 ─────────────────────────────────────────
             *   https://music.163.com/song?id=33894312          单曲
             *   https://music.163.com/#/song?id=33894312         带 hash 路由
             *   https://music.163.com/album?id=746325           专辑
             *   https://music.163.com/playlist?id=123456        歌单
             *
             * 用 s9e 原生 choose 按 mode 分流（type=2 单曲 / 1 专辑 / 0 歌单），
             * 高度也随类型不同（单曲矮、专辑歌单高）。
             * 播放器地址 2026-10-15 实测：type=2 与 type=1 均返回 200 真播放器页
             * （含 outchain-player.css）。
             */
            $configurator->MediaEmbed->add('music163', [
                'name'    => 'NetEase Cloud Music',
                'host'    => ['music.163.com'],
                'example' => 'https://music.163.com/song?id=33894312',
                'extract' => [
                    '!music\.163\.com/(?:#/)?(?\'mode\'song|album|playlist)(?:\?id=|/)(?\'id\'\d+)!i',
                ],
                'choose'  => [
                    'when' => [
                        [
                            'test'   => "@mode = 'album'",
                            'iframe' => [
                                'src'    => '//music.163.com/outchain/player?type=1&id={@id}&auto=0&height=450',
                                'width'  => 640,
                                'height' => 450,
                            ],
                        ],
                        [
                            'test'   => "@mode = 'song'",
                            'iframe' => [
                                'src'    => '//music.163.com/outchain/player?type=2&id={@id}&auto=0&height=66',
                                'width'  => 640,
                                'height' => 155,
                            ],
                        ],
                    ],
                    'otherwise' => [
                        'iframe' => [
                            'src'    => '//music.163.com/outchain/player?type=0&id={@id}&auto=0&height=450',
                            'width'  => 640,
                            'height' => 450,
                        ],
                    ],
                ],
            ]);

            /*
             * ── 5. 西瓜视频 ───────────────────────────────────────────
             * https://www.ixigua.com/7123456789012345678
             *
             * ⚠️ 未经端到端验证：ixigua 的 iframe 是纯 JS SPA，
             *    用 curl 只能拿到同一个应用外壳（无论 ID 真假都返回同样的 200 + 7.7KB），
             *    无法从服务端判断视频是否真的能播。播放器端点本身是通的。
             *    若实际发现播不了，把这一段删掉即可，不影响其他站点。
             */
            $configurator->MediaEmbed->add('ixigua', [
                'name'    => 'Ixigua',
                'host'    => ['ixigua.com'],
                'example' => 'https://www.ixigua.com/7123456789012345678',
                'extract' => [
                    '!ixigua\.com/(?\'id\'\d{6,})!',
                ],
                'iframe'  => [
                    'src'    => 'https://www.ixigua.com/iframe/{@id}?autoPlay=0',
                    'height' => 360,
                    'width'  => 640,
                ],
            ]);
        }),
];
