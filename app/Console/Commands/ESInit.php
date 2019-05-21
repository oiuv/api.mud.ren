<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class ESInit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'es:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '初始化es';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $client = new Client();
        //创建模板
        $url = config('scout.elasticsearch.hosts')[0].'/_template/tmp';
        $param = [
            'json' => [
                'template' => config('scout.elasticsearch.index'),
                'mappings' => [
                    'dynamic_templates' => [
                        [
                            'strings' => [
                                'match_mapping_type' => 'string',
                                'mapping' => [
                                    'type' => 'text',
                                    'analyzer' => 'ik_max_word',
                                    'fields' => [
                                        'raw'=> [
                                            'type' => 'keyword',
                                            'ignore_above' => 256,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $client->delete($url);
        $client->put($url, $param);

        $this->info('========= create template success ========');

        //创建索引
        $url = config('scout.elasticsearch.hosts')[0].'/'.config('scout.elasticsearch.index');
        $param = [
            'json' => [
                'settings' => [
                    'index' =>[
                        'refresh_interval' => '5s',
                        'number_of_shards' => 1,
                        'number_of_replicas' => 0,
                    ],
                ],
            ],
        ];
        // $client->delete($url);
        $client->put($url, $param);

        $this->info('=========== create index success ==========');
    }
}
