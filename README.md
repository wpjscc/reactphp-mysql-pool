# reactphp-mysql-pool


## install

```
composer require wpjscc/reactphp-mysql-pool -vvv
```


## example
```
require_once './vendor/autoload.php';

use Wpjscc\MySQL\Pool;
use React\MySQL\QueryResult;
use React\EventLoop\Loop;

$pool = new Pool('username:password@host/databasename?timeout=5', [
    'max_connections' => 10, // 10 connection
    'max_wait_queue' => 110, // how many sql in queue
    'wait_timeout' => 5,// wait time include response time
]);

qeury($pool);
queryStream($pool);

function qeury($pool) {
    for ($i=0; $i < 90; $i++) { 
        $pool->query('select * from blog')->then(function (QueryResult $command) use ($i) {
            echo "query:$i\n";
            if (isset($command->resultRows)) {
                // this is a response to a SELECT etc. with some rows (0+)
                // print_r($command->resultFields);
                // print_r($command->resultRows);
                echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
            } else {
                // this is an OK message in response to an UPDATE etc.
                if ($command->insertId !== 0) {
                    var_dump('last insert ID', $command->insertId);
                }
                echo 'Query OK, ' . $command->affectedRows . ' row(s) affected' . PHP_EOL;
            }
        }, function (\Exception $error) {
            // the query was not executed successfully
            echo 'Error: ' . $error->getMessage() . PHP_EOL;
        });
        
    }
}

function queryStream($pool){
    for ($i=0; $i < 90; $i++) { 
        (function($pool,$i){
            $stream = $pool->queryStream('select * from blog');
           
            $stream->on('data', function ($data) use ($i) {
                // echo "queryStream:$i\n";
                // print_r($data);
            });
            $stream->on('error', function ($err) {
                echo 'Error: ' . $err->getMessage() . PHP_EOL;
            });
            $stream->on('end', function () use ($i) {
                echo 'Completed.'.$i . PHP_EOL;
            });
            
           
        })($pool, $i);
        
    }
}


Loop::addPeriodicTimer(5, function() use ($pool) {
    // qeury($pool);
    // queryStream($pool);
});


$pool->translation(function($connection){
    $connection->query("INSERT INTO blog_test (content) VALUES ('hello world success')");
    // throw new Exception("Error Processing Request", 1);
})->then(function($result){
    var_dump($result);
}, function($error){
    var_dump($error->getMessage());
});


```
