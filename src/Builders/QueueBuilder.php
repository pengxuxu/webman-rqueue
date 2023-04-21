<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders;

use RedisException;
use Workbunny\WebmanRqueue\Header;
use Workerman\Timer;
use Workerman\Worker;

class QueueBuilder extends AbstractBuilder
{
    /**
     * 队列配置
     *
     * @var array = [
     *  'name'           => 'example',
     *  'delayed'        => false,
     *  'prefetch_count' => 1,
     *  'prefetch_size'  => 0,
     *  'is_global'      => false,
     *  'routing_key'    => '',
     * ]
     */
    protected array $_queueConfig = [];

    /** @var string|null 分组名称 */
    protected ?string $_groupName = null;

    /** @var float|null 消费间隔 0.001s */
    protected ?float $_timerInterval = 0.001;

    /**
     * @var int|null
     */
    private ?int $_mainTimer = null;

    public function __construct()
    {
        parent::__construct();
        $name = str_replace('\\', '.', get_called_class());

        $this->getBuilderConfig()->setConsumerTag($this->_exchangeName ?? $name);
        $this->getBuilderConfig()->setExchange($this->_exchangeName ?? $name);
        $this->getBuilderConfig()->setExchangeType($this->_exchangeType);

        $this->getBuilderConfig()->setQueue($this->_queueConfig['name'] ?? $name);
        $this->getBuilderConfig()->setRoutingKey($this->_queueConfig['routing_key'] ?? '');
        $this->getBuilderConfig()->setPrefetchCount($this->_queueConfig['prefetch_count'] ?? 0);
        $this->getBuilderConfig()->setPrefetchSize($this->_queueConfig['prefetch_size'] ?? 0);
        $this->getBuilderConfig()->setGlobal($this->_queueConfig['is_global'] ?? false);
        $this->getBuilderConfig()->setCallback([$this, 'handler']);
        if($config['delayed'] ?? false){
            $this->getBuilderConfig()->setArguments([
                'x-delayed-type' => $this->getBuilderConfig()->getExchangeType()
            ]);
            $this->getBuilderConfig()->setExchangeType(Constants::DELAYED);
        }
    }

    /** @inheritDoc */
    public function onWorkerStart(Worker $worker): void
    {
        if($this->getConnection()){
            $this->_mainTimer = Timer::add($this->_timerInterval, function () use($worker) {
                $client = $this->getConnection()->client();
                // create group
                $client->xGroup(
                    'CREATE', $this->getBuilderConfig()->getQueue(),
                    $group = $this->getBuilderConfig()->getGroup(),
                    '0', true
                );
                // group read
                if($res = $client->xReadGroup(
                    $group, $group . "-$worker->id", [$this->getBuilderConfig()->getQueue() => '>'],
                    $this->getBuilderConfig()->getPrefetchCount(), $this->_timerInterval < 0.001 ? null : (int)($this->_timerInterval * 1000)
                )){
                    // queues
                    foreach ($res as $queue => $message) {
                        $ids = [];
                        // messages
                        foreach ($message as $id => $value){
                            // drop
                            if(!isset($value['_header']) or !isset($value['_body'])) {
                                $client->xAck($queue, $group, [$ids[] = $id]);
                                continue;
                            }
                            // delay message
                            $header = new Header($value['_header']);
                            if(
                                $this->getBuilderConfig()->isDelayed() and
                                $header->_delay > 0 and
                                (($header->_delay / 1000 + $header->_timestamp) - microtime(true)) > 0
                            ){
                                // republish
                                $client->xAdd($queue, '*', $header->toArray());
                                $client->xAck($queue, $group, [$ids[] = $id]);
                                continue;
                            }
                            try {
                                // handler
                                if(!\call_user_func($this->getBuilderConfig()->getCallback(), $id, $value, $this->getConnection())) {
                                    // false to republish
                                    $header->_count = $header->_count + 1;
                                    $client->xAdd($queue, '*', $header->toArray());
                                }
                                $client->xAck($queue, $group, [$id]);
                                $ids[] = $id;
                            }catch (\Throwable $throwable) {
                                $header->_count = $header->_count + 1;
                                $header->_error = $throwable->getMessage();
                                $client->xAdd($queue, '*', $header->toArray());
                                $client->xAck($queue, $group, [$ids[] = $id]);
                            }
                        }
                        // 删除ack的消息
                        Timer::add($this->_timerInterval, function() use ($client, $queue, $ids){
                            $client->xDel($queue,$ids);
                        }, [], false);
                    }
                }
            });
        }
    }

    /** @inheritDoc */
    public function onWorkerStop(Worker $worker): void
    {
        if($this->getConnection()) {
            try {
                $this->getConnection()->client()->close();
            }catch (RedisException $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
        if($this->_mainTimer) {
            Timer::del($this->_mainTimer);
        }
    }

    /** @inheritDoc */
    public function onWorkerReload(Worker $worker): void
    {}

    /**
     * @param string $queue
     * @param string $group
     * @param string $id
     * @param Header|null $header
     * @return void
     * @throws RedisException
     */
    public function ack(string $queue, string $group, string $id, ?Header $header = null): void
    {
        $client = $this->getConnection()->client();
        if($header) {
            $header->_count = $header->_count + 1;
            $client->xAdd($queue, '*', $header->toArray());
        }
        $client->xAck($queue, $group, [$id]);
    }

    /** @inheritDoc */
    public static function classContent(string $namespace, string $className, bool $isDelay): string
    {
        $isDelay = $isDelay ? 'true' : 'false';
        return <<<doc
<?php declare(strict_types=1);

namespace $namespace;

use Bunny\Channel as BunnyChannel;
use Bunny\Async\Client as BunnyClient;
use Bunny\Message as BunnyMessage;
use Workbunny\WebmanRabbitMQ\Constants;
use Workbunny\WebmanRabbitMQ\Builders\QueueBuilder;

class $className extends QueueBuilder
{
    /**
     * @var array = [
     *   'name'           => 'example',
     *   'delayed'        => false,
     *   'prefetch_count' => 1,
     *   'prefetch_size'  => 0,
     *   'is_global'      => false,
     *   'routing_key'    => '',
     * ]
     */
    protected array \$_queueConfigs = [
        'name'           => 'example',          // TODO 队列名称 ，默认由类名自动生成
        'delayed'        => $isDelay,           // TODO 是否延迟
        'prefetch_count' => 0,                  // TODO QOS 数量
        'prefetch_size'  => 0,                  // TODO QOS size 
        'is_global'      => false,              // TODO QOS 全局
        'routing_key'    => '',                 // TODO 路由键
    ];
    
    /** @var string 交换机类型 */
    protected string \$_exchangeType = Constants::DIRECT; // TODO 交换机类型
    
    /** @var string|null 交换机名称 */
    protected ?string \$_exchangeName = null; // TODO 交换机名称，默认由类名自动生成
    
    /**
     * 【请勿移除该方法】
     * @param BunnyMessage \$message
     * @param BunnyChannel \$channel
     * @param BunnyClient \$client
     * @return string
     */
    public function handler(BunnyMessage \$message, BunnyChannel \$channel, BunnyClient \$client): string 
    {
        // TODO 请重写消费逻辑
        echo "请重写 $className::handler\\n";
        return Constants::ACK;
    }
}
doc;
    }
}