# OpenAPI Consumer

PoC to see if it is possible to automatically consume an API that has an [OpenAPI specification](https://github.com/OAI/OpenAPI-Specification) (pka Swagger).

At this point the library is written for laravel.

If you need to use it, simply create a Client instance with the correct configuration

    $api = new \OpenAPI\Consumer\Client('json specification path', [&lt;extra configuration&gt;])

You can then call any operation from the API in a very simplistic way:

    $call = $api->operationName->with(['api-data' => 'api-data-value'])->execute();
    if ($call->statusCode == 200) {
        dd($call->json);
    }
    
    