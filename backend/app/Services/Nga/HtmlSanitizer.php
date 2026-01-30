<?php

namespace App\Services\Nga;

use DOMDocument;
use DOMNode;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * HTML 清洗器：将来源内容过滤为安全子集，并执行链接/图片策略兜底。
 */
class HtmlSanitizer
{
    /**
     * @var HTMLPurifier|null 可选注入：用于测试或替换实现；为空时按需创建实例。
     */
    private ?HTMLPurifier $purifier;

    /**
     * 初始化清洗器依赖。
     *
     * @param SafeUrlPolicy $urlPolicy URL 安全策略
     * @param HTMLPurifier|null $purifier 可选注入的 purifier 实例
     */
    public function __construct(private readonly SafeUrlPolicy $urlPolicy, ?HTMLPurifier $purifier = null)
    {
        $this->purifier = $purifier;
    }

    /**
     * 清洗来源 HTML 并应用业务侧安全策略。
     *
     * @param string $html 来源 HTML
     * @return string 清洗后的 HTML
     */
    public function sanitize(string $html): string
    {
        // 风险点：HTMLPurifier 内部会缓存状态；为避免并发/复用带来的锁异常，优先按需创建新实例。
        $purifier = $this->purifier ?? $this->buildPurifier();
        $purified = $purifier->purify($html);

        return $this->applyPolicies($purified);
    }

    /**
     * 构建 HTMLPurifier 实例并完成配置。
     *
     * @return HTMLPurifier
     */
    private function buildPurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $this->configureDefinitionCache($config);
        // 业务规则：只允许基础排版、代码、引用、列表与图片标签
        $config->set(
            'HTML.Allowed',
            'a[href|rel|target],br,blockquote,pre,code,strong,em,u,s,del,ul,ol,li,img[src|alt|loading|referrerpolicy]'
        );
        // 业务规则：仅允许 http/https 协议
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        // 业务规则：不自动补段落，避免与来源格式冲突
        $config->set('AutoFormat.AutoParagraph', false);
        $this->configureCustomAttributes($config);

        return new HTMLPurifier($config);
    }

    /**
     * 配置 HTMLPurifier 定义缓存目录。
     *
     * 业务含义：默认缓存路径位于 vendor 目录，容器内通常只读，导致写入失败进而触发异常。
     * 副作用：可能在 storage 下创建缓存目录。
     *
     * @param HTMLPurifier_Config $config
     * @return void
     */
    private function configureDefinitionCache(HTMLPurifier_Config $config): void
    {
        $cachePath = $this->resolveDefinitionCachePath();
        if ($cachePath === null) {
            // 风险兜底：无法解析可写目录时禁用缓存，避免写入失败触发异常
            $config->set('Cache.DefinitionImpl', null);
            return;
        }

        $cacheReady = $this->ensureCacheDirectory($cachePath);
        if (!$cacheReady) {
            // 风险兜底：目录不可写时禁用缓存，避免影响抓取任务
            $config->set('Cache.DefinitionImpl', null);
            return;
        }

        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('Cache.SerializerPermissions', 0775);
    }

    /**
     * 解析定义缓存目录路径。
     *
     * @return string|null 绝对路径；无法解析时返回 null
     */
    private function resolveDefinitionCachePath(): ?string
    {
        // 风险点：在纯 PHPUnit 场景（未引导 Laravel Application）中，storage_path() 可能可用但 app() 不是 Application，
        // 调用会触发 “Container::storagePath() 不存在” 的错误；因此这里需要判断 Application 能力是否就绪。
        if (
            function_exists('storage_path')
            && function_exists('app')
            && is_object(app())
            && method_exists(app(), 'storagePath')
        ) {
            return storage_path('framework/cache/htmlpurifier');
        }

        $tempDir = sys_get_temp_dir();
        if (!is_string($tempDir) || $tempDir === '') {
            return null;
        }

        return rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'htmlpurifier';
    }

    /**
     * 确保缓存目录存在且可写。
     *
     * 副作用：可能创建目录。
     *
     * @param string $cachePath 缓存目录绝对路径
     * @return bool true 表示可用
     */
    private function ensureCacheDirectory(string $cachePath): bool
    {
        $isExistingDirectory = is_dir($cachePath);
        if ($isExistingDirectory) {
            return is_writable($cachePath);
        }

        $created = @mkdir($cachePath, 0775, true);
        if (!$created && !is_dir($cachePath)) {
            return false;
        }

        return is_writable($cachePath);
    }

    /**
     * 注册自定义 HTML 属性定义，避免 HTMLPurifier 对新属性报错。
     *
     * 业务含义：允许业务层主动补齐的图片属性（loading/referrerpolicy）通过校验。
     * 风险点：若 DefinitionID 变更需同步提升 DefinitionRev，否则可能沿用旧缓存。
     *
     * @param HTMLPurifier_Config $config
     * @return void
     */
    private function configureCustomAttributes(HTMLPurifier_Config $config): void
    {
        [$definitionId, $definitionRev] = $this->resolveDefinitionIdentity();
        if ($definitionId !== null) {
            // 业务规则：启用自定义定义缓存标识，确保 HTMLPurifier 缓存可复用
            $config->set('HTML.DefinitionID', $definitionId);
        }
        if ($definitionRev !== null) {
            // 业务规则：定义变更后需提升版本，触发缓存刷新
            $config->set('HTML.DefinitionRev', $definitionRev);
        }

        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition === null) {
            // 规则：缓存已命中时无需重复注册
            return;
        }

        // 业务规则：图片懒加载属性仅允许 lazy
        $definition->addAttribute('img', 'loading', 'Enum#lazy');
        // 业务规则：图片引用策略仅允许 no-referrer
        $definition->addAttribute('img', 'referrerpolicy', 'Enum#no-referrer');
    }

    /**
     * 解析 HTMLPurifier 自定义定义标识。
     *
     * @return array{0: string|null, 1: int|null} DefinitionID 与 DefinitionRev
     */
    private function resolveDefinitionIdentity(): array
    {
        // 风险点：在纯 PHPUnit 场景（未引导 Laravel Application）中，config() 可能可用但容器未绑定 config，
        // 调用会触发 “Target class [config] does not exist.”；因此这里必须判断绑定是否就绪。
        if (
            !function_exists('config')
            || !function_exists('app')
            || !is_object(app())
            || !method_exists(app(), 'bound')
            || !app()->bound('config')
        ) {
            // 规则：未引导 Laravel 时回退到默认标识，确保定义注册可用且行为稳定
            return ['nga_html', 1];
        }

        $definitionId = config('nga.html_purifier.definition_id');
        $definitionRev = config('nga.html_purifier.definition_rev');

        $definitionId = is_string($definitionId) && $definitionId !== '' ? $definitionId : null;
        if (!is_int($definitionRev)) {
            $definitionRev = is_numeric($definitionRev) ? (int) $definitionRev : null;
        }

        return [$definitionId, $definitionRev];
    }

    /**
     * 应用业务侧安全策略。
     *
     * @param string $html purifier 输出
     * @return string 处理后的 HTML
     */
    private function applyPolicies(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->enforceLinkPolicy($document);
        $this->enforceImagePolicy($document);

        $container = $document->getElementsByTagName('div')->item(0);
        if ($container === null) {
            return '';
        }

        return $this->innerHtmlFromNode($container);
    }

    /**
     * 链接策略：只保留安全链接，并统一补齐 rel/target。
     *
     * @param DOMDocument $document
     * @return void
     */
    private function enforceLinkPolicy(DOMDocument $document): void
    {
        $links = iterator_to_array($document->getElementsByTagName('a'));
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $safeHref = $this->urlPolicy->normalize($href);
            if ($safeHref === null) {
                $link->removeAttribute('href');
                $link->removeAttribute('rel');
                $link->removeAttribute('target');
                continue;
            }

            $link->setAttribute('href', $safeHref);
            $link->setAttribute('rel', 'nofollow noopener noreferrer');
            $link->setAttribute('target', '_blank');
        }
    }

    /**
     * 图片策略：仅允许安全图片来源，并补齐必要属性。
     *
     * @param DOMDocument $document
     * @return void
     */
    private function enforceImagePolicy(DOMDocument $document): void
    {
        $images = iterator_to_array($document->getElementsByTagName('img'));
        foreach ($images as $image) {
            $src = $image->getAttribute('src');
            $safeSrc = $this->urlPolicy->normalize($src);
            if ($safeSrc === null) {
                $image->parentNode?->removeChild($image);
                continue;
            }

            $image->setAttribute('src', $safeSrc);
            if (!$image->hasAttribute('alt')) {
                $image->setAttribute('alt', '');
            }
            $image->setAttribute('loading', 'lazy');
            $image->setAttribute('referrerpolicy', 'no-referrer');
        }
    }

    /**
     * 获取节点的 innerHTML。
     *
     * @param DOMNode $node 目标节点
     * @return string innerHTML
     */
    private function innerHtmlFromNode(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                continue;
            }
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
