<?php namespace OpenAPI\Consumer;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;

class Request
{
    /** @var Client */
    protected $client;
    /** @var  Operation */
    protected $operation;
    /** @var GuzzleClient */
    protected $guzzle;
    /** @var ClientException */
    protected $exception;


    /** @var  string */
    protected $uri;
    /** @var  array Collected data to pass along with the request */
    protected $data;
    /** @var Psr7Request */
    protected $request;
    /** @var Psr7Response */
    protected $response;
    /** @var array */
    protected $smartFill;

    public function __construct(Client $apiClient, Operation $operation, GuzzleClient $guzzle)
    {
        $this->client    = $apiClient;
        $this->operation = $operation;
        $this->guzzle    = $guzzle;
    }

    /**
     * Add data to pass to the API. It is okay to call this multiple times, the data will be merged.
     *
     * @param array $data      The data to register
     * @param bool  $smartFill If enabled, this will attempt to automatically created the correct data format based on the aliases and names. You can ignore the required structure of the data
     * @return $this
     */
    public function with(array $data, $smartFill = false)
    {
        if ($smartFill) {
            $data = $this->smartFillData($data);
        }

        $this->data = array_merge($this->data ?: [], $data);
        return $this;
    }

    public function execute(array $data = null)
    {
        if ($data) {
            $this->with($data);
        }

        // Prepare the data we need to perform the request
        $this->uri = $this->operation->path;
        $verb      = strtolower($this->operation->verb);
        $options   = $this->createRequestOptions($this->data);

        // We still need to add the main basePath to the uri we have one.
        // If the guzzle client is configured with a base_uri that has a path part, specifying another path part
        //  - which happens here - will overwrite that one, resulting in the wrong target URI.
        $uri = rtrim($this->client->basePath ?: '', '/') . '/' . ltrim($this->uri, '/');

        try {
            // Finally, execute the request
            $this->response = $this->guzzle->$verb($uri, $options);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            if ($e->hasResponse()) {
                $this->response = $e->getResponse();
            }
            $this->exception = $e;
        }

        return $this;
    }

    /**
     * Validates success of the request (no exception was thrown, a response is available and it has an acceptable status)
     * @param int $acceptedStatus
     * @return bool
     */
    public function succeeded($acceptedStatus = 200)
    {
        $acceptedStatus = is_array($acceptedStatus) ? $acceptedStatus : [$acceptedStatus];
        return $this->exception === null && $this->response && in_array($this->response->getStatusCode(), $acceptedStatus);
    }

    /**
     * Manually set data to pass along to the API call.
     * Note: This does smart fill by default
     *
     * @param string $name
     * @param mixed  $value
     */
    public function __set($name, $value)
    {
        $this->with([$name => $value], true);
    }

    public function __get($name)
    {
        switch ($name) {
            case 'request' :
                return $this->request;

            case 'requestData' :
                return $this->data;

            case 'response' :
                return $this->response;

            case 'exception' :
                return $this->exception;

            case 'statusCode' :
                if ($this->response) {
                    return $this->response->getStatusCode();
                }

                return $this->exception ? $this->exception->getCode() : null;

            case 'body' :
                if ($this->response) {
                    return $this->response->getBody();
                }

                return $this->exception ? $this->exception->getMessage() : null;

            case 'json' :
                if ($this->response && head($this->operation->produces) === 'application/json') {
                    $responseBody = $this->response->getBody();
                    return json_decode($responseBody, true, 512, JSON_OBJECT_AS_ARRAY);
                }
                return null;
        }

        return null;
    }

    public function __call($name, $arguments)
    {
        return $this->operation->$name(...$arguments);
    }

    /**
     * Take the given data and assemble it into an options-array for guzzle.
     *
     * @param array $data
     * @return array
     */
    protected function createRequestOptions($data)
    {
        $options = [
            'headers'     => [],
            'query'       => [],
            'body'        => [],
            'form_params' => [],
        ];

        $bodyParameter = null;
        foreach ($this->operation->getParameters() as $parameter) {
            $name = $parameter->name;

            $value = array_get($data, $name);
            $parameter->validate($value);

            if ($value === null) {
                continue;
            }

            $in = $parameter->in;
            if ($in === 'path') {
                $this->uri = str_replace('{' . $name . '}', $value, $this->uri);
            } else {
                $key                  = ['header' => 'headers', 'query' => 'query', 'body' => 'body', 'formData' => 'form_data'][$parameter->in];
                $options[$key][$name] = $value;

                if ($in === 'body') {
                    $bodyParameter = $parameter;
                }
            }
        }

        $options = array_filter($options);

        if (array_has($options, 'body')) {
            // Even though the OpenAPI specification forces a name for the body parameter, it isn't used. Make sure to eliminate that extra level in the data
            $options['body'] = $options['body'][$bodyParameter->name];

            if (head($this->operation->consumes) === 'application/json') {
                $options['json'] = $options['body'];
                unset($options['body']);
            }
        }

        return $options;
    }

    /**
     * Attempt to automatically structure the given data according to the aliases and detected parameter structures.
     * This allows you to specify a flat array that will automatically be formatted correctly.
     *
     * @param array $data
     * @return array
     */
    protected function smartFillData($data)
    {
        $smartFill = $this->getSmartFillData();
        $result    = [];
        foreach ($data as $path => $value) {
            $target = null;
            if (array_key_exists($path, $smartFill)) {
                // Direct assignment
                $target = $path;
            } else {
                foreach ($smartFill as $possibleTarget => $aliases) {
                    if (in_array($path, $aliases, true)) {
                        $target = $possibleTarget;
                        break;
                    }
                }
            }
            if (!$target) {
                $target = $path;
            }

            array_set($result, $target, $value);
        }

        return $result;
    }

    /**
     * Build the array used to perform smart filling
     * @return array
     */
    protected function getSmartFillData()
    {
        if ($this->smartFill) {
            return;
        }

        $aliases         = str_replace('/', '.', $this->client->getAlias('parameter'));
        $this->smartFill = [];
        foreach ($this->operation->getParameters() as $parameter) {
            $this->smartFill = array_merge($this->smartFill, $this->smartFillDataHelper($parameter->dataType, '', $aliases));
        }

        return $this->smartFill;
    }

    /**
     * @param DataType $item
     * @param string   $prefix
     * @param array    $aliases
     * @return array
     */
    protected function smartFillDataHelper($item, $prefix, $aliases)
    {
        $smartFill = [];
        $path      = $prefix . (strlen($prefix) ? '.' : '') . $item->name;

        // Assemble an array containing every variant of the current item, starting with just the name and adding a single path-piece at a time (makes for easier comparison)
        $pieces  = explode('.', $path);
        $targets = [];
        while (count($pieces)) {
            $piece     = array_pop($pieces);
            $previous  = end($targets);
            $targets[] = $piece . (strlen($previous) ? '.' : '') . $previous;
        }

        // By default we support the "base name" of the item to smart fill this.
        $smartFill[$path] = [$item->name];
        foreach ($aliases as $alias => $target) {
            if (in_array($target, $targets)) {
                $smartFill[$path][] = $alias;
            }
        }

        if ($item->type == 'object') {
            foreach ($item->properties as $property) {
                $smartFill = array_merge($smartFill, $this->smartFillDataHelper($property, $path, $aliases));
            }
        }

        return $smartFill;
    }
}