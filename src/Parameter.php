<?php namespace OpenAPI\Consumer;

use OpenAPI\Consumer\DataType\DataType;
use Illuminate\Contracts\Validation\ValidationException;

class Parameter
{
    use SpecificationTrait;
    protected $dataType;

    public function __construct($client, $specification)
    {
        $this->client = $client;
        $this->specification = $specification;
    }

    public function validate($value)
    {
        // Extra validation if the parameter is required & we received an empty value
        if ($this->required) {
            $validator = \Validator::make([$this->name => $value], [$this->name => 'required']);
            if ($validator->fails()) {
                throw new ValidationException($validator->messages());
            }
        }

        // Now validate the regular data type
        return $this->getDataType()->validate($value);
    }

    public function getDataType()
    {
        if (!$this->dataType) {
           $this->dataType = DataType::create($this->client, $this->name, 'body' === $this->in ? $this->schema : $this->specification);
        }
        return $this->dataType;
    }
}