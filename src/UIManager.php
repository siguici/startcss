<?php

namespace Sikessem\UI;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentAttributeBag as ComponentAttributes;
use Illuminate\View\ComponentSlot;
use Livewire\Livewire;
use RuntimeException;
use Sikessem\UI\Base\BladeComponent as BladeBaseComponent;
use Sikessem\UI\Base\LivewireComponent as LivewireBaseComponent;
use Sikessem\UI\Contracts\IsBladeComponent;
use Sikessem\UI\Contracts\IsLivewireComponent;

/**
 * @template TComponent of array{tag:string,attributes:array<string,string>,variants:array<string,TComponent>}
 */
class UIManager
{
    public const COMPONENT_NAMESPACE = 'Sikessem\\UI\\Components';

    public const ANONYMOUS_COMPONENT_NAMESPACE = 'ui::components';

    /**
     * @var array<string,array<string,array<TComponent>>>;
     */
    protected array $components = [];

    /**
     * @var array<ComponentTag>
     */
    protected array $tags = [];

    /**
     * @template TValue of mixed
     *
     * @param  TValue  $default
     * @return TValue
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return config("sikessem.ui.$key", $default);
    }

    /**
     * @template TValue of TComponent
     *
     * @param  TValue  $default
     * @return TValue
     */
    public function componentConfig(string $component, mixed $default = []): array
    {
        return $this->config("components.$component", $default);
    }

    /**
     * @template TValue of string
     *
     * @param  TValue  $default
     * @return TValue
     */
    public function getComponentTag(string $component, string $default = ''): string
    {
        if ($component = $this->find($component)) {
            return $component['tag'] ?? $default;
        }

        return $default;
    }

    /**
     * @template TValue of array<string,string>
     *
     * @param  TValue  $default
     * @return TValue
     */
    public function getComponentAttributes(string $component, array $default = []): array
    {
        if ($component = $this->find($component)) {
            ['options' => $options] = $component;

            return $options['attributes'] ?? $default;
        }

        return $default;
    }

    /**
     * @template TValue of string
     *
     * @param  TValue  $default
     * @return TValue
     */
    public function getComponentContents(string $component, string $default = ''): string
    {
        if ($component = $this->find($component)) {
            ['options' => $options] = $component;

            return $options['contents'] ?? $default;
        }

        return $default;
    }

    /**
     * @template TValue of TComponent
     *
     * @param  TValue  $default
     * @return TValue
     */
    public function getComponentVariants(string $component, array $default = []): array
    {
        if ($component = $this->find($component)) {
            ['options' => $options] = $component;

            return $options['variants'] ?? $default;
        }

        return $default;
    }

    public function prefix(): string
    {
        return $this->config('prefix', 'ui');
    }

    public function component(string $class, string $alias = null, bool $anonymous = false): void
    {
        $alias ??= $anonymous ? $class : $this->getAlias($class);
        if (is_null($this->find($alias))) {
            $this->add($alias, $class, $anonymous, $this->componentConfig($alias));
        }
    }

    /**
     * @param  TComponent  $options
     */
    protected function add(string $alias, string $class, bool $anonymous = false, array $options = []): void
    {
        $namespace = $anonymous ? self::ANONYMOUS_COMPONENT_NAMESPACE.'.' : self::COMPONENT_NAMESPACE.'\\';

        if (! str_starts_with($class, $namespace)) {
            $class = $namespace.$class;
        }

        $tag = $options['tag'] ?? '';
        $contents = $options['contents'] ?? '';
        $attributes = $options['attributes'] ?? [];
        $variants = $options['variants'] ?? [];
        $variants = collect($variants)->map(fn ($variant) => [
            'tag' => $variant['tag'] ?? $tag,
            'attributes' => (new ComponentAttributeBag($variant['attributes']))->merge($attributes)->getAttributes(),
            'contents' => $variant['contents'] ?? $contents,
        ])->toArray();

        $this->components[$namespace][$class][$alias] = compact('tag', 'attributes', 'variants', 'contents');

        if ($this->isLivewire($class, $anonymous)) {
            $this->addLivewireComponent($alias, $class);
        } elseif ($this->isBlade($class, $anonymous)) {
            $this->addBladeComponent($alias, $class);
        }

        foreach ($variants as $name => $variant) {
            $this->add("$name-$alias", $class, $anonymous, $variant);
        }
    }

    protected function addLivewireComponent(string $alias, string $class): void
    {
        Livewire::component($this->prefix()."-$alias", $class);
    }

    protected function addBladeComponent(string $alias, string $class): void
    {
        Blade::component($class, $alias, $this->prefix());
    }

    /**
     * @param  array<string>  $classes
     */
    public function components(array $classes = [], bool $anonymous = false): void
    {
        foreach ($classes as $class => $alias) {
            if (is_int($class)) {
                $class = $alias;
                $alias = null;
            }
            $this->component($class, $alias, $anonymous);
        }
    }

    public function getAlias(string $class, string $namespace = null): string
    {
        $namespace ??= self::COMPONENT_NAMESPACE;
        if (! str_ends_with($namespace, '\\')) {
            $namespace .= '\\';
        }

        if (0 === strpos($class, $namespace)) {
            $class = substr_replace($class, '', 0, strlen($namespace));
        }

        return Str::kebab(implode('', array_reverse(explode('\\', $class))));
    }

    /**
     * @return array{namespace:string,class:string,alias:string:options:TComponent}|null
     */
    public function find(string $component): ?array
    {
        foreach ($this->components as $namespace => $components) {
            foreach ($components as $class => $variants) {
                foreach ($variants as $alias => $options) {
                    if ($alias === $component) {
                        return compact('namespace', 'class', 'alias', 'options');
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,string>|ComponentAttributes  $attributes
     */
    public function make(string $name, array|ComponentAttributes $attributes = [], string|ComponentSlot $slot = null): string
    {
        if ($component = $this->find($name)) {
            ['class' => $class, 'alias' => $alias] = $component;
            $slot = $this->makeComponentSlot($name, $attributes, $slot);

            $render = '';

            if ($this->isLivewire($class)) {
                $render .= $slot->isEmpty()
                ? '<livewire:ui-'.$alias.' '.$slot->attributes->toHtml().'>'
                : "@livewire('ui-$alias', ".$this->attributesToString($slot->attributes).", key({$slot->toHtml()}))";
            } else {
                $render = $this->makeComponentTag("x-ui-$alias", $slot->attributes, $slot)->toHtml();
            }

            $render = $this->render($render);

            return $render;
        } elseif ($options = $this->componentConfig($name)) {
            $attributes = $this->makeComponentAttributes($name, $attributes)->merge($options['attributes'] ?? []);

            return $this->makeComponentTag($options['tag'] ?? $name, $attributes, $slot ??= $options['contents'] ?? '')->toHtml();
        }

        return $this->makeComponentTag($name, $attributes, $slot)->toHtml();
    }

    /**
     * @param  array<string,string>|ComponentAttributes  $attributes
     */
    public function openTag(string $name, array|ComponentAttributes $attributes = [], string|ComponentSlot $contents = null): string
    {
        $tag = $this->makeComponentTag($name, $attributes, $contents);

        if ($tag->isOrphan() || $tag->isNotEmpty()) {
            return $tag->toHtml();
        }

        array_push($this->tags, $tag);

        return $tag->open();
    }

    /**
     * @throws RuntimeException If there is no open tag
     */
    public function closeTag(): string
    {
        if ($tag = array_pop($this->tags)) {
            return $tag->close();
        }

        throw new RuntimeException('No tags open');
    }

    public function isLivewire(string $component, bool $anonymous = false): bool
    {
        return ! $anonymous && (is_subclass_of($component, LivewireBaseComponent::class) || $component instanceof IsLivewireComponent);
    }

    public function isBlade(string $component, bool $anonymous = false): bool
    {
        return $anonymous || (is_subclass_of($component, BladeBaseComponent::class) || $component instanceof IsBladeComponent);
    }

    /**
     * @param  Arrayable<int|string,mixed>|mixed[]  $contentData
     * @param  Arrayable<int|string,mixed>|mixed[]  $layoutData
     */
    public function page(string $contentPath, Arrayable|array $contentData = [], string $layoutPath = null, Arrayable|array $layoutData = [], array $mergeData = []): ViewContract
    {
        if (isset($layoutPath)) {
            $content = ViewFacade::make("contents.$contentPath", $contentData, $mergeData)->render();

            return ViewFacade::make("layouts.$layoutPath", compact('content') + $layoutData, array_merge($contentData instanceof Arrayable ? $contentData->toArray() : $contentData, $mergeData));
        }

        return ViewFacade::make("pages.$contentPath", $contentData, $mergeData);
    }

    /**
     * @param  mixed[]  $data
     */
    public function render(string $template, array $data = [], bool $deleteCachedView = false): string
    {
        $render = Blade::render($template, $data, $deleteCachedView);
        if (! config('app.debug')) {
            $render = $this->compress($render);
        }

        return $render;
    }

    public function compress(string $code): string
    {
        $code = preg_replace([
            '/(?:\v|\t|\s)+/m',
            '/[\s ]*\</s',
            '/[\s ]*(\/?\>)[\s ]*/s',
            '/=\s+(\"|\')/',
            '/(\"|\')\s+(\/?\>)/s',
            '/<!--.*?-->/s',
        ], [
            ' ',
            '<',
            '$1',
            '=$1',
            '$1$2',
            '',
        ], $code) ?: $code;

        $code = trim($code);

        return $code;
    }

    /**
     * @param  array<string,string>|ComponentAttributes  $attributes
     */
    public function makeComponentTag(string $component, array|ComponentAttributes $attributes = [], string|ComponentSlot $contents = null): ComponentTag
    {
        $tag = $this->getComponentTag($component) ?: $component;

        $slot = $this->makeComponentSlot($component, $attributes, $contents);

        return ComponentTag::from($tag, $slot->attributes->getAttributes(), $slot->toHtml());
    }

    /**
     * @param  array<string,string>|ComponentAttributes  $attributes
     */
    public function makeComponentSlot(string $component, array|ComponentAttributes $attributes = [], string|ComponentSlot $contents = null): ComponentSlot
    {
        $attributes = $this->makeComponentAttributes($component, $attributes);

        /** @var string|ComponentSlot */
        $defaultContents = $this->getComponentContents($component, '');
        $contents ??= $defaultContents;
        if ($contents instanceof ComponentSlot) {
            $contents = $contents->toHtml();
        }

        return new ComponentSlot($contents, $attributes->getAttributes());
    }

    /**
     * @param  array<string,string>|ComponentAttributes  $attributes
     */
    public function makeComponentAttributes(string $component, array|ComponentAttributes $attributes = []): ComponentAttributes
    {
        if (! $attributes instanceof ComponentAttributes) {

            $attributes = new ComponentAttributes($attributes);
        }

        return $attributes->merge((array) $this->getComponentAttributes($component, []));
    }

    protected function attributesToString(ComponentAttributes $attributes): string
    {
        return $this->toArrayString($attributes->getAttributes());
    }

    protected function attributesToHtml(ComponentAttributes $attributes): string
    {
        return $attributes->toHtml();
    }

    protected function toHtmlString(mixed $value): string
    {
        return var_export($value, true);
    }

    /**
     * @param  Arrayable<string,string>|iterable<string,string>  $array
     */
    protected function toArrayString(Arrayable|iterable $array): string
    {
        $array = collect($array)
            ->map(function (string $value, string $key): string {
                return "'{$key}' => {$value}";
            })
            ->implode(',');
        $array = "[$array]";

        return $array;
    }
}
