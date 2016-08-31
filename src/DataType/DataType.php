<?php

namespace OpenAPI\Consumer\DataType;

use OpenAPI\Consumer\SpecificationTrait;
use Illuminate\Contracts\Validation\ValidationException;
use Validator;

class DataType
{
    use SpecificationTrait;
    protected $name;

    public function __construct($client, $name, $specification)
    {
        $this->client        = $client;
        $this->name          = $name;
        $this->specification = $specification;
    }

    protected function rules()
    {
        $rules = [];
        // Required value (scalar types only)?
        if ($this->required) {
            $rules[] = 'required';
        }

        if ($values = $this->enum) {
            $rules[] = 'in:' . implode(',', $values);
        }

        // @todo:
        // maximum number
        // exclusiveMaximum boolean
        // minimum number
        // exclusiveMinimum boolean
        // maxLength integer
        // minLength integer
        // pattern string
        // maxItems integer
        // minItems integer
        // uniqueItems boolean
        // multipleOf

        return $rules;
    }

    public function validate($value)
    {
        $validator = Validator::make([$this->name => $value], [$this->name => $this->rules()]);
        if ($validator->fails()) {
            throw new ValidationException($validator->messages());
        }
    }

    /**
     * Creates a new DataType instance based on the given specification
     * @param        $client
     * @param string $name
     * @param array  $specification
     * @return \OpenAPI\Consumer\DataType\DataType
     */
    public static function create($client, $name, $specification)
    {
        $type = array_get($specification, 'type');
        if (!$type) {
            throw new \InvalidArgumentException('Specification does not contain a "type"');
        }

        $class = __NAMESPACE__ . '\\' . ucfirst($type) . 'DataType';
        return new $class($client, $name, $specification);
    }
}