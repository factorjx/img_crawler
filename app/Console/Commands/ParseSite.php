<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Crawler;

class ParseSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawl:site {domain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
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
        $domain = $this->argument('domain');
        $crawler = new Crawler($domain);
        // dd();
        $crawler->traverse();
    }
}
