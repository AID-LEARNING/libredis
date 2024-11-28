## Objective of the Test
- https://timings.pmmp.io/?id=338715
- https://timings.pmmp.io/?id=338716
- https://timings.pmmp.io/?id=338720 lag create with PlayerQuitEvent 180 * 10 request is 1800 request in parallel
- https://timings.pmmp.io/?id=338725 after suppression of PlayerQuitEvent

1. **Handle 135-252 simultaneous players**:
   - Players can interact (e.g., send messages or join the server).
   - Data is fetched and incremented in Redis in real time.

2. **Maintain 20 TPS**:
   - Redis requests should have a latency of 0-2 ms maximum.
   - Minimize delays between the Redis thread and the main thread to keep server performance stable.

3. **Avoid excessive CPU usage**:
   - Overusing `asyncRequest` increases CPU load significantly.
   - Avoid introducing a delay (e.g., 99 ms) between the Redis thread and the main thread, especially under heavy load.
---

## Code Analysis

### 1. **Connecting to Redis**
The `libredis` library creates a persistent Redis connection during plugin activation. Please refer to the **test** folder in the repository for detailed examples of the implementation:
```php
$this->manager = libredis::create($this, [
    "host" => "127.0.0.1",
    "port" => 6379,
    "persistent" => true
]);
```

**Key Points**:
- Persistent connections reduce overhead by reusing existing connections.
- This is crucial when handling frequent requests from many players.

---

### 2. **Handling Events**

#### **PlayerChatEvent**
When a player sends a message, Redis requests and timing logs are executed. Detailed code for this can be found in the **test** folder of the repository:
```php
$start = microtime(true);
$data = $this->manager->syncRequest(GetMememberFactionRequest::class);
echo "Benchmark request members list: " . ((microtime(true) - $start) * 1000) . "ms" . PHP_EOL;
```

#### **PlayerJoinEvent**
When a player joins, faction members are retrieved, and the player is sent a message. Implementation details are available in the **test** folder:
```php
$data = $this->manager->syncRequest(GetMememberFactionRequest::class);
foreach ($data->getDataTest() as $test) {
    $event->getPlayer()->sendMessage($test);
}
```

#### **PlayerQuitEvent**
When a player leaves, multiple Redis requests are executed in parallel. See the **test** folder for examples:
```php
Await::f2c(function () use ($event) {
    $start = microtime(true);
    $promises = [];
    for ($i = 0; $i < 10; ++$i) {
        $promises[] = $this->manager->asyncRequest(GetMememberFactionRequest::class);
    }
    yield from Await::all($promises);
    return (microtime(true) - $start) * 1000;
}, function (float|int $time) {
    echo $time . PHP_EOL;
});
```

**Key Point**:
- Avoid making too many `asyncRequest` calls, as they significantly increase CPU load and can introduce delays when the server is under heavy load.

---

### 3. **Performance Metrics**
Performance tests and measurements are implemented in the code samples found in the **test** folder.

---

### 4. **Plugin Shutdown**
On shutdown, all pending Redis requests are completed and connections are closed. Detailed shutdown logic can be found in the **test** folder:
```php
$this->manager->waitAll();
$this->manager->close();
```

---

## Optimization Suggestions

### 1. **Local Caching**
Reduce Redis requests by caching frequently accessed data:
```php
private array $localCache = [];

public function getCachedFactionMembers(): array {
    if (!isset($this->localCache['faction:members'])) {
        $this->localCache['faction:members'] = $this->manager->syncRequest(GetMememberFactionRequest::class)->getDataTest();
    }
    return $this->localCache['faction:members'];
}
```

### 2. **Batch Requests Using Pipelines (for sMembers)**

If you need to fetch members of multiple factions, a pipeline can be used to execute all `sMembers` requests in a single round-trip to Redis:

#### Without Pipeline (Inefficient)
Each call to `sMembers` happens individually, causing multiple network round-trips:
```php
$results = [];
foreach ($factions as $factionId) {
    $results[$factionId] = $this->manager->getClient()->sMembers("faction:{$factionId}:members");
}
```

#### With Pipeline (Optimized)
Using a pipeline to fetch members of all factions in one round-trip:
```php
$pipeline = $this->manager->getClient()->multi(Redis::PIPELINE);

// Add all sMembers commands to the pipeline
foreach ($factions as $factionId) {
    $pipeline->sMembers("faction:{$factionId}:members");
}

// Execute the pipeline
$results = $pipeline->exec();

// Map the results back to faction IDs
$factionMembers = [];
foreach ($factions as $index => $factionId) {
    $factionMembers[$factionId] = $results[$index];
}
```

**Key Advantages**:
- All `sMembers` commands are sent together, minimizing latency.
- The results are returned in the same order as the commands, making it easy to correlate results with faction IDs.

### 3. **Throttling Asynchronous Requests**
Limit asynchronous requests using throttling logic:
```php
private int $lastAsyncRequestTime = 0;

public function throttleAsyncRequest(callable $callback): void {
    $currentTime = microtime(true);
    if (($currentTime - $this->lastAsyncRequestTime) > 0.05) { // 50 ms delay
        $this->lastAsyncRequestTime = $currentTime;
        $callback();
    }
}
```

---

## Conclusion

The repository’s **test** folder contains examples that demonstrate handling Redis interactions effectively under the specified objectives. By using pipelines with `sMembers`, caching, and throttling strategies, you can ensure stable 20 TPS performance even under heavy player loads (135-180 players).