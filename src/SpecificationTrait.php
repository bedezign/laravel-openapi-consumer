<?php  namespace OpenAPI\Consumer;

use Illuminate\Support\Str;

trait SpecificationTrait {
    /** @var Client */
    protected $client;

    /** @var  array */
    protected $specification;

    public function __get($name)
    {
        if (method_exists($this, 'get'.Str::studly($name))) {
            return $this->{'get'.Str::studly($name)}();
        }

        if (property_exists($this, $name)) {
            return $this->$name;
        }

        // Support JSON structure reference
        $name = str_replace(['#/', '/', '.'], ['', '|', '|'], $name);

        // These can also be obtained from the object root if not set locally
        $fallback = in_array($name, ['consumes', 'produces', 'schemes']);
        if (!array_has_delimiter($this->specification, $name, '|') && $fallback) {
            return $this->client->$name;
        }

        $default = array_get(['required' => false, 'deprecated' => false], $name);
        return array_get_delimiter($this->specification, $name, '|', $default);
    }
}