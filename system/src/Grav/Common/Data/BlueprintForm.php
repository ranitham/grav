<?php
namespace Grav\Common\Data;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use RocketTheme\Toolbox\ArrayTraits\Export;
use RocketTheme\Toolbox\ArrayTraits\ExportInterface;
use RocketTheme\Toolbox\ArrayTraits\NestedArrayAccessWithGetters;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * The Config class contains configuration information.
 *
 * @author RocketTheme
 */
class BlueprintForm implements \ArrayAccess, ExportInterface
{
    use NestedArrayAccessWithGetters, Export;

    /**
     * @var array
     */
    protected $items;

    /**
     * @var string
     */
    protected $filename;

    /**
     * @var string
     */
    protected $context = 'blueprints://';

    /**
     * @var array
     */
    protected $overrides = [];

    /**
     * Constructor.
     *
     * @param string|array $filename
     * @param array $items
     */
    public function __construct($filename, array $items = [])
    {
        $this->filename = $filename;
        $this->items = $items;
    }

    /**
     * Set context for import@ and extend@.
     *
     * @param $context
     * @return $this
     */
    public function setContext($context)
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Set custom overrides for import@ and extend@.
     *
     * @param array $overrides
     * @return $this
     */
    public function setOverrides(array $overrides)
    {
        $this->overrides = $overrides;

        return $this;
    }

    /**
     * Load blueprint.
     *
     * @return $this
     */
    public function load()
    {
        $path = $this->filename;

        if (is_string($path) && !strpos($path, '://')) {
            // Resolve filename.
            $path = isset($this->overrides[$path]) ? $this->overrides[$path] : "{$this->context}{$path}";
            if (!preg_match('/\.yaml$/', $path)) {
                $path .= YAML_EXT;
            }
        }

        // Only load and extend blueprint if it has not yet been loaded.
        if (empty($this->items)) {
            // Get list of parent files.
            if (is_string($path) && strpos($path, '://')) {
                /** @var UniformResourceLocator $locator */
                $locator = Grav::instance()['locator'];

                $files = $locator->findResources($path);
            } else {
                $files = (array) $path;
            }

            // Load and extend blueprints.
            $data = $this->doLoad($files);

            $this->items = array_shift($data);
            foreach ($data as $content) {
                $this->extend($content, true);
            }
        }

        // Finally initialize blueprint.
        return $this->init();
    }

    /**
     * Initialize blueprint.
     *
     * @return $this
     */
    public function init()
    {
        // Import blueprints.
        $this->deepInit($this->items);

        return $this;
    }

    /**
     * Get form.
     *
     * @return array
     */
    public function form()
    {
        return (array) $this->get('form');
    }

    /**
     * Get form fields.
     *
     * @return array
     */
    public function fields()
    {
        return (array) $this->get('form.fields');
    }

    /**
     * Extend blueprint with another blueprint.
     *
     * @param BlueprintForm|array $extends
     * @param bool $append
     * @return $this
     */
    public function extend($extends, $append = false)
    {
        if ($extends instanceof BlueprintForm) {
            $extends = $extends->toArray();
        }

        if ($append) {
            $a = $this->items;
            $b = $extends;
        } else {
            $a = $extends;
            $b = $this->items;
        }

        $this->items = $this->deepMerge($a, $b);

        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @param string $separator
     * @param bool $append
     * @return $this
     */
    public function embed($name, $value, $separator = '/', $append = false)
    {
        $oldValue = $this->get($name, null, $separator);

        if (is_array($oldValue) && is_array($value)) {
            if ($append) {
                $a = $oldValue;
                $b = $value;
            } else {
                $a = $value;
                $b = $oldValue;
            }

            $value = $this->deepMerge($a, $b);
        }

        $this->set($name, $value, $separator);

        return $this;
    }

    /**
     * Get blueprints by using dot notation for nested arrays/objects.
     *
     * @example $value = $this->resolve('this.is.my.nested.variable');
     * returns ['this.is.my', 'nested.variable']
     *
     * @param array  $path
     * @param string  $separator
     * @return array
     */
    public function resolve(array $path, $separator = '/')
    {
        $fields = false;
        $parts = [];
        $current = $this['form.fields'];
        $result = [null, null, null];

        while (($field = current($path)) !== null) {
            if (!$fields && isset($current['fields'])) {
                if (!empty($current['array'])) {
                    $result = [$current, $parts, $path ? implode($separator, $path) : null];
                    // Skip item offset.
                    $parts[] = array_shift($path);
                }

                $current = $current['fields'];
                $fields = true;

            } elseif (isset($current[$field])) {
                $parts[] = array_shift($path);
                $current = $current[$field];
                $fields = false;

            } elseif (isset($current['.' . $field])) {
                $parts[] = array_shift($path);
                $current = $current['.' . $field];
                $fields = false;

            } else {
                break;
            }
        }

        return $result;
    }

    /**
     * Deep merge two arrays together.
     *
     * @param array $a
     * @param array $b
     * @return array
     */
    protected function deepMerge(array $a, array $b)
    {
        $bref_stack = array(&$a);
        $head_stack = array($b);

        do {
            end($bref_stack);
            $bref = &$bref_stack[key($bref_stack)];
            $head = array_pop($head_stack);
            unset($bref_stack[key($bref_stack)]);

            foreach (array_keys($head) as $key) {
                if (isset($key, $bref[$key]) && is_array($bref[$key]) && is_array($head[$key])) {
                    $bref_stack[] = &$bref[$key];
                    $head_stack[] = $head[$key];
                } else {
                    $bref = array_merge($bref, [$key => $head[$key]]);
                }
            }
        } while (count($head_stack));

        return $a;
    }

    protected function deepInit(array &$items, $path = [])
    {
        foreach ($items as $key => &$item) {
            if (!empty($key) && ($key[0] === '@' || $key[strlen($key) - 1] === '@')) {
                $name = trim($key, '@');

                if ($name === 'import') {
                    $this->doImport($item, $path);
                    unset($items[$key]);
                }

            } elseif (is_array($item)) {
                $newPath = array_merge($path, [$key]);

                $this->deepInit($item, $newPath);
            }
        }
    }

    /**
     * @param array $value
     * @param array $path
     */
    protected function doImport(array &$value, array &$path)
    {
        $type = !is_string($value) ? !isset($value['type']) ? null : $value['type'] : $value;

        if (strpos($type, '://')) {
            $filename = $type;
        } elseif (empty($value['context'])) {
            $filename = isset($this->overrides[$type]) ? $this->overrides[$type] : "{$this->context}{$type}";
        } else {
            $separator = $value['context'][strlen($value['context'])-1] === '/' ? '' : '/';
            $filename = $value['context'] . $separator . $type;
        }
        if (!preg_match('/\.yaml$/', $filename)) {
            $filename .= YAML_EXT;
        }


        if (!is_file($filename)) {
            return;
        }

        $blueprint = (new BlueprintForm($filename))->setContext($this->context)->setOverrides($this->overrides)->load();

        $name = implode('/', $path);

        $this->embed($name, $blueprint->form(), '/', false);
    }

    /**
     * Internal function that handles loading extended blueprints.
     *
     * @param array $files
     * @return array
     */
    protected function doLoad(array $files)
    {
        $filename = array_shift($files);
        $file = CompiledYamlFile::instance($filename);
        $content = $file->content();
        $file->free();

        $extends = isset($content['@extends']) ? (array) $content['@extends']
            : (isset($content['extends@']) ? (array) $content['extends@'] : null);

        $data = isset($extends) ? $this->doExtend($files, $extends) : [];
        $data[] = $content;

        return $data;
    }

    /**
     * Internal function to recursively load extended blueprints.
     *
     * @param array $parents
     * @param array $extends
     * @return array
     */
    protected function doExtend(array $parents, array $extends)
    {
        if (is_string(key($extends))) {
            $extends = [$extends];
        }

        $data = [];
        foreach ($extends as $value) {
            // Accept array of type and context or a string.
            $type = !is_string($value)
                ? !isset($value['type']) ? null : $value['type'] : $value;

            if (!$type) {
                continue;
            }

            if ($type === '@parent' || $type === 'parent@') {
                $files = $parents;

            } else {
                if (strpos($type, '://')) {
                    $path = $type;
                } elseif (empty($value['context'])) {
                    $path = isset($this->overrides[$type]) ? $this->overrides[$type] : "{$this->context}{$type}";
                } else {
                    $separator = $value['context'][strlen($value['context'])-1] === '/' ? '' : '/';
                    $path = $value['context'] . $separator . $type;
                }
                if (!preg_match('/\.yaml$/', $path)) {
                    $path .= YAML_EXT;
                }

                if (strpos($path, '://')) {
                    /** @var UniformResourceLocator $locator */
                    $locator = Grav::instance()['locator'];

                    $files = $locator->findResources($path);
                } else {
                    $files = (array) $path;
                }
            }

            if ($files) {
                $data = array_merge($data, $this->doLoad($files));
            }
        }

        return $data;
    }
}
