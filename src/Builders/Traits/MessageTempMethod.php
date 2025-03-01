<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue\Builders\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HigherOrderWhenProxy;
use support\Db;
use Workerman\Timer;
use function Workbunny\WebmanRqueue\config;

trait MessageTempMethod
{
    protected ?int $_requeueTimer = null;

    protected static array $_tables = [
        'requeue', 'pending'
    ];

    /**
     * 初始化temp
     *
     * @return void
     */
    public function tempInit(): void
    {
        if (config('database.plugin.workbunny.webman-rqueue.local-storage')) {
            $builder = Schema::connection('plugin.workbunny.webman-rqueue.local-storage');
            foreach (self::$_tables as $table) {
                if (!$builder->hasTable($table)) {
                    $builder->create($table, function (Blueprint $table) {
                        $table->id();
                        $table->string('queue');
                        $table->json('data');
                        $table->integer('create_at');
                    });
                    echo "local-storage db $table-table created. " . PHP_EOL;
                }
            }
        }
    }

    /**
     * 插入temp数据
     *
     * @param string $table
     * @param string $queue
     * @param array $value
     * @return int
     */
    public function tempInsert(string $table, string $queue, array $value): int
    {
        if (config('database.plugin.workbunny.webman-rqueue.local-storage')) {
            if (in_array($table, self::$_tables)) {
                // 数据储存至文件
                return Db::connection('plugin.workbunny.webman-rqueue.local-storage')
                    ->table($table)->insertGetId([
                        'queue'      => $queue,
                        'data'       => json_encode($value, JSON_UNESCAPED_UNICODE),
                        'created_at' => time()
                    ]);
            }
        }
        return 0;
    }

    /**
     * 查询temp
     *
     * @param string $table
     * @param array|null $where
     * @param array $columns
     * @return QueryBuilder|HigherOrderWhenProxy|null
     */
    public function tempSelect(string $table, ?array $where = null, array $columns = ['*']): QueryBuilder|HigherOrderWhenProxy|null
    {
        if (config('database.plugin.workbunny.webman-rqueue.local-storage')) {
            if (in_array($table, self::$_tables)) {
                // 数据储存至文件
                return Db::connection('plugin.workbunny.webman-rqueue.local-storage')
                    ->table($table)->when($where, function (Builder $query, $where) {
                        $query->where($where);
                    })->select($columns);
            }
        }
        return null;
    }

    /**
     * temp requeue定时器初始化
     *
     * @return void
     */
    public function tempRequeueInit(): void
    {
        if (config('database.plugin.workbunny.webman-rqueue.local-storage')) {
            // 设置消息重载定时器
            if (($interval = config('plugin.workbunny.webman-rqueue.app.requeue_interval', 0)) > 0) {
                $this->_requeueTimer = Timer::add(
                    $interval,
                    function () {
                        $connection = Db::connection('plugin.workbunny.webman-rqueue.local-storage');
                        $this->tempSelect('requeue')->chunkById(
                            500,
                            function (Collection $collection) use ($connection) {
                                $client = $this->getConnection()->client();
                                foreach ($collection as $item) {
                                    if ($client->xAdd($item->queue,'*', json_decode($item->data, true))) {
                                        $connection->table('pending')->delete($item->id);
                                    }
                                }
                            });
                    });
            }
        }
    }
}