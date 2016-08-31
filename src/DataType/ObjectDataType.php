<?php namespace OpenAPI\Consumer\DataType;

use Illuminate\Contracts\Validation\ValidationException;

class ObjectDataType extends DataType
{
    protected $properties = null;

    public function validate($value)
    {
        // First make sure all required properties have a value.
        $required = array_get($this->specification, 'required', []);
        if (count($required)) {
            $validator = \Validator::make($value, array_combine($required, array_fill(0, count($required), 'required')));
            if ($validator->fails()) {
                throw new ValidationException($validator->messages());
            }
        }

        // Still here, continue validating all properties separately
        foreach ($value as $property => $propertyValue) {
            $property = $this->findProperty($property);
            $property->validate($propertyValue);
        }
    }

    protected function initProperties()
    {
        if (is_array($this->properties)) {
            return;
        }

        $this->properties = [];
        $properties       = array_get($this->specification, 'properties', []);
        foreach ($properties as $name => $type) {
            $this->properties[$name] = self::create($this->client, $name, $type);
        }
    }

    public function getProperties()
    {
        $this->initProperties();
        return $this->properties;
    }

    protected function findProperty($path)
    {
        $this->initProperties();
        $remnant = null;
        $path    = preg_split('@\./@', $path, 2);

        if (count($path) === 2) {
            list($path, $remnant) = $path;
        } else {
            $path = head($path);
        }

        if (!array_has($this->properties, $path)) {
            throw new \InvalidArgumentException("Invalid property specified (\"$path\")");
        }

        $property = $this->properties[$path];
        // If a remnant path is present, pass on
        if ($remnant) {
            if (!$property instanceof static) {
                throw new \InvalidArgumentException("Invalid property specified (\"$remnant\")");
            }
            return $property->findProperty($remnant);
        }

        return $property;
    }
}