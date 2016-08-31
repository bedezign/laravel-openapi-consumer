<?php namespace OpenAPI\Consumer;

use OpenAPI\Consumer\DataType\DataType;

class Operation
{
    use SpecificationTrait;

    protected $verb;
    protected $path;
    protected $parameters;

    public function __construct($client, $verb, $path, $configuration)
    {
        $this->client        = $client;
        $this->verb          = $verb;
        $this->path          = $path;
        $this->specification = $configuration;
    }

    /**
     * @param string $name
     * @return DataType
     */
    public function getParameter($name)
    {
        return array_get($this->getParameters(), $name);
    }

    /**
     * @return array
     */
    public function getParameters()
    {
        if (!$this->parameters) {
            $parameters = array_get($this->specification, 'parameters', []);
            foreach ($parameters as $parameter) {
                $parameter = new Parameter($this->client, $parameter);
                $this->parameters[$parameter->name] = $parameter;
            }
        }

        return $this->parameters;
    }
}