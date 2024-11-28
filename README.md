
## Details

1. **Namespace and Imports:**
    - The namespace is structured to reflect the project's architecture.
    - Necessary external classes are imported at the top of the file.

2. **Comments:**
    - Each method and significant block is documented with clear comments.

3. **Naming Conventions:**
    - Classes and variables are intuitively named to improve readability.

4. **Organized Structure:**
    - Calls are separated into distinct sections: synchronous, multi-threaded, and await-generator.

---

## Prerequisites

Ensure the `phpredis` extension is installed and enabled on your PHP instance before running this code.

---

## Notes

- This code is designed to demonstrate Redis calls using a well-structured PHP class.
- Customize the Redis keys used in the example (`faction:123:members` and `test`) to fit your use case.
---
# Redis Example

An example of using Redis in a PHP class to retrieve faction members and perform additional operations.

## `GetMemberFactionRequest` Class
```php
<?php

namespace SenseiTarzan\redistest;

use Redis;
use SenseiTarzan\libredis\Class\Request;
use SenseiTarzan\libredis\Class\Response;

/**
 * Class GetMemberFactionRequest
 * Handles fetching faction members and performs additional Redis operations.
 */
class GetMemberFactionRequest extends Request
{
    /**
     * Executes the request to fetch faction members and increments a Redis counter.
     *
     * @param Redis $client The Redis client instance.
     * @param array $argv Additional arguments (not used in this example).
     * @return Response A response containing the list of faction members.
     */
    public static function request(Redis $client, array $argv): Response
    {
        // Fetch members of a specific faction
        $data = $client->sMembers("faction:123:members");
        
        // Increment a test counter in Redis
        $client->incrBy("test", 1);
        
        // Return the response with the fetched data
        return new FindResponse($data);
    }
}
```

# `FindResponse` Class

This class represents a response object for handling data fetched from Redis in a structured manner.
```php
<?php

namespace SenseiTarzan\redistest;

use SenseiTarzan\libredis\Class\Response;

/**
 * Class FindResponse
 * Represents a response containing data retrieved from Redis.
 */
class FindResponse extends Response
{
    /**
     * Constructor for the FindResponse class.
     *
     * @param array $data_test The data retrieved from Redis.
     */
    public function __construct(
        private array $data_test
    ) {
    }

    /**
     * Retrieve the test data from the response.
     *
     * @return array The data retrieved from Redis.
     */
    public function getDataTest(): array
    {
        return $this->data_test;
    }
}
```

---

## Usage in Different Contexts

### 1. Main Thread Execution

```php
$start = microtime(true);
$data = $this->manager->syncRequest(GetMemberFactionRequest::class);

echo "Benchmark request members list: " . ((microtime(true) - $start) * 1000) . "ms" . PHP_EOL;
```

---

### 2. Multi-threaded Execution

```php
$start = microtime(true);
$data = Main::$instance->getManager()->executeRequestOnThread(
    GetMemberFactionRequest::class,
    handler: function (FindResponse $data) use ($start, $event) {
        echo "Benchmark request members list: " . ((microtime(true) - $start) * 1000) . "ms" . PHP_EOL;
    }
);
```

---

### 3. Execution with `await-generator`

```php
Await::f2c(
    function () { 
        $start = microtime(true);
        $data = yield from $this->manager->asyncRequest(GetMemberFactionRequest::class); 
        return (microtime(true) - $start) * 1000;
    }, 
    function (float|int $time) {
        echo $time . PHP_EOL;
    }
);
```

