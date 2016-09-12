<?php namespace OpenAPI\Consumer;

use GuzzleHttp\Client as GuzzleClient;

class Client
{
    use SpecificationTrait {
        __get as __traitGet;
    }

    protected $specificationLocation;

    /** @var string     If set, overrides the schemes from the specification */
    protected $scheme = null;
    /** @var string     If set, this will override the host defined in the OpenAPI specification */
    protected $host = null;
    /** @var string     If set, this will override the basePath defined in the OpenAPI specification */
    protected $basePath = null;

    /** @var GuzzleClient */
    protected $guzzleClient;
    protected $guzzleConfig;
    protected $guzzleExceptionHandler;

    protected $aliases    = [];
    protected $operations = [];

    /** @var array List of references that are allowed to fail without interrupting the loading process. The related operations can't be used though */
    protected $silentFailReferences = [
        '#/definitions/Principal'       // Springfox internal reference
    ];

    /**
     * Client constructor.
     * @param       $specification
     * @param array $config supported: 'host', 'basePath, 'scheme', 'guzzleConfig', 'aliases'
     */
    public function __construct($specification, $config = [])
    {
        $this->specificationLocation = $specification;
        $this->host                  = array_get($config, 'host');
        $this->basePath              = array_get($config, 'basePath');
        $this->scheme                = array_get($config, 'scheme');
        $this->guzzleConfig          = array_get($config, 'guzzleConfig');
        $this->setAliases(array_get($config, 'aliases'));

        $this->initialize();
    }

    public function getOperation($name)
    {
        $name      = $this->getAlias('operation', $name, $name);
        $operation = array_get($this->operations, $name);
        if ($operation) {
            return $operation;
        }

        $paths = array_get($this->specification, 'paths');
        foreach ($paths as $path => $operations) {
            foreach ($operations as $verb => $operation) {
                $operationId = array_get($operation, 'operationId', str_replace('/', '', $path) . $verb);
                if ($operationId === $name) {
                    $this->operations[$operationId] = new Operation($this, $verb, $path, $operation);
                    if ($this->guzzleExceptionHandler) {
                        $this->operations[$operationId]->setGuzzleExceptionHandler($this->guzzleExceptionHandler);
                    }
                    return $this->operations[$operationId];
                }
            }
        }

        return null;
    }

    /**
     * Allows you to alias the API's identifiers. This allows you to make your code calling the API more clear, or perhaps modify parameter names so they match your code, saving you from having to modify them.
     * Some examples:
     *  setAlias('operation', ['userCreate' => 'createUserUsingPOST']) - allow the `createUserUsingPOST` operation to be called as "userCreate" (`$client->userCreate->with([])->execute();`)
     *  setAlias('parameter', ['email' => 'emailAddress']) - If the API forces you to use "emailAddress", this will allow you to use "email" instead (this works for everything called "emailAddress")
     *  setAlias('parameter', ['email' => 'userData/emailAddress']) - Like above, but the alias is only valid for operations that have a parameter named "userData"
     *
     * @param string|array $type        Either a type (supported: operation, parameter)
     * @param array|null $aliases
     */
    public function setAliases($type, $aliases = null)
    {
        if (is_array($type) && $aliases === null) {
            $this->aliases = $type;
        } else {
            $this->aliases[$type] = $aliases;
        }
    }

    /**
     * @param  string      $type
     * @param  string|null $alias Alias to look up, null if you want all aliases for the requested $type
     * @param null         $default
     * @return string|array
     */
    public function getAlias($type, $alias = null, $default = null)
    {
        $aliases = array_get($this->aliases, $type, []);
        if ($aliases === null) {
            return $aliases;
        }

        return array_get($aliases, $alias, $default);
    }

    public function getHost()
    {
        return $this->host ? $this->host : array_get($this->specification, 'host');
    }

    /**
     * Returns an API request that you can then use to execute a call
     * @param string $name
     * @return \OpenAPI\Consumer\Request|null
     */
    public function __get($name)
    {
        $operation = $this->getOperation($name);
        if ($operation) {
            return new Request($this, $operation, $this->getGuzzleClient());
        }

        return $this->__traitGet($name);
    }

    public function getGuzzleClient()
    {
        if (!$this->guzzleClient) {
            if (!array_has($this->guzzleConfig, 'base_uri')) {
                // The basePath-part is handled by the Request itself. Guzzle doesn't combine 2 URI parts with a basePath correctly, the initial one is discarded
                $this->guzzleConfig['base_uri'] = sprintf('%s://%s', $this->scheme, $this->host);
            }

            $this->guzzleClient = new GuzzleClient($this->guzzleConfig);
        }

        return $this->guzzleClient;
    }

    /**
     * Normally Guzzle exceptions are silently consumed (and will result in a failed request).
     * This function allows you to specify an exception handler that will be called for all guzzle exceptions
     *      function exceptionHandler(Request $request, ClientException $exception)
     * By setting the handler on client level, it will be passed to all created requests
     * @param Callable $handler
     */
    public function setGuzzleExceptionHandler($handler)
    {
        $this->exceptionHandler = $handler;
    }

    protected function initialize()
    {
        $basePath            = dirname($this->specificationLocation);
        $specification       = \GuzzleHttp\json_decode(file_get_contents($this->specificationLocation), true);
        $this->specification = $this->resolve($specification, $basePath);
        $this->host          = $this->host ?: array_get($this->specification, 'host');
        $this->basePath      = $this->basePath ?: array_get($this->specification, 'basePath');

        if (($pos = strpos($this->host, '://')) !== false) {
            // Some (faulty) OpenAPI specifications seem to have the scheme set as part of the host
            $this->scheme = substr($this->host, 0, $pos);
            $this->host   = substr($this->host, $pos + 3);
        }

        $this->scheme = $this->scheme ?: head(array_get($this->specification, 'schemes', ['http']));
    }

    /**
     * Attempts to resolve all references in the specification. This allows for better cacheability.
     *
     * @param array  $specification
     * @param string $basePath Base path of the main specification, in case we need to load external references
     * @return array
     */
    protected function resolve($specification, $basePath)
    {
        $resolved             = 0;
        $iterator             = new \RecursiveArrayIterator($specification);
        $updatedSpecification = $specification;
        $walker               = function ($iterator, $keyPrefix = '') use (&$walker, &$updatedSpecification, &$resolved) {
            while ($iterator->valid()) {
                $key = $keyPrefix . (strlen($keyPrefix) ? '|' : '') . $iterator->key();
                if ($iterator->hasChildren()) {
                    $walker($iterator->getChildren(), $key);
                } else {
                    // Reference? Resolve it
                    if ($iterator->key() === '$ref') {
                        $resolved ++;

                        $value     = null;
                        $reference = $iterator->current();

                        if (0 === strpos($reference, '#')) {
                            // Within the same specification
                            $value = array_get_delimiter($updatedSpecification, str_replace('/', '|', substr($reference, 2)), '|');
                        }
                        // @todo add support for other files etc

                        if ($value === null && !in_array($reference, $this->silentFailReferences)) {
                            throw new \InvalidArgumentException('Unable to resolve reference ' . $reference);
                        }

                        // Cut off the "$ref" from the key
                        $key = substr($key, 0, - 5);
                        array_set_delimiter($updatedSpecification, $key, $value, '|');
                    }
                }
                $iterator->next();
            }
        };
        iterator_apply($iterator, $walker, [$iterator]);
        $specification = $updatedSpecification;

        // Repeat as long as we resolved things, make sure that we get everything
        return $resolved ? $this->resolve($specification, $basePath) : $specification;
    }
}
