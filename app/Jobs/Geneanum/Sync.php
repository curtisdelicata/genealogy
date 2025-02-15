<?php

namespace App\Jobs\Geneanum;

use App\Models\Geneanum;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

abstract class Sync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const MODEL = Geneanum::class;
    protected const DATABASE = null;
    protected const MAX_RETRY = 3;
    protected const AREA = null;
    protected const URL = null;

    public static $is_testing = true; //change true if want test sync

    protected $current_page;
    protected $retry;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $page = 1, int $retry = 0)
    {
        $this->current_page = $page;
        $this->retry = $retry;
    }

    /**
     * Execute the job.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $url = sprintf(static::URL, time() - random_int(1, 30), $this->current_page);

        $response = Http::get($url);

        if ($response->failed()) {
            if (self::$is_testing) {
                $response->throw();
            }
            $this->retry++;
            if ($this->retry > static::MAX_RETRY) {
                return;
            }

            dispatch(new static($this->current_page, $this->retry))
                ->delay(random_int(10, 60));
        }

        $result = $response->json();

        if (! self::$is_testing) {
            $has_more_result = $result['total'] > $result['page'];
            if ($has_more_result) {
                $this->current_page++;
                dispatch(new static($this->current_page))
                    ->delay(now()->addSeconds(random_int(10, 60)));
            }
        }

        $rows = $result['rows'];

        foreach ($rows as $row) {
            $remote_id = $row['id'];

            static::MODEL::updateOrCreate(
                [
                    'remote_id' => $remote_id,
                    'area' => static::AREA,
                    'db_name' => static::DATABASE,
                ],
                [
                    'data' => $this->getFields($row),
                ]
            );
        }
    }

    /**
     * get the fields to store.
     *
     * @return mixed
     */
    abstract protected function getFields(array $row): array;
}
